<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\DocumentSignatureRequest;
use App\Models\OrderDocument;
use App\Models\User;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Signatures\DocumentSignatureWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DocumentSignatureController extends BaseApiController
{
    public function __construct(
        private readonly DocumentSignatureWorkflowService $workflow,
        private readonly OrderDocumentCenterService $documentCenter
    ) {
    }

    public function signers(Request $request): JsonResponse
    {
        $this->authorize('os:editar');
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        $users = User::query()
            ->withExists(['activeSignature as assinatura_cadastrada'])
            ->where('ativo', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'email'])
            ->map(static fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->nome,
                'email' => (string) $user->email,
                'signature_registered' => (bool) $user->assinatura_cadastrada,
            ])
            ->values()
            ->all();

        return $this->success(['users' => $users], request: $request);
    }

    public function pending(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! (bool) $actor->ativo) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        return $this->success([
            'requests' => $this->workflow->pendingFor($actor),
        ], request: $request);
    }

    public function sign(Request $request, int $signatureRequest): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! (bool) $actor->ativo) {
            return $this->error('Usuário não autenticado.', 401, 'AUTH_REQUIRED', request: $request);
        }

        $validated = $request->validate([
            'signature_email' => ['nullable', 'email:rfc', 'max:160'],
            'signature_password' => ['nullable', 'string', 'max:200'],
        ]);

        $pending = DocumentSignatureRequest::query()
            ->with(['order', 'requester', 'responsibleUser'])
            ->find($signatureRequest);
        if (! $pending instanceof DocumentSignatureRequest) {
            return $this->error('Solicitação de assinatura não encontrada.', 404, 'SIGNATURE_REQUEST_NOT_FOUND', request: $request);
        }

        $lock = Cache::lock('staff-document-signature:' . $pending->id, 60);
        if (! $lock->get()) {
            return $this->error(
                'Esta solicitação de assinatura já está sendo processada.',
                409,
                'SIGNATURE_REQUEST_IN_PROGRESS',
                request: $request
            );
        }

        try {
            $pending->refresh();

            return $this->processSignature($request, $pending, $actor, $validated);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array{signature_email?: string|null, signature_password?: string|null} $validated
     */
    private function processSignature(
        Request $request,
        DocumentSignatureRequest $pending,
        User $actor,
        array $validated
    ): JsonResponse {
        try {
            $signer = $this->workflow->assertCanSign($pending, $actor, $validated, $request);
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 422, 'SIGNATURE_REQUEST_INVALID', request: $request);
        }

        if ($pending->order === null || ! hash_equals(
            (string) $pending->snapshot_os_hash,
            $this->workflow->orderSnapshotHash($pending->order)
        )) {
            return $this->error(
                'A OS foi alterada após a solicitação. Cancele esta pendência e gere uma nova para evitar assinar dados desatualizados.',
                409,
                'SIGNATURE_REQUEST_STALE',
                request: $request
            );
        }

        $generationActor = $pending->requester instanceof User && (bool) $pending->requester->ativo
            ? $pending->requester
            : $actor;
        $method = (int) $actor->id === (int) $signer->id ? 'pendencia_sessao' : 'pendencia_reautenticada';
        $signedAt = now();
        $result = $this->documentCenter->generate(
            (int) $pending->os_id,
            $generationActor,
            [(string) $pending->tipo_documento],
            [
                'signature_signer' => $signer,
                'signature_method' => $method,
                'signature_signed_at' => $signedAt,
            ]
        );
        $generated = collect($result['documents'] ?? [])->first(static fn ($item): bool => (bool) ($item['ok'] ?? false));
        $document = is_array($generated)
            ? OrderDocument::query()->find((int) ($generated['document_id'] ?? 0))
            : null;
        if (! $document instanceof OrderDocument) {
            return $this->error(
                (string) ($generated['message'] ?? 'Não foi possível emitir o documento assinado.'),
                422,
                'SIGNED_DOCUMENT_GENERATION_FAILED',
                request: $request
            );
        }

        try {
            $this->workflow->complete(
                $pending,
                $document,
                $signer,
                $method,
                (string) $request->ip(),
                (string) $request->userAgent()
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error($exception->getMessage(), 409, 'SIGNATURE_REQUEST_ALREADY_PROCESSED', request: $request);
        }

        logger()->notice('[SIGNATURE] Documento pendente assinado', [
            'request_id' => (int) $pending->id,
            'document_id' => (int) $document->id,
            'creator_id' => (int) $generationActor->id,
            'session_user_id' => (int) $actor->id,
            'signer_id' => (int) $signer->id,
            'method' => $method,
        ]);

        return $this->success([
            'request_id' => (int) $pending->id,
            'document_id' => (int) $document->id,
            'order_id' => (int) $pending->os_id,
            'signed_by' => ['id' => (int) $signer->id, 'name' => (string) $signer->nome],
        ], request: $request);
    }
}
