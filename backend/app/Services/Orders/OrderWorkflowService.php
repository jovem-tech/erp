<?php

namespace App\Services\Orders;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderPhoto;
use App\Models\OrderStatus;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Notifications\MobileNotification;
use App\Services\Financeiro\OsMargemService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class OrderWorkflowService
{
    public function __construct(
        private readonly OrderNumberService $orderNumberService,
        private readonly OsMargemService $osMargemService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function paginateForUser(User $actor, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseSummaryQuery();

        if ($this->isTechnicianScoped($actor)) {
            $query->assignedToTechnician((int) $actor->id);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        $macroGroup = trim((string) ($filters['grupo_macro'] ?? ''));

        $this->applyOperationalStatusScope($query, $filters, $status, $macroGroup);

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $this->applySearchFilter($query, $search);
        }

        if ($status !== '') {
            $query->where('os.status', $status);
        }

        $technicianId = (int) ($filters['technician_id'] ?? 0);
        if ($technicianId > 0 && ! $this->isTechnicianScoped($actor)) {
            $query->where('os.tecnico_id', $technicianId);
        }

        $equipmentId = (int) ($filters['equipment_id'] ?? 0);
        if ($equipmentId > 0) {
            $query->where('os.equipamento_id', $equipmentId);
        }

        $clientId = (int) ($filters['client_id'] ?? 0);
        if ($clientId > 0) {
            $query->where('os.cliente_id', $clientId);
        }

        if ($macroGroup !== '') {
            $query->where('os_status.grupo_macro', $macroGroup);
        }

        $openingFrom = $this->normalizeDateValue($filters['data_abertura_de'] ?? null);
        if ($openingFrom !== null) {
            $query->whereDate('os.data_abertura', '>=', $openingFrom);
        }

        $openingTo = $this->normalizeDateValue($filters['data_abertura_ate'] ?? null);
        if ($openingTo !== null) {
            $query->whereDate('os.data_abertura', '<=', $openingTo);
        }

        if (($filters['valor_min'] ?? '') !== '') {
            $query->where('os.valor_final', '>=', (float) $filters['valor_min']);
        }

        if (($filters['valor_max'] ?? '') !== '') {
            $query->where('os.valor_final', '<=', (float) $filters['valor_max']);
        }

        $perPage = $this->normalizePerPage($filters['per_page'] ?? null);
        $paginator = $query->orderByDesc('os.id')->paginate($perPage)->withQueryString();

        $orders = $paginator->getCollection();
        $orderIds = $orders->map(static fn (Order $order): int => (int) $order->id)->all();
        $budgetByOrderId = $this->resolveLatestBudgetByOrderId($orderIds);
        $receivableByOrderId = $this->resolveReceivableSummaryByOrderId($orderIds);

        $paginator->setCollection(
            $orders->map(
                fn (Order $order): array => $this->mapSummary(
                    $order,
                    $budgetByOrderId[(int) $order->id] ?? null,
                    $receivableByOrderId[(int) $order->id] ?? null
                )
            )
        );

        return $paginator;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function applyOperationalStatusScope(Builder $query, array $filters, string $status, string $macroGroup): void
    {
        $statusScope = strtolower(trim((string) ($filters['status_scope'] ?? '')));
        if ($statusScope === '' || $status !== '' || $macroGroup !== '') {
            return;
        }

        if ($statusScope === 'open') {
            $query->where(static function (Builder $scopeQuery): void {
                $scopeQuery
                    ->whereNull('os.estado_fluxo')
                    ->orWhere('os.estado_fluxo', '!=', 'encerrado');
            });
        }
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveLatestBudgetByOrderId(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $colorByStatus = array_column(Budget::statusOptions(), 'color', 'value');

        return Budget::query()
            ->whereIn('os_id', $orderIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'os_id', 'numero', 'status', 'created_at'])
            ->groupBy('os_id')
            ->map(static function ($budgets) use ($colorByStatus): array {
                /** @var Budget $latest */
                $latest = $budgets->first();

                return [
                    'id' => (int) $latest->id,
                    'numero' => (string) ($latest->numero ?? ''),
                    'status' => (string) ($latest->status ?? ''),
                    'status_label' => Budget::statusLabel($latest->status),
                    'status_color' => (string) ($colorByStatus[$latest->status] ?? '#6b7280'),
                ];
            })
            ->all();
    }

    /**
     * @param array<int, int> $orderIds
     * @return array<int, array<string, mixed>>
     */
    private function resolveReceivableSummaryByOrderId(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $titulosPorOs = Financeiro::query()
            ->whereIn('os_id', $orderIds)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'os_id', 'valor', 'status'])
            ->groupBy('os_id')
            ->map(static fn ($titulos) => $titulos->first());

        if ($titulosPorOs->isEmpty()) {
            return [];
        }

        $tituloIds = $titulosPorOs->map(static fn (Financeiro $titulo): int => (int) $titulo->id)->all();

        $recebidoPorTitulo = FinanceiroMovimento::query()
            ->whereIn('financeiro_id', $tituloIds)
            ->selectRaw('financeiro_id, COALESCE(SUM(valor_movimento), 0) as total')
            ->groupBy('financeiro_id')
            ->pluck('total', 'financeiro_id');

        $resumoPorOs = [];

        foreach ($titulosPorOs as $osId => $titulo) {
            $valorTitulo = round((float) $titulo->valor, 2);
            $valorRecebido = round((float) ($recebidoPorTitulo[$titulo->id] ?? 0), 2);

            $resumoPorOs[(int) $osId] = [
                'titulo_id' => (int) $titulo->id,
                'status' => (string) ($titulo->status ?? ''),
                'valor_recebido' => $valorRecebido,
                'saldo' => max(0.0, round($valorTitulo - $valorRecebido, 2)),
            ];
        }

        return $resumoPorOs;
    }

    private function applySearchFilter(Builder $query, string $search): void
    {
        $searchTerm = '%' . mb_strtolower($search) . '%';

        $query->where(function (Builder $subQuery) use ($searchTerm): void {
            $this->orWhereLikeColumns($subQuery, [
                'os.numero_os',
                'os.numero_os_legado',
                'os.status',
                'os.estado_fluxo',
                'os.prioridade',
                'os.relato_cliente',
                'os.diagnostico_tecnico',
                'os.solucao_aplicada',
                'os.procedimentos_executados',
                'os.acessorios',
                'os.forma_pagamento',
                'os.orcamento_pdf',
                'os.observacoes_internas',
                'os.observacoes_cliente',
                'os.status_final_pendente_pagamento',
            ], $searchTerm);

            $this->orWhereLikeColumns($subQuery, [
                'clientes.nome_razao',
                'clientes.cpf_cnpj',
                'clientes.rg_ie',
                'clientes.email',
                'clientes.telefone1',
                'clientes.telefone2',
                'clientes.nome_contato',
                'clientes.telefone_contato',
                'clientes.cep',
                'clientes.endereco',
                'clientes.numero',
                'clientes.complemento',
                'clientes.referencia',
                'clientes.bairro',
                'clientes.cidade',
                'clientes.uf',
                'clientes.observacoes',
                'clientes.status_cadastro',
            ], $searchTerm);

            $this->orWhereLikeColumns($subQuery, [
                'equipamentos.resumo_tecnico',
                'equipamentos.numero_serie',
                'equipamentos.imei',
                'equipamentos.cor',
                'equipamentos.observacoes',
                'equipamentos.desktop_modalidade',
                'equipamentos.status_operacional',
                'equipamentos.status',
            ], $searchTerm);

            $this->orWhereLikeColumns($subQuery, [
                'os_status.codigo',
                'os_status.nome',
                'os_status.grupo_macro',
            ], $searchTerm);

            $this->orWhereLikeCastColumns($subQuery, [
                'os.valor_mao_obra',
                'os.valor_pecas',
                'os.valor_total',
                'os.desconto',
                'os.valor_final',
                'os.garantia_dias',
            ], $searchTerm);

            $this->orWhereLikeCastColumns($subQuery, [
                'os.status_atualizado_em',
                'os.data_abertura',
                'os.data_entrada',
                'os.data_previsao',
                'os.data_conclusao',
                'os.data_entrega',
                'os.baixa_tecnica_em',
                'os.data_aprovacao',
                'os.garantia_validade',
            ], $searchTerm);

            $subQuery->orWhereExists(function ($documentQuery) use ($searchTerm): void {
                $documentQuery
                    ->selectRaw('1')
                    ->from('os_documentos')
                    ->whereColumn('os_documentos.os_id', 'os.id')
                    ->where(function ($innerQuery) use ($searchTerm): void {
                        $this->orWhereLikeColumns($innerQuery, [
                            'os_documentos.tipo_documento',
                            'os_documentos.arquivo',
                        ], $searchTerm);
                    });
            });

            $subQuery->orWhereExists(function ($photoQuery) use ($searchTerm): void {
                $photoQuery
                    ->selectRaw('1')
                    ->from('os_fotos')
                    ->whereColumn('os_fotos.os_id', 'os.id')
                    ->where(function ($innerQuery) use ($searchTerm): void {
                        $this->orWhereLikeColumns($innerQuery, [
                            'os_fotos.tipo',
                            'os_fotos.arquivo',
                        ], $searchTerm);
                    });
            });
        });
    }

    /**
     * @param array<int, string> $columns
     */
    private function orWhereLikeColumns(Builder|QueryBuilder $query, array $columns, string $searchTerm): void
    {
        foreach ($columns as $column) {
            $query->orWhereRaw('LOWER(COALESCE(' . $column . ", '')) LIKE ?", [$searchTerm]);
        }
    }

    /**
     * @param array<int, string> $columns
     */
    private function orWhereLikeCastColumns(Builder|QueryBuilder $query, array $columns, string $searchTerm): void
    {
        foreach ($columns as $column) {
            $query->orWhereRaw('LOWER(COALESCE(CAST(' . $column . " AS CHAR), '')) LIKE ?", [$searchTerm]);
        }
    }

    public function showForUser(User $actor, int $orderId): array
    {
        $order = $this->detailQuery()->find($orderId);

        if (! $order instanceof Order) {
            return [
                'result' => 'not_found',
            ];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return [
                'result' => 'forbidden',
            ];
        }

        return [
            'result' => 'ok',
            'order' => $this->mapDetail($order),
        ];
    }

    public function resolvePhotoAccess(int $orderId, int $photoId, User $actor): array
    {
        $order = Order::query()
            ->select(['id', 'tecnico_id'])
            ->find($orderId);

        if (! $order instanceof Order) {
            return [
                'result' => 'not_found',
            ];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return [
                'result' => 'forbidden',
            ];
        }

        $photo = OrderPhoto::query()
            ->whereKey($photoId)
            ->where('os_id', $orderId)
            ->first();

        if (! $photo instanceof OrderPhoto) {
            return [
                'result' => 'not_found',
            ];
        }

        $file = $this->resolveLegacyPhotoFile((string) ($photo->arquivo ?? ''), (string) ($photo->tipo ?? ''));
        if (! is_array($file)) {
            logger()->warning('[API V1][ORDERS] Foto da OS não encontrada em disco', [
                'order_id' => $orderId,
                'photo_id' => $photoId,
                'arquivo' => (string) ($photo->arquivo ?? ''),
                'tipo' => (string) ($photo->tipo ?? ''),
            ]);

            return [
                'result' => 'missing_file',
            ];
        }

        return [
            'result' => 'ok',
            'file' => $file,
        ];
    }

    public function resolveDocumentAccess(int $orderId, int $documentId, User $actor): array
    {
        $order = Order::query()
            ->select(['id', 'tecnico_id'])
            ->find($orderId);

        if (! $order instanceof Order) {
            return [
                'result' => 'not_found',
            ];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return [
                'result' => 'forbidden',
            ];
        }

        $document = OrderDocument::query()
            ->whereKey($documentId)
            ->where('os_id', $orderId)
            ->first();

        if (! $document instanceof OrderDocument) {
            return [
                'result' => 'not_found',
            ];
        }

        $file = $this->resolveLegacyDocumentFile((string) ($document->arquivo ?? ''));
        if (! is_array($file)) {
            logger()->warning('[API V1][ORDERS] Documento da OS não encontrado em disco', [
                'order_id' => $orderId,
                'document_id' => $documentId,
                'arquivo' => (string) ($document->arquivo ?? ''),
            ]);

            return [
                'result' => 'missing_file',
            ];
        }

        return [
            'result' => 'ok',
            'file' => $file,
        ];
    }

    public function updateStatus(int $orderId, User $actor, string $newStatus, ?string $observacao = null): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return [
                'result' => 'not_found',
            ];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return [
                'result' => 'forbidden',
                'order' => $this->mapSummaryFromOrderId($orderId),
            ];
        }

        $statusRow = OrderStatus::activeByCode($newStatus);
        if (! $statusRow instanceof OrderStatus) {
            return [
                'result' => 'invalid_status',
            ];
        }

        $now = Carbon::now();
        $previousStatus = trim((string) ($order->status ?? ''));
        $estadoFluxo = trim((string) ($statusRow->estado_fluxo_padrao ?? '')) ?: 'em_atendimento';

        DB::transaction(function () use ($orderId, $previousStatus, $newStatus, $estadoFluxo, $actor, $observacao, $now): void {
            Order::query()
                ->whereKey($orderId)
                ->update([
                    'status' => $newStatus,
                    'estado_fluxo' => $estadoFluxo,
                    'status_atualizado_em' => $now,
                    'updated_at' => $now,
                ]);

            $this->createStatusHistory($orderId, $previousStatus, $newStatus, $estadoFluxo, $actor, $observacao, $now);
        });

        if ((bool) ($statusRow->status_final ?? false)) {
            try {
                $this->osMargemService->calcularParaOs($orderId);
            } catch (Throwable $exception) {
                logger()->warning('[API V1][ORDERS] Falha ao calcular margem da OS', [
                    'order_id' => $orderId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $updatedOrder = $this->detailQuery()->find($orderId);

        if ($updatedOrder instanceof Order) {
            $this->sendOrderNotification(
                $updatedOrder,
                $actor,
                'order.status.updated',
                'Status da OS atualizado',
                'A OS ' . $updatedOrder->numero_os . ' foi movida para ' . ($updatedOrder->statusCatalog?->nome ?? $newStatus) . '.',
                [
                    'status_anterior' => $previousStatus !== '' ? $previousStatus : null,
                    'status_novo' => $newStatus,
                    'estado_fluxo' => $estadoFluxo,
                    'observacao' => $observacao,
                    'icon' => 'clipboard-check',
                ]
            );
        }

        logger()->info('[API V1][ORDERS] Status alterado', [
            'order_id' => $orderId,
            'user_id' => $actor->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'estado_fluxo' => $estadoFluxo,
        ]);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapDetail($updatedOrder) : null,
            'status_anterior' => $previousStatus,
            'status_novo' => $newStatus,
            'estado_fluxo' => $estadoFluxo,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function createOrder(User $actor, array $attributes): array
    {
        $clientId = (int) ($attributes['cliente_id'] ?? 0);
        $equipmentId = (int) ($attributes['equipamento_id'] ?? 0);

        if (! $this->equipmentBelongsToClient($equipmentId, $clientId)) {
            return [
                'result' => 'equipment_client_mismatch',
            ];
        }

        $statusCode = trim((string) ($attributes['status'] ?? 'triagem'));
        $statusCode = $statusCode !== '' ? $statusCode : 'triagem';
        $statusRow = OrderStatus::activeByCode($statusCode);
        if (! $statusRow instanceof OrderStatus) {
            return [
                'result' => 'invalid_status',
            ];
        }

        $now = Carbon::now();
        $estadoFluxo = $this->normalizeString($attributes['estado_fluxo'] ?? null)
            ?: trim((string) ($statusRow->estado_fluxo_padrao ?? 'em_atendimento'));

        $payload = $this->extractMutableOrderAttributes($attributes, true);
        $payload['numero_os'] = $this->orderNumberService->nextNumber();
        $payload['status'] = $statusCode;
        $payload['estado_fluxo'] = $estadoFluxo;
        $payload['status_atualizado_em'] = $now;
        $payload['data_abertura'] = $this->normalizeDateTimeValue($attributes['data_abertura'] ?? null) ?? $now->copy()->toDateTimeString();
        $payload['data_entrada'] = $this->normalizeDateTimeValue($attributes['data_entrada'] ?? null) ?? $now->copy()->toDateTimeString();

        $order = DB::transaction(function () use ($payload, $actor, $statusCode, $estadoFluxo, $now): Order {
            /** @var Order $order */
            $order = Order::query()->create($payload);

            $this->createStatusHistory(
                (int) $order->id,
                null,
                $statusCode,
                $estadoFluxo,
                $actor,
                'OS criada pelo backend central.',
                $now
            );

            return $order;
        });

        $createdOrder = $this->detailQuery()->find((int) $order->id);

        if ($createdOrder instanceof Order) {
            $this->sendOrderNotification(
                $createdOrder,
                $actor,
                'order.created',
                'Nova OS criada',
                'A OS ' . $createdOrder->numero_os . ' foi aberta para ' . ($createdOrder->client?->nome_razao ?? 'o cliente selecionado') . '.',
                [
                    'status_novo' => $statusCode,
                    'estado_fluxo' => $estadoFluxo,
                    'icon' => 'clipboard-plus',
                ]
            );
        }

        return [
            'result' => 'ok',
            'order' => $createdOrder instanceof Order ? $this->mapDetail($createdOrder) : null,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public function updateOrder(int $orderId, User $actor, array $attributes): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return [
                'result' => 'not_found',
            ];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return [
                'result' => 'forbidden',
            ];
        }

        $clientId = (int) ($attributes['cliente_id'] ?? $order->cliente_id ?? 0);
        $equipmentId = (int) ($attributes['equipamento_id'] ?? $order->equipamento_id ?? 0);
        if (! $this->equipmentBelongsToClient($equipmentId, $clientId)) {
            return [
                'result' => 'equipment_client_mismatch',
            ];
        }

        $payload = $this->extractMutableOrderAttributes($attributes, false);
        $statusChanged = array_key_exists('status', $payload);
        $previousStatus = trim((string) ($order->status ?? ''));
        $estadoFluxo = trim((string) ($order->estado_fluxo ?? ''));

        if ($statusChanged) {
            $statusRow = OrderStatus::activeByCode((string) $payload['status']);
            if (! $statusRow instanceof OrderStatus) {
                return [
                    'result' => 'invalid_status',
                ];
            }

            if (! array_key_exists('estado_fluxo', $payload)) {
                $payload['estado_fluxo'] = trim((string) ($statusRow->estado_fluxo_padrao ?? 'em_atendimento'));
            }

            $payload['status_atualizado_em'] = Carbon::now()->toDateTimeString();
            $estadoFluxo = (string) ($payload['estado_fluxo'] ?? $estadoFluxo);
        }

        if ($payload !== []) {
            DB::transaction(function () use ($orderId, $payload, $statusChanged, $previousStatus, $actor, $estadoFluxo): void {
                Order::query()
                    ->whereKey($orderId)
                    ->update(array_merge($payload, ['updated_at' => Carbon::now()]));

                if ($statusChanged) {
                    $this->createStatusHistory(
                        $orderId,
                        $previousStatus !== '' ? $previousStatus : null,
                        (string) $payload['status'],
                        $estadoFluxo,
                        $actor,
                        'OS atualizada pelo backend central.',
                        Carbon::now()
                    );
                }
            });
        }

        $updatedOrder = $this->detailQuery()->find($orderId);

        if ($statusChanged && $updatedOrder instanceof Order) {
            $this->sendOrderNotification(
                $updatedOrder,
                $actor,
                'order.updated',
                'OS atualizada',
                'A OS ' . $updatedOrder->numero_os . ' recebeu alterações de cadastro.',
                [
                    'status_anterior' => $previousStatus !== '' ? $previousStatus : null,
                    'status_novo' => (string) ($payload['status'] ?? $order->status),
                    'estado_fluxo' => (string) ($payload['estado_fluxo'] ?? $order->estado_fluxo),
                    'icon' => 'pencil-square',
                ]
            );
        }

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapDetail($updatedOrder) : null,
        ];
    }

    /**
     * @return Builder<Order>
     */
    private function detailQuery(): Builder
    {
        return Order::query()->with([
            'client',
            'equipment',
            'technician',
            'statusCatalog',
            'statusHistory' => static function ($query): void {
                $query
                    ->with('user')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(5);
            },
            'photos' => static function ($query): void {
                $query->orderBy('id');
            },
            'documents' => static function ($query): void {
                $query
                    ->with('generatedBy')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id');
            },
        ]);
    }

    /**
     * @return Builder<Order>
     */
    private function baseSummaryQuery(): Builder
    {
        return Order::query()
            ->select([
                'os.id',
                'os.numero_os',
                'os.numero_os_legado',
                'os.cliente_id',
                'os.equipamento_id',
                'os.tecnico_id',
                'os.status',
                'os.estado_fluxo',
                'os.prioridade',
                'os.status_atualizado_em',
                'os.data_abertura',
                'os.data_entrada',
                'os.data_previsao',
                'os.data_conclusao',
                'os.data_entrega',
                'os.valor_mao_obra',
                'os.valor_pecas',
                'os.desconto',
                'os.valor_final',
                'clientes.nome_razao as cliente_nome',
                'clientes.telefone1 as cliente_telefone',
                'clientes.telefone_contato as cliente_telefone_contato',
                'equipamentos.resumo_tecnico as equipamento_resumo_tecnico',
                'equipamentos.numero_serie as equipamento_numero_serie',
                'equipamentos_tipos.nome as equipamento_tipo_nome',
                'equipamentos_marcas.nome as equipamento_marca_nome',
                'equipamentos_modelos.nome as equipamento_modelo_nome',
                'equipamentos_fotos.id as equipamento_foto_id',
                'os_status.nome as status_nome',
                'os_status.cor as status_cor',
                'os_status.grupo_macro as status_grupo_macro',
            ])
            ->leftJoin('clientes', 'clientes.id', '=', 'os.cliente_id')
            ->leftJoin('equipamentos', 'equipamentos.id', '=', 'os.equipamento_id')
            ->leftJoin('equipamentos_tipos', 'equipamentos_tipos.id', '=', 'equipamentos.tipo_id')
            ->leftJoin('equipamentos_marcas', 'equipamentos_marcas.id', '=', 'equipamentos.marca_id')
            ->leftJoin('equipamentos_modelos', 'equipamentos_modelos.id', '=', 'equipamentos.modelo_id')
            ->leftJoin('equipamentos_fotos', function ($join): void {
                $join->on('equipamentos_fotos.equipamento_id', '=', 'equipamentos.id')
                    ->where('equipamentos_fotos.is_principal', '=', 1);
            })
            ->leftJoin('os_status', 'os_status.codigo', '=', 'os.status');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapSummaryFromOrderId(int $orderId): ?array
    {
        $order = $this->baseSummaryQuery()->where('os.id', $orderId)->first();

        return $order instanceof Order ? $this->mapSummary($order) : null;
    }

    /**
     * @param array<string, mixed>|null $budget
     * @param array<string, mixed>|null $receivable
     * @return array<string, mixed>
     */
    private function mapSummary(Order $order, ?array $budget = null, ?array $receivable = null): array
    {
        $valorFinal = (float) ($order->valor_final ?? 0);
        $valorRecebido = (float) ($receivable['valor_recebido'] ?? 0);

        return [
            'id' => (int) ($order->id ?? 0),
            'numero_os' => (string) ($order->numero_os ?? ''),
            'numero_os_legado' => (string) ($order->numero_os_legado ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'cliente_nome' => (string) ($order->cliente_nome ?? ''),
            'cliente_telefone' => $this->resolveClientPhone($order->cliente_telefone ?? null, $order->cliente_telefone_contato ?? null),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
            'equipamento_resumo_tecnico' => (string) ($order->equipamento_resumo_tecnico ?? ''),
            'equipamento_resumo_curto' => $this->resolveEquipmentShortSummary(
                $order->equipamento_tipo_nome ?? null,
                $order->equipamento_marca_nome ?? null,
                $order->equipamento_modelo_nome ?? null,
                $order->equipamento_resumo_tecnico ?? null
            ),
            'equipamento_numero_serie' => (string) ($order->equipamento_numero_serie ?? ''),
            'equipamento_foto_id' => (int) ($order->equipamento_foto_id ?? 0),
            'equipamento_foto_url' => $this->buildEquipmentPhotoUrlIfAny(
                (int) ($order->equipamento_id ?? 0),
                $order->equipamento_foto_id ?? null
            ),
            'tecnico_id' => (int) ($order->tecnico_id ?? 0),
            'status' => (string) ($order->status ?? ''),
            'status_nome' => (string) ($order->status_nome ?? ''),
            'status_cor' => (string) ($order->status_cor ?? ''),
            'status_grupo_macro' => (string) ($order->status_grupo_macro ?? ''),
            'prioridade' => (string) ($order->prioridade ?? ''),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'status_atualizado_em' => $this->formatDateTime($order->status_atualizado_em ?? null),
            'data_abertura' => $this->formatDateTime($order->data_abertura ?? null),
            'data_entrada' => $this->formatDateTime($order->data_entrada ?? null),
            'data_previsao' => $this->formatDate($order->data_previsao ?? null),
            'data_conclusao' => $this->formatDateTime($order->data_conclusao ?? null),
            'data_entrega' => $this->formatDateTime($order->data_entrega ?? null),
            'prazo' => $this->resolveDeadlineState(
                $order->data_previsao ?? null,
                $order->data_conclusao ?? null,
                $order->data_entrega ?? null
            ),
            'orcamento' => $budget,
            'valor_mao_obra' => $this->normalizeDecimalString($order->valor_mao_obra ?? null),
            'valor_pecas' => $this->normalizeDecimalString($order->valor_pecas ?? null),
            'desconto' => $this->normalizeDecimalString($order->desconto ?? null),
            'valor_final' => $this->normalizeDecimalString($order->valor_final ?? null),
            'valor_recebido' => $receivable !== null ? $this->normalizeDecimalString($valorRecebido) : null,
            'saldo' => $receivable !== null ? $this->normalizeDecimalString($receivable['saldo'] ?? max(0.0, $valorFinal - $valorRecebido)) : null,
        ];
    }

    private function resolveClientPhone(mixed $telefone1, mixed $telefoneContato): string
    {
        $telefone1 = trim((string) ($telefone1 ?? ''));

        return $telefone1 !== '' ? $telefone1 : trim((string) ($telefoneContato ?? ''));
    }

    private function resolveEquipmentShortSummary(
        mixed $tipoNome,
        mixed $marcaNome,
        mixed $modeloNome,
        mixed $resumoTecnico
    ): string {
        $parts = array_values(array_filter([
            trim((string) ($tipoNome ?? '')),
            trim((string) ($marcaNome ?? '')),
            trim((string) ($modeloNome ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        $fallback = trim((string) ($resumoTecnico ?? ''));
        if ($fallback === '') {
            return 'Sem resumo técnico';
        }

        return mb_strlen($fallback) > 60 ? mb_substr($fallback, 0, 57) . '...' : $fallback;
    }

    /**
     * Caminho da API central, util para um consumidor ja autenticado com Bearer
     * (ex.: app mobile). O desktop nao deve usar este campo diretamente num <img> —
     * ele resolve `equipamento_foto_id` para a propria rota proxy autenticada
     * (`equipments.photos.show`), do mesmo jeito que ja faz para fotos de OS.
     */
    private function buildEquipmentPhotoUrlIfAny(int $equipmentId, mixed $photoId): ?string
    {
        $photoId = (int) ($photoId ?? 0);
        if ($equipmentId <= 0 || $photoId <= 0) {
            return null;
        }

        return route('api.v1.equipments.photos.show', [
            'equipment' => $equipmentId,
            'photo' => $photoId,
        ], false);
    }

    /**
     * @return array{estado: string, label: string, dias: int|null}
     */
    private function resolveDeadlineState(mixed $previsao, mixed $conclusao, mixed $entrega): array
    {
        $previsao = trim((string) ($previsao ?? ''));
        if ($previsao === '') {
            return ['estado' => 'sem_previsao', 'label' => 'Sem previsão', 'dias' => null];
        }

        $previsaoDate = Carbon::parse($previsao)->startOfDay();
        $referencia = trim((string) ($conclusao ?? '')) !== '' ? $conclusao : $entrega;
        $referencia = trim((string) ($referencia ?? ''));

        if ($referencia !== '') {
            $referenciaDate = Carbon::parse($referencia)->startOfDay();
            $diasAtraso = (int) floor(($referenciaDate->getTimestamp() - $previsaoDate->getTimestamp()) / 86400);

            if ($diasAtraso <= 0) {
                return ['estado' => 'concluido_no_prazo', 'label' => 'Concluída no prazo', 'dias' => 0];
            }

            return ['estado' => 'concluido_atrasado', 'label' => 'Concluída com atraso', 'dias' => $diasAtraso];
        }

        $diasParaPrevisao = (int) floor(($previsaoDate->getTimestamp() - Carbon::now()->startOfDay()->getTimestamp()) / 86400);

        if ($diasParaPrevisao < 0) {
            return ['estado' => 'atrasado', 'label' => 'Atrasada', 'dias' => abs($diasParaPrevisao)];
        }

        if ($diasParaPrevisao === 0) {
            return ['estado' => 'vence_hoje', 'label' => 'Vence hoje', 'dias' => 0];
        }

        if ($diasParaPrevisao <= 2) {
            return ['estado' => 'critico', 'label' => 'Vence em breve', 'dias' => $diasParaPrevisao];
        }

        return ['estado' => 'no_prazo', 'label' => 'No prazo', 'dias' => $diasParaPrevisao];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapDetail(Order $order): array
    {
        $statusRow = $order->statusCatalog;

        return [
            'id' => (int) ($order->id ?? 0),
            'numero_os' => (string) ($order->numero_os ?? ''),
            'numero_os_legado' => (string) ($order->numero_os_legado ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
            'cliente' => $this->mapClient($order->client),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
            'equipamento_resumo_tecnico' => (string) ($order->equipment?->resumo_tecnico ?? ''),
            'equipamento_numero_serie' => (string) ($order->equipment?->numero_serie ?? ''),
            'equipamento' => $this->mapEquipment($order->equipment),
            'tecnico_id' => (int) ($order->tecnico_id ?? 0),
            'tecnico' => $this->mapTechnician($order->technician),
            'status' => (string) ($order->status ?? ''),
            'status_nome' => (string) ($statusRow?->nome ?? ''),
            'status_cor' => (string) ($statusRow?->cor ?? ''),
            'status_grupo_macro' => (string) ($statusRow?->grupo_macro ?? ''),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'prioridade' => (string) ($order->prioridade ?? ''),
            'status_atualizado_em' => $this->formatDateTime($order->status_atualizado_em ?? null),
            'relato_cliente' => (string) ($order->relato_cliente ?? ''),
            'diagnostico_tecnico' => (string) ($order->diagnostico_tecnico ?? ''),
            'solucao_aplicada' => (string) ($order->solucao_aplicada ?? ''),
            'procedimentos_executados' => (string) ($order->procedimentos_executados ?? ''),
            'acessorios' => (string) ($order->acessorios ?? ''),
            'forma_pagamento' => (string) ($order->forma_pagamento ?? ''),
            'data_abertura' => $this->formatDateTime($order->data_abertura ?? null),
            'data_entrada' => $this->formatDateTime($order->data_entrada ?? null),
            'data_previsao' => $this->formatDate($order->data_previsao ?? null),
            'data_conclusao' => $this->formatDateTime($order->data_conclusao ?? null),
            'data_entrega' => $this->formatDateTime($order->data_entrega ?? null),
            'baixa_tecnica_em' => $this->formatDateTime($order->baixa_tecnica_em ?? null),
            'baixa_tecnica_por' => (int) ($order->baixa_tecnica_por ?? 0),
            'valor_mao_obra' => $this->normalizeDecimalString($order->valor_mao_obra ?? null),
            'valor_pecas' => $this->normalizeDecimalString($order->valor_pecas ?? null),
            'valor_total' => $this->normalizeDecimalString($order->valor_total ?? null),
            'desconto' => $this->normalizeDecimalString($order->desconto ?? null),
            'valor_final' => $this->normalizeDecimalString($order->valor_final ?? null),
            'orcamento_aprovado' => (bool) ($order->orcamento_aprovado ?? false),
            'data_aprovacao' => $this->formatDateTime($order->data_aprovacao ?? null),
            'orcamento_pdf' => (string) ($order->orcamento_pdf ?? ''),
            'garantia_dias' => (int) ($order->garantia_dias ?? 0),
            'garantia_validade' => $this->formatDate($order->garantia_validade ?? null),
            'observacoes_internas' => (string) ($order->observacoes_internas ?? ''),
            'observacoes_cliente' => (string) ($order->observacoes_cliente ?? ''),
            'historico' => $this->mapHistoryCollection($order->statusHistory),
            'status_disponiveis' => $this->mapStatusOptions(),
            'fotos' => $this->mapPhotoCollection($order->photos, (int) ($order->id ?? 0)),
            'documentos' => $this->mapDocumentCollection($order->documents, (int) ($order->id ?? 0)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapClient(?Client $client): ?array
    {
        if (! $client instanceof Client) {
            return null;
        }

        return [
            'id' => (int) ($client->id ?? 0),
            'tipo_pessoa' => (string) ($client->tipo_pessoa ?? ''),
            'nome_razao' => (string) ($client->nome_razao ?? ''),
            'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
            'rg_ie' => (string) ($client->rg_ie ?? ''),
            'email' => (string) ($client->email ?? ''),
            'telefone1' => (string) ($client->telefone1 ?? ''),
            'telefone2' => (string) ($client->telefone2 ?? ''),
            'nome_contato' => (string) ($client->nome_contato ?? ''),
            'telefone_contato' => (string) ($client->telefone_contato ?? ''),
            'cep' => (string) ($client->cep ?? ''),
            'endereco' => (string) ($client->endereco ?? ''),
            'numero' => (string) ($client->numero ?? ''),
            'complemento' => (string) ($client->complemento ?? ''),
            'referencia' => (string) ($client->referencia ?? ''),
            'bairro' => (string) ($client->bairro ?? ''),
            'cidade' => (string) ($client->cidade ?? ''),
            'uf' => (string) ($client->uf ?? ''),
            'observacoes' => (string) ($client->observacoes ?? ''),
            'status_cadastro' => (string) ($client->status_cadastro ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapEquipment(?Equipment $equipment): ?array
    {
        if (! $equipment instanceof Equipment) {
            return null;
        }

        return [
            'id' => (int) ($equipment->id ?? 0),
            'cliente_id' => (int) ($equipment->cliente_id ?? 0),
            'tipo_id' => (int) ($equipment->tipo_id ?? 0),
            'marca_id' => (int) ($equipment->marca_id ?? 0),
            'modelo_id' => (int) ($equipment->modelo_id ?? 0),
            'cor' => (string) ($equipment->cor ?? ''),
            'numero_serie' => (string) ($equipment->numero_serie ?? ''),
            'imei' => (string) ($equipment->imei ?? ''),
            'desktop_modalidade' => (string) ($equipment->desktop_modalidade ?? ''),
            'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
            'observacoes' => (string) ($equipment->observacoes ?? ''),
            'status_operacional' => (string) ($equipment->status_operacional ?? ''),
            'status' => (string) ($equipment->status ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapTechnician(?User $user): ?array
    {
        if (! $user instanceof User) {
            return null;
        }

        return [
            'id' => (int) ($user->id ?? 0),
            'nome' => (string) ($user->nome ?? ''),
            'email' => (string) ($user->email ?? ''),
            'perfil' => (string) ($user->perfil ?? ''),
            'grupo_id' => (int) ($user->grupo_id ?? 0),
            'foto' => (string) ($user->foto ?? ''),
            'ativo' => (bool) ($user->ativo ?? false),
        ];
    }

    /**
     * @param iterable<OrderStatusHistory> $historyItems
     * @return array<int, array<string, mixed>>
     */
    private function mapHistoryCollection(iterable $historyItems): array
    {
        $items = [];

        foreach ($historyItems as $historyItem) {
            if (! $historyItem instanceof OrderStatusHistory) {
                continue;
            }

            $items[] = [
                'id' => (int) ($historyItem->id ?? 0),
                'status_anterior' => (string) ($historyItem->status_anterior ?? ''),
                'status_novo' => (string) ($historyItem->status_novo ?? ''),
                'estado_fluxo' => (string) ($historyItem->estado_fluxo ?? ''),
                'observacao' => (string) ($historyItem->observacao ?? ''),
                'created_at' => $this->formatDateTime($historyItem->created_at ?? null),
                'usuario_id' => (int) ($historyItem->usuario_id ?? 0),
                'usuario' => $this->mapTechnician($historyItem->user),
            ];
        }

        return $items;
    }

    /**
     * @param iterable<OrderPhoto> $photos
     * @return array<int, array<string, mixed>>
     */
    private function mapPhotoCollection(iterable $photos, int $orderId): array
    {
        $items = [];

        foreach ($photos as $photo) {
            if (! $photo instanceof OrderPhoto) {
                continue;
            }

            $arquivo = $this->normalizeStoredPath((string) ($photo->arquivo ?? ''));
            $tipo = strtolower(trim((string) ($photo->tipo ?? '')));

            $items[] = [
                'id' => (int) ($photo->id ?? 0),
                'tipo' => $tipo,
                'tipo_label' => $this->humanizePhotoTipo($tipo),
                'arquivo' => $arquivo,
                'nome_arquivo' => $arquivo !== '' ? basename($arquivo) : '',
                'url' => $this->buildOrderPhotoUrl($orderId, (int) ($photo->id ?? 0)),
                'created_at' => $this->formatDateTime($photo->created_at ?? null),
            ];
        }

        return $items;
    }

    /**
     * @param iterable<OrderDocument> $documents
     * @return array<int, array<string, mixed>>
     */
    private function mapDocumentCollection(iterable $documents, int $orderId): array
    {
        $items = [];

        foreach ($documents as $document) {
            if (! $document instanceof OrderDocument) {
                continue;
            }

            $arquivo = $this->normalizeStoredPath((string) ($document->arquivo ?? ''));
            $tipo = strtolower(trim((string) ($document->tipo_documento ?? '')));

            $items[] = [
                'id' => (int) ($document->id ?? 0),
                'tipo_documento' => $tipo,
                'tipo_label' => $this->humanizeDocumentType($tipo),
                'arquivo' => $arquivo,
                'nome_arquivo' => $arquivo !== '' ? basename($arquivo) : '',
                'versao' => (int) ($document->versao ?? 1),
                'hash_sha1' => (string) ($document->hash_sha1 ?? ''),
                'url' => $this->buildOrderDocumentUrl($orderId, (int) ($document->id ?? 0)),
                'created_at' => $this->formatDateTime($document->created_at ?? null),
                'updated_at' => $this->formatDateTime($document->updated_at ?? null),
                'gerado_por' => (int) ($document->gerado_por ?? 0),
                'gerado_por_usuario' => $this->mapTechnician($document->generatedBy),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapStatusOptions(): array
    {
        return OrderStatus::query()
            ->active()
            ->orderBy('ordem_fluxo')
            ->get()
            ->map(static function (OrderStatus $status): array {
                return [
                    'codigo' => (string) ($status->codigo ?? ''),
                    'nome' => (string) ($status->nome ?? ''),
                    'grupo_macro' => (string) ($status->grupo_macro ?? ''),
                    'cor' => (string) ($status->cor ?? ''),
                    'icone' => (string) ($status->icone ?? ''),
                    'ordem_fluxo' => (int) ($status->ordem_fluxo ?? 0),
                    'status_final' => (bool) ($status->status_final ?? false),
                    'status_pausa' => (bool) ($status->status_pausa ?? false),
                    'estado_fluxo_padrao' => (string) ($status->estado_fluxo_padrao ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function buildOrderPhotoUrl(int $orderId, int $photoId): string
    {
        return route('api.v1.orders.photos.show', [
            'order' => $orderId,
            'photo' => $photoId,
        ], false);
    }

    private function buildOrderDocumentUrl(int $orderId, int $documentId): string
    {
        return route('api.v1.orders.documents.show', [
            'order' => $orderId,
            'document' => $documentId,
        ], false);
    }

    /**
     * @param array<int, string> $candidates
     * @return array{absolute_path:string, relative_path:string, filename:string, mime_type:string}|null
     */
    private function resolveLegacyFileCandidates(array $candidates): ?array
    {
        $root = $this->legacyPublicRootPath();

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $relative = $this->normalizeStoredPath((string) $candidate);
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }

            $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (! is_file($absolute)) {
                continue;
            }

            return [
                'absolute_path' => $absolute,
                'relative_path' => $relative,
                'filename' => basename($relative),
                'mime_type' => $this->inferMimeType($relative),
            ];
        }

        return null;
    }

    /**
     * @return array{absolute_path:string, relative_path:string, filename:string, mime_type:string}|null
     */
    private function resolveLegacyPhotoFile(string $arquivo, string $tipo = ''): ?array
    {
        $normalized = $this->normalizeStoredPath($arquivo);
        $basename = basename($normalized);
        $folders = $this->photoFoldersByType($tipo);

        $candidates = [];
        if ($normalized !== '') {
            $candidates[] = $normalized;
            if (! str_starts_with($normalized, 'uploads/')) {
                foreach ($folders as $folder) {
                    $candidates[] = $folder . '/' . $normalized;
                }
            }
        }

        if ($basename !== '') {
            foreach ($folders as $folder) {
                $candidates[] = $folder . '/' . $basename;
            }

            $candidates[] = 'uploads/' . $basename;
        }

        return $this->resolveLegacyFileCandidates($candidates);
    }

    /**
     * @return array{absolute_path:string, relative_path:string, filename:string, mime_type:string}|null
     */
    private function resolveLegacyDocumentFile(string $arquivo): ?array
    {
        $normalized = $this->normalizeStoredPath($arquivo);
        $basename = basename($normalized);

        $candidates = [];
        if ($normalized !== '') {
            $candidates[] = $normalized;
            if (! str_starts_with($normalized, 'uploads/')) {
                $candidates[] = 'uploads/os_documentos/' . $normalized;
            }
        }

        if ($basename !== '') {
            $candidates[] = 'uploads/os_documentos/' . $basename;
            $candidates[] = 'uploads/' . $basename;
        }

        return $this->resolveLegacyFileCandidates($candidates);
    }

    private function legacyPublicRootPath(): string
    {
        $configuredRoot = trim((string) config('filesystems.disks.legacy_public.root', ''));
        if ($configuredRoot !== '') {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configuredRoot), DIRECTORY_SEPARATOR);
        }

        return rtrim(
            dirname(base_path(), 2) . DIRECTORY_SEPARATOR . 'sistema-hml' . DIRECTORY_SEPARATOR . 'public',
            DIRECTORY_SEPARATOR
        );
    }

    private function normalizeStoredPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        return ltrim($path, '/');
    }

    /**
     * @return array<int, string>
     */
    private function photoFoldersByType(string $tipo): array
    {
        $tipo = strtolower(trim($tipo));

        return match ($tipo) {
            'recepcao' => [
                'uploads/os_anormalidades',
                'uploads/os',
                'uploads/os_fotos',
            ],
            default => [
                'uploads/os',
                'uploads/os_anormalidades',
                'uploads/os_fotos',
            ],
        };
    }

    private function humanizeDocumentType(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return 'Documento PDF';
        }

        $map = [
            'abertura' => 'Abertura',
            'orcamento' => 'Orçamento',
            'laudo' => 'Laudo',
            'entrega' => 'Entrega',
            'devolucao_sem_reparo' => 'Devolução sem Reparo',
        ];

        if (isset($map[$type])) {
            return $map[$type];
        }

        return ucwords(str_replace('_', ' ', $type));
    }

    private function humanizePhotoTipo(string $type): string
    {
        $type = strtolower(trim($type));
        if ($type === '') {
            return 'Foto';
        }

        return match ($type) {
            'recepcao' => 'Recepção',
            'diagnostico' => 'Diagnóstico',
            'entrega' => 'Entrega',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    private function inferMimeType(string $relativePath): string
    {
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'txt' => 'text/plain',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function extractMutableOrderAttributes(array $attributes, bool $creating): array
    {
        $payload = [];
        $integerFields = [
            'cliente_id',
            'equipamento_id',
            'tecnico_id',
            'baixa_tecnica_por',
            'garantia_dias',
        ];
        $stringFields = [
            'estado_fluxo',
            'prioridade',
            'relato_cliente',
            'diagnostico_tecnico',
            'solucao_aplicada',
            'procedimentos_executados',
            'acessorios',
            'forma_pagamento',
            'orcamento_pdf',
            'observacoes_internas',
            'observacoes_cliente',
        ];
        $dateTimeFields = [
            'data_abertura',
            'data_entrada',
            'data_conclusao',
            'data_entrega',
            'baixa_tecnica_em',
            'data_aprovacao',
            'status_atualizado_em',
        ];
        $dateFields = [
            'data_previsao',
            'garantia_validade',
        ];
        $decimalFields = [
            'valor_mao_obra',
            'valor_pecas',
            'valor_total',
            'desconto',
            'valor_final',
        ];

        foreach ($integerFields as $field) {
            if ($creating || array_key_exists($field, $attributes)) {
                $payload[$field] = $this->normalizeNullableInteger($attributes[$field] ?? null);
            }
        }

        foreach ($stringFields as $field) {
            if ($creating || array_key_exists($field, $attributes)) {
                $payload[$field] = $this->normalizeString($attributes[$field] ?? null);
            }
        }

        foreach ($dateTimeFields as $field) {
            if ($creating || array_key_exists($field, $attributes)) {
                $payload[$field] = $this->normalizeDateTimeValue($attributes[$field] ?? null);
            }
        }

        foreach ($dateFields as $field) {
            if ($creating || array_key_exists($field, $attributes)) {
                $payload[$field] = $this->normalizeDateValue($attributes[$field] ?? null);
            }
        }

        foreach ($decimalFields as $field) {
            if ($creating || array_key_exists($field, $attributes)) {
                $payload[$field] = $this->normalizeDecimalValue($attributes[$field] ?? null);
            }
        }

        if ($creating || array_key_exists('status', $attributes)) {
            $payload['status'] = trim((string) ($attributes['status'] ?? ''));
        }

        if ($creating || array_key_exists('orcamento_aprovado', $attributes)) {
            $payload['orcamento_aprovado'] = filter_var($attributes['orcamento_aprovado'] ?? false, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return array_filter(
            $payload,
            static fn ($value, $key): bool => $value !== null || in_array($key, ['tecnico_id', 'baixa_tecnica_por', 'garantia_dias'], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    private function createStatusHistory(
        int $orderId,
        ?string $previousStatus,
        string $newStatus,
        string $stateFlow,
        User $actor,
        ?string $note,
        Carbon $timestamp
    ): void {
        if (! Schema::hasTable('os_status_historico')) {
            return;
        }

        OrderStatusHistory::query()->create([
            'os_id' => $orderId,
            'status_anterior' => $previousStatus !== '' ? $previousStatus : null,
            'status_novo' => $newStatus,
            'estado_fluxo' => $stateFlow,
            'usuario_id' => (int) $actor->id,
            'observacao' => $note,
            'created_at' => $timestamp,
        ]);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function sendOrderNotification(
        Order $order,
        User $actor,
        string $kind,
        string $title,
        string $body,
        array $extra = []
    ): void {
        $payload = array_merge([
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'route' => '/os/' . (int) $order->id,
            'icon' => 'clipboard',
            'order_id' => (int) $order->id,
            'numero_os' => (string) ($order->numero_os ?? ''),
            'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
            'status' => (string) ($order->status ?? ''),
            'status_nome' => (string) ($order->statusCatalog?->nome ?? ''),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'actor_id' => (int) $actor->id,
            'actor_nome' => (string) ($actor->nome ?? ''),
        ], $extra);

        $recipients = [];
        foreach ([$actor, $order->technician] as $recipient) {
            if (! $recipient instanceof User) {
                continue;
            }

            $recipients[(int) $recipient->id] = $recipient;
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new MobileNotification($payload));
        }
    }

    public function canAccessOrder(User $actor, Order $order): bool
    {
        if (! $this->isTechnicianScoped($actor)) {
            return true;
        }

        return (int) ($order->tecnico_id ?? 0) === (int) $actor->id;
    }

    private function isTechnicianScoped(User $actor): bool
    {
        return mb_strtolower(trim((string) ($actor->perfil ?? ''))) === 'tecnico';
    }

    private function equipmentBelongsToClient(int $equipmentId, int $clientId): bool
    {
        if ($equipmentId <= 0 || $clientId <= 0) {
            return false;
        }

        return Equipment::query()
            ->whereKey($equipmentId)
            ->where('cliente_id', $clientId)
            ->exists();
    }

    private function normalizePerPage(mixed $value): int
    {
        $perPage = (int) $value;

        if ($perPage < 1) {
            return 15;
        }

        return min($perPage, 50);
    }

    private function normalizeNullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function normalizeString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDateTimeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateTimeString();
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }

    private function normalizeDecimalValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeDecimalString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
