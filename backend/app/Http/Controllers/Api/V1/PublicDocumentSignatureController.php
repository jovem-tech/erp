<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OrderDocument;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\Orders\OrderDocumentCenterService;
use App\Services\Signatures\DocumentSignatureWorkflowService;
use App\Services\Signatures\SignatureImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PublicDocumentSignatureController extends BaseApiController
{
    public function __construct(
        private readonly DocumentSignatureWorkflowService $workflow,
        private readonly SignatureImageService $images,
        private readonly OrderDocumentCenterService $documentCenter
    ) {
    }

    public function show(Request $request, string $token): JsonResponse
    {
        $pending = $this->workflow->resolvePublic($token);
        if ($pending === null) {
            return $this->error('Link de assinatura inválido.', 404, 'PUBLIC_SIGNATURE_NOT_FOUND', request: $request);
        }
        if ((string) $pending->status !== 'pendente') {
            return $this->error('Este documento já foi assinado ou o link foi cancelado.', 410, 'PUBLIC_SIGNATURE_UNAVAILABLE', request: $request);
        }
        if ($pending->expira_em !== null && $pending->expira_em->isPast()) {
            return $this->error('Este link de assinatura expirou.', 410, 'PUBLIC_SIGNATURE_EXPIRED', request: $request);
        }

        return $this->success([
            'request_id' => (int) $pending->id,
            'order_number' => (string) ($pending->order?->numero_os ?? ('#' . $pending->os_id)),
            'document_type' => (string) $pending->tipo_documento,
            'client_name' => (string) ($pending->order?->client?->nome_razao ?? 'Cliente'),
            'company_user' => (string) ($pending->requester?->nome ?? ''),
            'expires_at' => $pending->expira_em?->toIso8601String(),
            'consent_version' => (string) ($pending->consentimento_versao ?? 'assinatura-cliente-v1'),
        ], request: $request);
    }

    public function store(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'signature_data' => ['required', 'string', 'max:3000000'],
            'consent' => ['accepted'],
        ]);

        $pending = $this->workflow->resolvePublic($token);
        if ($pending === null) {
            return $this->error('Link de assinatura inválido.', 404, 'PUBLIC_SIGNATURE_NOT_FOUND', request: $request);
        }

        $lock = Cache::lock('public-document-signature:' . (int) $pending->id, 60);
        if (! $lock->get()) {
            return $this->error('Esta assinatura já está sendo processada.', 409, 'PUBLIC_SIGNATURE_BUSY', request: $request);
        }

        $drawing = null;
        try {
            $pending->refresh()->load(['order.client', 'requester', 'requesterSignature']);
            if ((string) $pending->status !== 'pendente') {
                return $this->error('Este documento já foi assinado ou o link foi cancelado.', 410, 'PUBLIC_SIGNATURE_UNAVAILABLE', request: $request);
            }
            if ($pending->expira_em !== null && $pending->expira_em->isPast()) {
                return $this->error('Este link de assinatura expirou.', 410, 'PUBLIC_SIGNATURE_EXPIRED', request: $request);
            }
            if ($pending->order === null || ! hash_equals(
                (string) $pending->snapshot_os_hash,
                $this->workflow->orderSnapshotHash($pending->order)
            )) {
                return $this->error('A OS foi alterada. Solicite um novo link antes de assinar.', 409, 'PUBLIC_SIGNATURE_STALE', request: $request);
            }

            $creator = $pending->requester;
            $creatorSignature = $pending->requesterSignature;
            if (! $creator instanceof User || ! $creatorSignature instanceof UserSignature) {
                return $this->error('O responsável interno não possui assinatura válida.', 422, 'PUBLIC_SIGNATURE_CREATOR_INVALID', request: $request);
            }

            $signedAt = now();
            $drawing = $this->images->storeCustomerDrawing((string) $validated['signature_data'], (int) $pending->id);
            $result = $this->documentCenter->generate(
                (int) $pending->os_id,
                $creator,
                [(string) $pending->tipo_documento],
                [
                    'signature_signer' => $creator,
                    'responsible_signature' => $creatorSignature,
                    'responsible_signed_at' => $pending->created_at,
                    'signature_method' => 'cliente_link',
                    'customer_signature' => [
                        'data_uri' => $drawing['data_uri'],
                        'hash_sha256' => $drawing['hash_sha256'],
                        'name' => trim((string) $validated['name']),
                        'signed_at' => $signedAt,
                    ],
                ]
            );
            $generated = collect($result['documents'] ?? [])->first(static fn ($item): bool => (bool) ($item['ok'] ?? false));
            $document = is_array($generated)
                ? OrderDocument::query()->find((int) ($generated['document_id'] ?? 0))
                : null;
            if (! $document instanceof OrderDocument) {
                Storage::disk('local')->delete((string) $drawing['path']);
                return $this->error('Não foi possível emitir o documento assinado.', 422, 'PUBLIC_SIGNATURE_GENERATION_FAILED', request: $request);
            }

            $this->workflow->completeCustomer(
                $pending,
                $document,
                $drawing,
                trim((string) $validated['name']),
                (string) $request->ip(),
                (string) $request->userAgent()
            );

            logger()->notice('[SIGNATURE] Cliente assinou documento por link', [
                'request_id' => (int) $pending->id,
                'document_id' => (int) $document->id,
                'order_id' => (int) $pending->os_id,
                'signature_hash' => (string) $drawing['hash_sha256'],
            ]);

            return $this->success([
                'signed' => true,
                'document_id' => (int) $document->id,
                'order_number' => (string) ($pending->order?->numero_os ?? ''),
            ], request: $request);
        } catch (\InvalidArgumentException $exception) {
            if (is_array($drawing)) {
                Storage::disk('local')->delete((string) ($drawing['path'] ?? ''));
            }
            return $this->error($exception->getMessage(), 422, 'PUBLIC_SIGNATURE_INVALID', request: $request);
        } catch (\Throwable $exception) {
            if (is_array($drawing)) {
                Storage::disk('local')->delete((string) ($drawing['path'] ?? ''));
            }
            report($exception);

            return $this->error(
                'Não foi possível concluir a assinatura agora.',
                500,
                'PUBLIC_SIGNATURE_FAILED',
                request: $request
            );
        } finally {
            $lock->release();
        }
    }
}
