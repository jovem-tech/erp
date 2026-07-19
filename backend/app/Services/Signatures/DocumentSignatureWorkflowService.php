<?php

namespace App\Services\Signatures;

use App\Models\DocumentSignatureRequest;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\User;
use App\Models\UserSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DocumentSignatureWorkflowService
{
    public function __construct(
        private readonly SignatureImageService $signatureImageService
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{mode: string, signer?: User, signature?: UserSignature, method?: string}
     */
    public function resolveImmediateSigner(User $actor, array $payload, Request $request): array
    {
        $mode = strtolower(trim((string) ($payload['signature_mode'] ?? 'self')));
        if (in_array($mode, ['pending', 'client'], true)) {
            return ['mode' => $mode];
        }

        if ($mode === 'self') {
            $signature = $this->requireActiveSignature($actor);

            return ['mode' => 'self', 'signer' => $actor, 'signature' => $signature, 'method' => 'sessao'];
        }

        if ($mode !== 'reauth') {
            throw new InvalidArgumentException('A forma de assinatura informada é inválida.');
        }

        $target = User::query()
            ->whereKey(max(0, (int) ($payload['signature_user_id'] ?? 0)))
            ->where('ativo', true)
            ->first();
        if (! $target instanceof User) {
            throw new InvalidArgumentException('Selecione um usuário ativo para assinar.');
        }

        $this->verifyCredentials(
            $actor,
            $target,
            (string) ($payload['signature_email'] ?? ''),
            (string) ($payload['signature_password'] ?? ''),
            $request
        );

        return [
            'mode' => 'reauth',
            'signer' => $target,
            'signature' => $this->requireActiveSignature($target),
            'method' => 'reautenticacao',
        ];
    }

    /**
     * @param array<int, string> $types
     * @return array<int, DocumentSignatureRequest>
     */
    public function createPending(Order $order, User $actor, User $responsible, array $types): array
    {
        $this->requireActiveSignature($responsible);
        $snapshotHash = $this->orderSnapshotHash($order);

        return DB::transaction(function () use ($order, $actor, $responsible, $types, $snapshotHash): array {
            $requests = [];
            foreach (array_values(array_unique($types)) as $type) {
                $requests[] = DocumentSignatureRequest::query()->create([
                    'os_id' => (int) $order->id,
                    'tipo_documento' => (string) $type,
                    'tipo_signatario' => 'usuario',
                    'papel' => 'responsavel',
                    'status' => 'pendente',
                    'solicitada_por' => (int) $actor->id,
                    'usuario_responsavel_id' => (int) $responsible->id,
                    'snapshot_os_hash' => $snapshotHash,
                    'expira_em' => now()->addDays(30),
                ]);
            }

            return $requests;
        }, 3);
    }

    /**
     * @param array<int, string> $types
     * @return array<int, array{request: DocumentSignatureRequest, token: string}>
     */
    public function createClientPending(Order $order, User $actor, array $types): array
    {
        $creatorSignature = $this->requireActiveSignature($actor);
        $snapshotHash = $this->orderSnapshotHash($order);

        return DB::transaction(function () use ($order, $actor, $types, $creatorSignature, $snapshotHash): array {
            $requests = [];
            foreach (array_values(array_unique($types)) as $type) {
                $token = Str::random(64);
                $signatureRequest = DocumentSignatureRequest::query()->create([
                    'os_id' => (int) $order->id,
                    'tipo_documento' => (string) $type,
                    'tipo_signatario' => 'cliente',
                    'papel' => 'cliente',
                    'status' => 'pendente',
                    'solicitada_por' => (int) $actor->id,
                    'assinatura_solicitante_id' => (int) $creatorSignature->id,
                    'token_hash' => hash('sha256', $token),
                    'snapshot_os_hash' => $snapshotHash,
                    'expira_em' => now()->addDays(7),
                    'consentimento_versao' => 'assinatura-cliente-v1',
                ]);
                $requests[] = ['request' => $signatureRequest, 'token' => $token];
            }

            return $requests;
        }, 3);
    }

    public function resolvePublic(string $token): ?DocumentSignatureRequest
    {
        if (strlen($token) !== 64) {
            return null;
        }

        return DocumentSignatureRequest::query()
            ->with(['order.client', 'requester', 'order'])
            ->where('token_hash', hash('sha256', $token))
            ->where('tipo_signatario', 'cliente')
            ->first();
    }

    /** @param array{path: string, hash_sha256: string} $drawing */
    public function completeCustomer(
        DocumentSignatureRequest $request,
        OrderDocument $document,
        array $drawing,
        string $name,
        ?string $ip,
        ?string $userAgent
    ): void {
        DB::transaction(function () use ($request, $document, $drawing, $name, $ip, $userAgent): void {
            $locked = DocumentSignatureRequest::query()->lockForUpdate()->findOrFail((int) $request->id);
            if ((string) $locked->status !== 'pendente') {
                throw new InvalidArgumentException('Este documento já foi assinado ou o link foi cancelado.');
            }

            $combinedHash = hash('sha256', (string) $document->hash_sha256 . '|' . (string) $drawing['hash_sha256']);
            $document->forceFill([
                'assinado_por' => (int) $request->solicitada_por,
                'assinatura_hash' => $combinedHash,
                'assinado_em' => now(),
                'metodo_assinatura' => 'cliente_link',
            ])->save();

            $locked->forceFill([
                'status' => 'assinada',
                'documento_id' => (int) $document->id,
                'assinatura_hash' => (string) $drawing['hash_sha256'],
                'assinatura_arquivo' => (string) $drawing['path'],
                'signatario_nome' => $name,
                'metodo_assinatura' => 'cliente_canvas',
                'ip_hash' => $this->fingerprint($ip),
                'user_agent_hash' => $this->fingerprint($userAgent),
                'assinada_em' => now(),
                'token_hash' => null,
            ])->save();
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function pendingFor(User $user): array
    {
        return DocumentSignatureRequest::query()
            ->with(['order:id,numero_os,updated_at', 'requester:id,nome', 'responsibleUser:id,nome,email'])
            ->where(static function ($query) use ($user): void {
                $query->where('usuario_responsavel_id', (int) $user->id)
                    ->orWhere('solicitada_por', (int) $user->id);
            })
            ->where('status', 'pendente')
            ->where(static function ($query): void {
                $query->whereNull('expira_em')->orWhere('expira_em', '>', now());
            })
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(static fn (DocumentSignatureRequest $item): array => [
                'id' => (int) $item->id,
                'order_id' => (int) $item->os_id,
                'order_number' => (string) ($item->order?->numero_os ?? ('#' . $item->os_id)),
                'document_type' => (string) $item->tipo_documento,
                'requested_by' => (string) ($item->requester?->nome ?? ''),
                'responsible_user_id' => (int) ($item->responsibleUser?->id ?? 0),
                'responsible_user' => (string) ($item->responsibleUser?->nome ?? ''),
                'responsible_email' => (string) ($item->responsibleUser?->email ?? ''),
                'requested_at' => $item->created_at?->toIso8601String(),
                'expires_at' => $item->expira_em?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function assertCanSign(DocumentSignatureRequest $signatureRequest, User $sessionUser, array $payload, Request $request): User
    {
        if ((string) $signatureRequest->status !== 'pendente') {
            throw new InvalidArgumentException('Esta solicitação não está mais pendente.');
        }
        if ($signatureRequest->expira_em !== null && $signatureRequest->expira_em->isPast()) {
            throw new InvalidArgumentException('Esta solicitação de assinatura expirou.');
        }

        $target = $signatureRequest->responsibleUser()->where('ativo', true)->first();
        if (! $target instanceof User) {
            throw new InvalidArgumentException('O usuário responsável não está disponível.');
        }

        if ((int) $sessionUser->id !== (int) $target->id) {
            $this->verifyCredentials(
                $sessionUser,
                $target,
                (string) ($payload['signature_email'] ?? ''),
                (string) ($payload['signature_password'] ?? ''),
                $request
            );
        }

        $this->requireActiveSignature($target);

        return $target;
    }

    public function complete(
        DocumentSignatureRequest $request,
        OrderDocument $document,
        User $signer,
        string $method,
        ?string $ip,
        ?string $userAgent
    ): void {
        $signature = $this->requireActiveSignature($signer);

        DB::transaction(function () use ($request, $document, $signer, $signature, $method, $ip, $userAgent): void {
            $locked = DocumentSignatureRequest::query()->lockForUpdate()->findOrFail((int) $request->id);
            if ((string) $locked->status !== 'pendente') {
                throw new InvalidArgumentException('Esta solicitação já foi processada.');
            }

            $document->forceFill([
                'assinado_por' => (int) $signer->id,
                'assinatura_hash' => (string) $signature->hash_sha256,
                'assinado_em' => now(),
                'metodo_assinatura' => $method,
            ])->save();

            $locked->forceFill([
                'status' => 'assinada',
                'documento_id' => (int) $document->id,
                'assinatura_hash' => (string) $signature->hash_sha256,
                'signatario_nome' => (string) $signer->nome,
                'metodo_assinatura' => $method,
                'ip_hash' => $this->fingerprint($ip),
                'user_agent_hash' => $this->fingerprint($userAgent),
                'assinada_em' => now(),
            ])->save();
        }, 3);
    }

    public function recordImmediate(OrderDocument $document, User $signer, UserSignature $signature, string $method): void
    {
        $document->forceFill([
            'assinado_por' => (int) $signer->id,
            'assinatura_hash' => (string) $signature->hash_sha256,
            'assinado_em' => now(),
            'metodo_assinatura' => $method,
        ])->save();
    }

    public function orderSnapshotHash(Order $order): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $order->id,
            'updated_at' => $order->updated_at?->toIso8601String(),
            'status' => (string) ($order->status ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function requireActiveSignature(User $user): UserSignature
    {
        $signature = $this->signatureImageService->activeFor($user);
        if (! $signature instanceof UserSignature) {
            throw new InvalidArgumentException(sprintf(
                '%s ainda não cadastrou uma assinatura no perfil.',
                (string) ($user->nome ?? 'O usuário')
            ));
        }

        return $signature;
    }

    private function verifyCredentials(User $actor, User $target, string $email, string $password, Request $request): void
    {
        $key = 'signature-reauth:' . (int) $actor->id . ':' . (int) $target->id . '|' . (string) $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new InvalidArgumentException('Muitas tentativas de autenticação. Aguarde antes de tentar novamente.');
        }

        $emailMatches = hash_equals(mb_strtolower((string) $target->email), mb_strtolower(trim($email)));
        if (! $emailMatches || $password === '' || ! Hash::check($password, (string) $target->senha)) {
            RateLimiter::hit($key, 300);
            logger()->warning('[SIGNATURE] Reautenticação recusada', [
                'actor_id' => (int) $actor->id,
                'target_id' => (int) $target->id,
                'ip_hash' => $this->fingerprint((string) $request->ip()),
            ]);
            throw new InvalidArgumentException('As credenciais do signatário não conferem.');
        }

        RateLimiter::clear($key);
    }

    private function fingerprint(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : hash_hmac('sha256', $value, (string) config('app.key'));
    }
}
