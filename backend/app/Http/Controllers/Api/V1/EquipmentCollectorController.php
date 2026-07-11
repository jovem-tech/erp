<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\EquipmentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EquipmentCollectorController extends BaseApiController
{
    public function __construct(
        private readonly EquipmentWorkflowService $equipmentWorkflowService
    ) {
    }

    public function storeSnapshot(Request $request): JsonResponse
    {
        // Autorizacao e por pareamento agora (token de uso unico gerado em
        // createCollectorPairing), nao mais um segredo global fixo — ver
        // EquipmentWorkflowService::storeCollectorSnapshot().
        $submissionToken = trim((string) $request->header('X-Collector-Token', ''));

        $payload = $request->validate([
            'pairing_code' => ['required', 'string', 'max:32'],
            'snapshot' => ['required', 'array'],
            'source' => ['nullable', 'string', 'max:120'],
            'agent_version' => ['nullable', 'string', 'max:60'],
            'hostname' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $pairing = $this->equipmentWorkflowService->storeCollectorSnapshot($payload, $submissionToken);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Nao foi possivel registrar o snapshot do coletor.',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'COLLECTOR_SNAPSHOT_REJECTED',
                null,
                request: $request
            );
        }

        return $this->success([
            'pairing' => [
                'code' => (string) $pairing->code,
                'snapshot_received_at' => optional($pairing->snapshot_received_at)?->toDateTimeString(),
                'expires_at' => optional($pairing->expires_at)?->toDateTimeString(),
            ],
        ], Response::HTTP_CREATED, request: $request);
    }
}
