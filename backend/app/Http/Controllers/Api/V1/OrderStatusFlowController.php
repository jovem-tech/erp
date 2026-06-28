<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\OrderStatus;
use App\Models\OrderStatusTransition;
use App\Services\OrderStatusFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OrderStatusFlowController extends BaseApiController
{
    public function __construct(
        private readonly OrderStatusFlowService $orderStatusFlowService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:visualizar');

        $statuses = OrderStatus::query()
            ->orderBy('ordem_fluxo')
            ->get();

        $transitions = OrderStatusTransition::query()->get();

        return $this->success(
            [
                'statuses' => $statuses->map(fn (OrderStatus $status): array => $this->mapStatus($status))->values()->all(),
                'transitions' => $transitions->map(static fn (OrderStatusTransition $transition): array => [
                    'status_origem_id' => (int) $transition->status_origem_id,
                    'status_destino_id' => (int) $transition->status_destino_id,
                    'ativo' => (bool) $transition->ativo,
                ])->values()->all(),
            ],
            request: $request
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:criar');

        $validated = $request->validate([
            'codigo' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:os_status,codigo'],
            'nome' => ['required', 'string', 'max:120'],
            'grupo_macro' => ['required', 'string', 'max:60'],
            'icone' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:30'],
            'ordem_fluxo' => ['nullable', 'integer'],
            'status_final' => ['nullable', 'boolean'],
            'status_pausa' => ['nullable', 'boolean'],
            'gera_evento_crm' => ['nullable', 'boolean'],
            'estado_fluxo_padrao' => ['nullable', 'string', 'max:40'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'codigo' => 'código',
            'nome' => 'nome',
            'grupo_macro' => 'grupo macro',
            'icone' => 'ícone',
            'cor' => 'cor',
            'ordem_fluxo' => 'ordem no fluxo',
            'status_final' => 'status final',
            'status_pausa' => 'status de pausa',
            'gera_evento_crm' => 'gera evento CRM',
            'estado_fluxo_padrao' => 'estado de fluxo padrão',
            'ativo' => 'status',
        ]);

        $payload = [
            'codigo' => trim((string) $validated['codigo']),
            'nome' => trim((string) $validated['nome']),
            'grupo_macro' => trim((string) $validated['grupo_macro']),
            'icone' => $this->normalizeFieldValue($validated['icone'] ?? null),
            'cor' => $this->normalizeFieldValue($validated['cor'] ?? null),
            'ordem_fluxo' => array_key_exists('ordem_fluxo', $validated) && $validated['ordem_fluxo'] !== null
                ? (int) $validated['ordem_fluxo']
                : ((int) OrderStatus::query()->max('ordem_fluxo')) + 10,
            'status_final' => $request->boolean('status_final', false),
            'status_pausa' => $request->boolean('status_pausa', false),
            'gera_evento_crm' => $request->boolean('gera_evento_crm', true),
            'estado_fluxo_padrao' => $this->normalizeFieldValue($validated['estado_fluxo_padrao'] ?? null),
            'ativo' => $request->boolean('ativo', true),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $status = OrderStatus::query()->create($payload);

        return $this->success(
            ['order_status' => $this->mapStatus($status)],
            201,
            request: $request
        );
    }

    public function update(Request $request, int $status): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $statusModel = OrderStatus::query()->find($status);

        if (! $statusModel instanceof OrderStatus) {
            return $this->error(
                'Status de OS não encontrado.',
                404,
                'ORDER_STATUS_NOT_FOUND',
                null,
                request: $request
            );
        }

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:120'],
            'grupo_macro' => ['required', 'string', 'max:60'],
            'icone' => ['nullable', 'string', 'max:60'],
            'cor' => ['nullable', 'string', 'max:30'],
            'ordem_fluxo' => ['nullable', 'integer'],
            'status_final' => ['nullable', 'boolean'],
            'status_pausa' => ['nullable', 'boolean'],
            'gera_evento_crm' => ['nullable', 'boolean'],
            'estado_fluxo_padrao' => ['nullable', 'string', 'max:40'],
            'ativo' => ['nullable', 'boolean'],
        ], [], [
            'nome' => 'nome',
            'grupo_macro' => 'grupo macro',
            'icone' => 'ícone',
            'cor' => 'cor',
            'ordem_fluxo' => 'ordem no fluxo',
            'status_final' => 'status final',
            'status_pausa' => 'status de pausa',
            'gera_evento_crm' => 'gera evento CRM',
            'estado_fluxo_padrao' => 'estado de fluxo padrão',
            'ativo' => 'status',
        ]);

        $payload = [
            'nome' => trim((string) $validated['nome']),
            'grupo_macro' => trim((string) $validated['grupo_macro']),
            'icone' => $this->normalizeFieldValue($validated['icone'] ?? null),
            'cor' => $this->normalizeFieldValue($validated['cor'] ?? null),
            'ordem_fluxo' => array_key_exists('ordem_fluxo', $validated) && $validated['ordem_fluxo'] !== null
                ? (int) $validated['ordem_fluxo']
                : (int) $statusModel->ordem_fluxo,
            'status_final' => $request->boolean('status_final', false),
            'status_pausa' => $request->boolean('status_pausa', false),
            'gera_evento_crm' => $request->boolean('gera_evento_crm', false),
            'estado_fluxo_padrao' => $this->normalizeFieldValue($validated['estado_fluxo_padrao'] ?? null),
            'ativo' => $request->boolean('ativo', false),
            'updated_at' => now(),
        ];

        $statusModel->forceFill($payload)->save();

        return $this->success(
            ['order_status' => $this->mapStatus($statusModel->fresh() ?? $statusModel)],
            request: $request
        );
    }

    public function updateTransitions(Request $request): JsonResponse
    {
        $this->authorize('conhecimento:editar');

        $validated = $request->validate([
            'transitions' => ['nullable', 'array'],
            'transitions.*' => ['array'],
            'transitions.*.*' => ['integer'],
        ], [], [
            'transitions' => 'matriz de transições',
        ]);

        $this->orderStatusFlowService->syncTransitions($validated['transitions'] ?? []);

        $statuses = OrderStatus::query()
            ->orderBy('ordem_fluxo')
            ->get();

        $transitions = OrderStatusTransition::query()->get();

        return $this->success(
            [
                'statuses' => $statuses->map(fn (OrderStatus $status): array => $this->mapStatus($status))->values()->all(),
                'transitions' => $transitions->map(static fn (OrderStatusTransition $transition): array => [
                    'status_origem_id' => (int) $transition->status_origem_id,
                    'status_destino_id' => (int) $transition->status_destino_id,
                    'ativo' => (bool) $transition->ativo,
                ])->values()->all(),
            ],
            request: $request
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapStatus(OrderStatus $status): array
    {
        return [
            'id' => (int) $status->id,
            'codigo' => (string) ($status->codigo ?? ''),
            'nome' => (string) ($status->nome ?? ''),
            'grupo_macro' => (string) ($status->grupo_macro ?? ''),
            'icone' => (string) ($status->icone ?? ''),
            'cor' => (string) ($status->cor ?? ''),
            'ordem_fluxo' => (int) ($status->ordem_fluxo ?? 0),
            'status_final' => (bool) ($status->status_final ?? false),
            'status_pausa' => (bool) ($status->status_pausa ?? false),
            'gera_evento_crm' => (bool) ($status->gera_evento_crm ?? false),
            'estado_fluxo_padrao' => (string) ($status->estado_fluxo_padrao ?? ''),
            'ativo' => (bool) ($status->ativo ?? false),
            'created_at' => $this->formatDateTime($status->created_at),
            'updated_at' => $this->formatDateTime($status->updated_at),
        ];
    }

    private function normalizeFieldValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        return (string) $value;
    }
}
