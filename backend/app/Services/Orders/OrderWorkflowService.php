<?php

namespace App\Services\Orders;

use App\Models\Budget;
use App\Models\ChecklistModelo;
use App\Models\ChecklistTipo;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use App\Models\OrderDocument;
use App\Models\OrderEvent;
use App\Models\OrderPhoto;
use App\Models\OrderStatus;
use App\Models\OrderProcedureHistory;
use App\Models\OrderStatusHistory;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Notifications\MobileNotification;
use App\Services\Channels\Whatsapp\WhatsappMessagingService;
use App\Services\Financeiro\OsMargemService;
use App\Services\Integrations\IntegrationSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OrderWorkflowService
{
    private const ENTRY_CHECKLIST_TYPE_CODE = 'entrada';

    /**
     * @var array<int, string>
     */
    private const ENTRY_CHECKLIST_RESPONSE_STATUSES = [
        'ok',
        'discrepancia',
        'nao_verificado',
    ];

    public function __construct(
        private readonly OrderNumberService $orderNumberService,
        private readonly OsMargemService $osMargemService,
        private readonly OrderOpeningPdfService $orderOpeningPdfService,
        private readonly WhatsappMessagingService $whatsappMessagingService,
        private readonly IntegrationSettingsService $integrationSettingsService,
        private readonly OrderEventService $orderEventService
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
        $nextStepsByStatusCode = $this->resolveNextStatusOptionsMap(
            $orders
                ->pluck('status')
                ->map(static fn (mixed $status): string => trim((string) $status))
                ->filter(static fn (string $status): bool => $status !== '')
                ->unique()
                ->values()
                ->all()
        );
        // Calculado uma unica vez fora do loop: OrderStatus::closureCodes() e'
        // uma query, e mapSummary() e' chamado por OS da pagina — chamar dentro
        // do map() vira N+1 (pego pelo teste de contagem de queries da listagem).
        $closureCodes = OrderStatus::closureCodes();

        $paginator->setCollection(
            $orders->map(
                fn (Order $order): array => $this->mapSummary(
                    $order,
                    $budgetByOrderId[(int) $order->id] ?? null,
                    $receivableByOrderId[(int) $order->id] ?? null,
                    $nextStepsByStatusCode[trim((string) ($order->status ?? ''))] ?? [],
                    $closureCodes
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
            $closureCodes = OrderStatus::closureCodes();

            if ($closureCodes === []) {
                return;
            }

            // A listagem operacional inicial deve mostrar toda OS que ainda tem
            // algum vinculo em aberto com a assistencia — equipamento ainda na
            // loja OU pagamento pendente. A OS so e' considerada encerrada de
            // fato quando os.status literalmente esta em OrderStatus::closureCodes()
            // (decisao explicita do usuario, 2026-07-12). NAO filtrar por
            // os.status_final_pendente_pagamento aqui: esse campo so guarda o
            // encerramento que *vai* ser aplicado quando o saldo for quitado —
            // uma OS "Entregue - Pendencia Financeira" (nao esta em closureCodes)
            // continua precisando de acao (cobranca) e deve seguir aparecendo.
            $query->whereNotIn('os.status', $closureCodes);
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

        $file = $this->resolveManagedPhotoFile((string) ($photo->arquivo ?? ''));

        if (! is_array($file)) {
            $file = $this->resolveLegacyPhotoFile((string) ($photo->arquivo ?? ''), (string) ($photo->tipo ?? ''));
        }
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

    /**
     * @return array{absolute_path:string, relative_path:string, filename:string, mime_type:string}|null
     */
    private function resolveManagedPhotoFile(string $arquivo): ?array
    {
        $relative = $this->normalizeStoredPath($arquivo);
        if ($relative === '') {
            return null;
        }

        if (! Storage::disk('local')->exists($relative)) {
            return null;
        }

        $mimeType = trim((string) Storage::disk('local')->mimeType($relative));

        return [
            'absolute_path' => Storage::disk('local')->path($relative),
            'relative_path' => $relative,
            'filename' => basename($relative),
            'mime_type' => $mimeType !== '' ? $mimeType : $this->inferMimeType($relative),
        ];
    }

    /**
     * @return array{absolute_path:string, relative_path:string, filename:string, mime_type:string}|null
     */
    private function resolveManagedDocumentFile(string $arquivo, int $orderId): ?array
    {
        $relative = $this->normalizeStoredPath($arquivo);
        $allowedPrefix = 'private/os_documentos/' . $orderId . '/';

        if (
            $orderId <= 0
            || $relative === ''
            || str_contains($relative, '..')
            || ! str_starts_with($relative, $allowedPrefix)
            || ! Storage::disk('local')->exists($relative)
        ) {
            return null;
        }

        $mimeType = trim((string) Storage::disk('local')->mimeType($relative));

        return [
            'absolute_path' => Storage::disk('local')->path($relative),
            'relative_path' => $relative,
            'filename' => basename($relative),
            'mime_type' => $mimeType !== '' ? $mimeType : $this->inferMimeType($relative),
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

        $file = $this->resolveManagedDocumentFile((string) ($document->arquivo ?? ''), $orderId)
            ?? $this->resolveLegacyDocumentFile((string) ($document->arquivo ?? ''));
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

    /**
     * @param array<int, UploadedFile> $uploadedPhotos
     * @return array<int, int>
     */
    private function storeOrderPhotos(Order $order, array $uploadedPhotos, string $tipo = 'recepcao'): array
    {
        $files = array_values(array_filter(
            $uploadedPhotos,
            static fn ($file): bool => $file instanceof UploadedFile && $file->isValid()
        ));

        if ($files === []) {
            return [];
        }

        $directory = 'private/os/' . (int) $order->id;
        $createdPhotoIds = [];

        foreach ($files as $index => $file) {
            $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg'));
            $filename = sprintf(
                'os_%d_%s_%02d.%s',
                (int) $order->id,
                now()->format('YmdHisv'),
                $index + 1,
                $extension
            );

            Storage::disk('local')->putFileAs($directory, $file, $filename);

            $photo = OrderPhoto::query()->create([
                'os_id' => (int) $order->id,
                'tipo' => $tipo !== '' ? $tipo : 'recepcao',
                'arquivo' => $directory . '/' . $filename,
                'created_at' => now(),
            ]);

            $createdPhotoIds[] = (int) $photo->id;
        }

        if ($createdPhotoIds !== []) {
            $this->orderEventService->record(
                (int) $order->id,
                OrderEvent::CATEGORIA_REGISTRO,
                OrderEvent::TIPO_FOTOS_ADICIONADAS,
                'Fotos adicionadas',
                sprintf('%d foto(s) anexada(s) à OS.', count($createdPhotoIds)),
                [
                    'quantidade' => count($createdPhotoIds),
                    'foto_ids' => $createdPhotoIds,
                    'tipo' => $tipo !== '' ? $tipo : 'recepcao',
                ]
            );
        }

        return $createdPhotoIds;
    }

    public function updateStatus(
        int $orderId,
        User $actor,
        ?string $newStatus = null,
        ?string $observacao = null,
        ?string $diagnosticoTecnico = null,
        ?string $solucaoAplicada = null,
        bool $comunicarCliente = false,
        bool $viaClosureFlow = false
    ): array {
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

        $previousStatus = trim((string) ($order->status ?? ''));

        // Sem status de destino informado: mantém a etapa atual (usado quando o
        // técnico só quer salvar diagnóstico/solução, sem avançar o fluxo).
        $newStatus = $newStatus !== null && trim($newStatus) !== '' ? trim($newStatus) : $previousStatus;

        $statusRow = OrderStatus::activeByCode($newStatus);
        if (! $statusRow instanceof OrderStatus) {
            return [
                'result' => 'invalid_status',
            ];
        }

        $now = Carbon::now();
        $statusChanged = $previousStatus !== '' && $newStatus !== $previousStatus;

        // Regra de projeto (ver skill sistema-erp-os-fluxo-fechamento): os 3
        // status que de fato encerram a OS (OrderStatus::closureCodes()) só
        // podem ser aplicados pelo fluxo de baixa (OrderClosureService::close(),
        // que chama este método com viaClosureFlow=true). Isso garante que toda
        // OS "fechada" passou pela reconciliacao financeira (titulo/movimento)
        // e pelo calculo correto de pendencia — nunca via "Alterar status" ou
        // edicao direta da OS.
        if ($statusChanged && ! $viaClosureFlow && in_array($newStatus, OrderStatus::closureCodes(), true)) {
            return [
                'result' => 'closure_status_requires_baixa_flow',
                'status_atual' => $previousStatus,
            ];
        }

        // Regra de projeto (mesma skill): uma OS que JÁ está fechada (status em
        // closureCodes(): equipamento nao esta mais de posse da assistencia) nao
        // pode ter o status alterado de forma facilitada — isso indicaria
        // erroneamente que o equipamento voltou. A unica forma de tirar a OS
        // desse estado e' cancelar a baixa (OrderClosureService::cancelClosure(),
        // que reverte o status diretamente, sem passar por este método).
        if ($statusChanged && ! $viaClosureFlow && in_array($previousStatus, OrderStatus::closureCodes(), true)) {
            return [
                'result' => 'order_is_closed',
                'status_atual' => $previousStatus,
            ];
        }

        // Só permite mover para uma etapa de destino prevista no catálogo de transições.
        // Se a origem não possui transições cadastradas, mantém o comportamento permissivo.
        // Exceção: a baixa da OS já restringe o destino aos únicos 3 status de
        // OrderStatus::closureCodes() e precisa poder fechar a OS a partir de
        // qualquer etapa aberta — por isso pede para pular esta validação.
        if ($statusChanged && ! $viaClosureFlow) {
            $allowed = $this->allowedTransitionCodes($previousStatus);
            if ($allowed !== [] && ! in_array($newStatus, $allowed, true)) {
                return [
                    'result' => 'invalid_transition',
                    'status_atual' => $previousStatus,
                    'proximas_etapas' => $this->mapNextStatusOptions($previousStatus),
                ];
            }
        }

        $estadoFluxo = trim((string) ($statusRow->estado_fluxo_padrao ?? '')) ?: 'em_atendimento';

        DB::transaction(function () use (
            $orderId,
            $previousStatus,
            $newStatus,
            $statusChanged,
            $estadoFluxo,
            $actor,
            $observacao,
            $diagnosticoTecnico,
            $solucaoAplicada,
            $now
        ): void {
            $updateData = ['updated_at' => $now];

            if ($statusChanged) {
                $updateData['status'] = $newStatus;
                $updateData['estado_fluxo'] = $estadoFluxo;
                $updateData['status_atualizado_em'] = $now;
            }

            if ($diagnosticoTecnico !== null) {
                $updateData['diagnostico_tecnico'] = trim($diagnosticoTecnico);
            }

            if ($solucaoAplicada !== null) {
                $updateData['solucao_aplicada'] = trim($solucaoAplicada);
            }

            Order::query()->whereKey($orderId)->update($updateData);

            if ($statusChanged) {
                $this->createStatusHistory($orderId, $previousStatus, $newStatus, $estadoFluxo, $actor, $observacao, $now);
            }

            if ($diagnosticoTecnico !== null || $solucaoAplicada !== null) {
                $camposTecnicos = array_filter([
                    'diagnostico_tecnico' => $diagnosticoTecnico !== null ? trim($diagnosticoTecnico) : null,
                    'solucao_aplicada' => $solucaoAplicada !== null ? trim($solucaoAplicada) : null,
                ], static fn ($value): bool => $value !== null);

                $this->orderEventService->record(
                    $orderId,
                    OrderEvent::CATEGORIA_REGISTRO,
                    OrderEvent::TIPO_DADOS_TECNICOS_ATUALIZADOS,
                    'Diagnóstico/solução registrados',
                    null,
                    $camposTecnicos,
                    (int) $actor->id,
                    OrderEvent::ORIGEM_USUARIO,
                    $now
                );
            }
        });

        if ($statusChanged && (bool) ($statusRow->status_final ?? false)) {
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

        if ($statusChanged && $updatedOrder instanceof Order) {
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

        if ($statusChanged && $comunicarCliente && $updatedOrder instanceof Order) {
            $this->sendStatusChangeClientNotification($updatedOrder, $newStatus, $observacao);
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

    public function addProcedureEntry(int $orderId, User $actor, string $descricao): array
    {
        $order = Order::query()->find($orderId);

        if (! $order instanceof Order) {
            return ['result' => 'not_found'];
        }

        if (! $this->canAccessOrder($actor, $order)) {
            return ['result' => 'forbidden'];
        }

        $descricao = trim($descricao);
        if ($descricao === '') {
            return ['result' => 'empty_description'];
        }

        $procedure = OrderProcedureHistory::query()->create([
            'os_id' => $orderId,
            'descricao' => $descricao,
            'usuario_id' => (int) $actor->id,
            'created_at' => Carbon::now(),
        ]);

        $this->orderEventService->record(
            $orderId,
            OrderEvent::CATEGORIA_REGISTRO,
            OrderEvent::TIPO_PROCEDIMENTO_REGISTRADO,
            'Procedimento registrado',
            $descricao,
            ['procedimento_historico_id' => (int) $procedure->id],
            (int) $actor->id
        );

        logger()->info('[API V1][ORDERS] Procedimento registrado', [
            'order_id' => $orderId,
            'user_id' => $actor->id,
        ]);

        $updatedOrder = $this->detailQuery()->find($orderId);

        return [
            'result' => 'ok',
            'order' => $updatedOrder instanceof Order ? $this->mapDetail($updatedOrder) : null,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, UploadedFile> $uploadedPhotos
     * @return array<string, mixed>
     */
    public function createOrder(User $actor, array $attributes, array $uploadedPhotos = []): array
    {
        $clientId = (int) ($attributes['cliente_id'] ?? 0);
        $equipmentId = (int) ($attributes['equipamento_id'] ?? 0);
        $shouldSendOpeningPdf = (bool) ($attributes['enviar_pdf_cliente'] ?? false);

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

        $entryChecklistPlan = null;
        if ($this->hasEntryChecklistPayload($attributes)) {
            $entryChecklistPlan = $this->buildEntryChecklistSyncPlan($equipmentId, (array) $attributes['checklist_entrada']);
            if (($entryChecklistPlan['result'] ?? 'error') !== 'ok') {
                return [
                    'result' => (string) ($entryChecklistPlan['result'] ?? 'entry_checklist_invalid'),
                ];
            }
        }

        $order = DB::transaction(function () use ($payload, $actor, $statusCode, $estadoFluxo, $now, $entryChecklistPlan): Order {
            /** @var Order $order */
            $order = Order::query()->create($payload);

            $this->createStatusHistory(
                (int) $order->id,
                null,
                $statusCode,
                $estadoFluxo,
                $actor,
                'OS criada pelo backend central.',
                $now,
                eventTipo: OrderEvent::TIPO_OS_CRIADA
            );

            if (is_array($entryChecklistPlan)) {
                $this->applyEntryChecklistSyncPlan((int) $order->id, $entryChecklistPlan, $now);
            }

            return $order;
        });

        if ($uploadedPhotos !== []) {
            $this->storeOrderPhotos($order, $uploadedPhotos);
        }

        $openingDocument = $this->generateOpeningDocument($order, $actor);
        $createdOrder = $this->detailQuery()->find((int) $order->id);
        $openingDelivery = $shouldSendOpeningPdf
            ? $this->sendOpeningDocumentToClient(
                $createdOrder instanceof Order ? $createdOrder : $order,
                $openingDocument
            )
            : [
                'requested' => false,
                'sent' => false,
                'channel' => null,
                'message' => '',
            ];

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

            try {
                broadcast(new \App\Events\OrderCreated([
                    'id'                   => (int) $createdOrder->id,
                    'numero_os'            => (string) ($createdOrder->numero_os ?? ''),
                    'cliente_nome'         => (string) ($createdOrder->client?->nome_razao ?? ''),
                    'cliente_telefone'     => (string) ($createdOrder->client?->telefone1 ?? ''),
                    'equipamento_resumo'   => (string) ($createdOrder->equipment?->resumo_tecnico ?? ''),
                    'equipamento_serie'    => (string) ($createdOrder->equipment?->numero_serie ?? ''),
                    'status_nome'          => (string) ($createdOrder->statusCatalog?->nome ?? ''),
                    'status_cor'           => (string) ($createdOrder->statusCatalog?->cor ?? '#64748b'),
                    'proximas_etapas'      => $this->mapNextStatusOptions($statusCode),
                    'estado_fluxo'         => (string) ($createdOrder->estado_fluxo ?? ''),
                    'data_entrada'         => $createdOrder->data_entrada?->format('d/m/Y') ?? '',
                ]));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Falha ao broadcast OrderCreated: ' . $e->getMessage());
            }
        }

        return [
            'result' => 'ok',
            'order' => $createdOrder instanceof Order ? $this->mapDetail($createdOrder) : null,
            'opening_document' => $this->sanitizeOpeningDocumentFeedback($openingDocument),
            'opening_delivery' => $openingDelivery,
        ];
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<int, UploadedFile> $uploadedPhotos
     * @return array<string, mixed>
     */
    public function updateOrder(int $orderId, User $actor, array $attributes, array $uploadedPhotos = []): array
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
        $entryChecklistPlan = null;

        if ($this->hasEntryChecklistPayload($attributes)) {
            $entryChecklistPlan = $this->buildEntryChecklistSyncPlan($equipmentId, (array) $attributes['checklist_entrada']);
            if (($entryChecklistPlan['result'] ?? 'error') !== 'ok') {
                return [
                    'result' => (string) ($entryChecklistPlan['result'] ?? 'entry_checklist_invalid'),
                ];
            }
        }

        if ($statusChanged) {
            $statusRow = OrderStatus::activeByCode((string) $payload['status']);
            if (! $statusRow instanceof OrderStatus) {
                return [
                    'result' => 'invalid_status',
                ];
            }

            // Regra de projeto (ver skill sistema-erp-os-fluxo-fechamento): a
            // edição genérica da OS nunca pode encerrar o atendimento — os 3
            // status de OrderStatus::closureCodes() só são aplicáveis pelo
            // fluxo de baixa (OrderClosureService::close()).
            if (in_array((string) $payload['status'], OrderStatus::closureCodes(), true)) {
                return [
                    'result' => 'closure_status_requires_baixa_flow',
                ];
            }

            // Idem: uma OS ja fechada nao pode ter o status alterado por aqui —
            // so cancelando a baixa (OrderClosureService::cancelClosure()).
            if ($previousStatus !== (string) $payload['status']
                && in_array($previousStatus, OrderStatus::closureCodes(), true)) {
                return [
                    'result' => 'order_is_closed',
                ];
            }

            if (! array_key_exists('estado_fluxo', $payload)) {
                $payload['estado_fluxo'] = trim((string) ($statusRow->estado_fluxo_padrao ?? 'em_atendimento'));
            }

            $payload['status_atualizado_em'] = Carbon::now()->toDateTimeString();
            $estadoFluxo = (string) ($payload['estado_fluxo'] ?? $estadoFluxo);
        }

        // Diff real dos campos alterados (antes/depois) para a timeline de
        // eventos — exclui os campos de status, que ja viram evento proprio
        // via createStatusHistory, e o carimbo interno de atualizacao.
        $camposAlterados = [];
        foreach ($payload as $campo => $valorNovo) {
            if (in_array($campo, ['status', 'estado_fluxo', 'status_atualizado_em'], true)) {
                continue;
            }
            $valorAnterior = $order->getAttribute($campo);
            if ((string) $valorAnterior !== (string) $valorNovo) {
                $camposAlterados[$campo] = [
                    'antes' => $valorAnterior,
                    'depois' => $valorNovo,
                ];
            }
        }

        if ($payload !== [] || is_array($entryChecklistPlan)) {
            DB::transaction(function () use ($orderId, $payload, $statusChanged, $previousStatus, $actor, $estadoFluxo, $entryChecklistPlan, $camposAlterados): void {
                $now = Carbon::now();

                Order::query()
                    ->whereKey($orderId)
                    ->update(array_merge($payload, ['updated_at' => $now]));

                if ($statusChanged) {
                    $this->createStatusHistory(
                        $orderId,
                        $previousStatus !== '' ? $previousStatus : null,
                        (string) $payload['status'],
                        $estadoFluxo,
                        $actor,
                        'OS atualizada pelo backend central.',
                        $now
                    );
                }

                if ($camposAlterados !== []) {
                    $this->orderEventService->record(
                        $orderId,
                        OrderEvent::CATEGORIA_REGISTRO,
                        OrderEvent::TIPO_OS_ATUALIZADA,
                        'OS atualizada',
                        'Campos alterados: ' . implode(', ', array_keys($camposAlterados)) . '.',
                        ['campos' => $camposAlterados],
                        (int) $actor->id,
                        OrderEvent::ORIGEM_USUARIO,
                        $now
                    );
                }

                if (is_array($entryChecklistPlan)) {
                    $this->applyEntryChecklistSyncPlan($orderId, $entryChecklistPlan, $now);
                }
            });
        }

        if ($uploadedPhotos !== []) {
            $this->storeOrderPhotos($order, $uploadedPhotos);
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
            'equipment.type',
            'equipment.brand',
            'equipment.model',
            'technician',
            'statusCatalog',
            'statusHistory' => static function ($query): void {
                $query
                    ->with('user')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(5);
            },
            'procedureHistory' => static function ($query): void {
                $query
                    ->with('user')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(20);
            },
            'events' => static function ($query): void {
                $query
                    ->with('user')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->limit(200);
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
     * @param array<int, array<string, mixed>>|null $nextStatusOptions
     * @param array<int, string>|null $closureCodes
     * @return array<string, mixed>
     */
    private function mapSummary(
        Order $order,
        ?array $budget = null,
        ?array $receivable = null,
        ?array $nextStatusOptions = null,
        ?array $closureCodes = null
    ): array
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
            // Bug corrigido em 2026-07-08: "Baixa" nao deve sumir das Ações so
            // porque estado_fluxo='encerrado' (Irreparável/Reparo Recusado tambem
            // usam esse estado_fluxo_padrao sem serem status de fechamento de
            // verdade) — usar is_encerrada (grupo_macro='encerrado', os 3 reais).
            'is_encerrada' => in_array((string) ($order->status ?? ''), $closureCodes ?? OrderStatus::closureCodes(), true),
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
            'proximas_etapas' => array_values($nextStatusOptions ?? []),
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

            return ['estado' => 'concluido_atrasado', 'label' => 'Concluída, atraso', 'dias' => $diasAtraso];
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
        $orderId = (int) ($order->id ?? 0);
        $financeiroResumo = $this->resolveDetailFinancialSummary(
            $orderId,
            (string) ($order->forma_pagamento ?? '')
        );
        $custoAuditoria = $this->resolveDetailCostAudit($orderId);

        return [
            'id' => $orderId,
            'numero_os' => (string) ($order->numero_os ?? ''),
            'numero_os_legado' => (string) ($order->numero_os_legado ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
            'cliente' => $this->mapClient($order->client),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
            'equipamento_resumo_tecnico' => (string) ($order->equipment?->resumo_tecnico ?? ''),
            'equipamento_numero_serie' => (string) ($order->equipment?->numero_serie ?? ''),
            'equipamento_tipo_nome' => (string) ($order->equipment?->type?->nome ?? ''),
            'equipamento_resumo_curto' => $this->resolveEquipmentShortSummary(
                $order->equipment?->type?->nome,
                $order->equipment?->brand?->nome,
                $order->equipment?->model?->nome,
                $order->equipment?->resumo_tecnico
            ),
            'equipamento' => $this->mapEquipment($order->equipment),
            'tecnico_id' => (int) ($order->tecnico_id ?? 0),
            'tecnico' => $this->mapTechnician($order->technician),
            'status' => (string) ($order->status ?? ''),
            'status_nome' => (string) ($statusRow?->nome ?? ''),
            'status_cor' => (string) ($statusRow?->cor ?? ''),
            'status_grupo_macro' => (string) ($statusRow?->grupo_macro ?? ''),
            // OS encerrada = status num dos 3 codigos de fechamento
            // (skill sistema-erp-os-fluxo-fechamento). A UI usa para bloquear
            // "Alterar status" e oferecer "Cancelar baixa".
            'is_encerrada' => in_array((string) ($order->status ?? ''), OrderStatus::closureCodes(), true),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'prioridade' => (string) ($order->prioridade ?? ''),
            'status_atualizado_em' => $this->formatDateTime($order->status_atualizado_em ?? null),
            'relato_cliente' => (string) ($order->relato_cliente ?? ''),
            'diagnostico_tecnico' => (string) ($order->diagnostico_tecnico ?? ''),
            'solucao_aplicada' => (string) ($order->solucao_aplicada ?? ''),
            'procedimentos_executados' => (string) ($order->procedimentos_executados ?? ''),
            'acessorios' => (string) ($order->acessorios ?? ''),
            'forma_pagamento' => (string) ($order->forma_pagamento ?? ''),
            'forma_pagamento_resolvida' => (string) ($financeiroResumo['forma_pagamento_label'] ?? ''),
            'financeiro_resumo' => $financeiroResumo,
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
            'procedimentos_historico' => $this->mapProcedureHistoryCollection($order->procedureHistory),
            'eventos' => $this->mapEventCollection($order->events, $order->documents),
            'status_disponiveis' => $this->mapStatusOptions(),
            'proximas_etapas' => $this->mapNextStatusOptions((string) ($order->status ?? '')),
            'fotos' => $this->mapPhotoCollection($order->photos, (int) ($order->id ?? 0)),
            'equipamento_foto' => $this->mapEquipmentPrincipalPhoto($order->equipment),
            'documentos' => $this->mapDocumentCollection($order->documents, (int) ($order->id ?? 0)),
            'orcamento' => $this->mapLinkedBudget((int) ($order->id ?? 0)),
            'custo_auditoria' => $custoAuditoria,
            'checklist' => $this->mapEntryChecklist((int) ($order->id ?? 0)),
            'checklist_modelo_entrada' => $this->entryChecklistModelForEquipmentType((int) ($order->equipment?->tipo_id ?? 0)),
        ];
    }

    /**
     * Resumo financeiro autoritativo para a aba Valores da OS.
     *
     * A coluna legada `os.forma_pagamento` pode ficar vazia porque a baixa
     * moderna aceita múltiplos recebimentos. A forma exibida deve vir dos
     * movimentos financeiros efetivamente registrados, que são a fonte de
     * verdade para fluxo de caixa, DRE de caixa e auditoria.
     *
     * @return array<string, mixed>
     */
    private function resolveDetailFinancialSummary(int $orderId, string $legacyPaymentMethod): array
    {
        $empty = [
            'titulo_id' => null,
            'valor_titulo' => 0.0,
            'valor_recebido' => 0.0,
            'saldo_aberto' => 0.0,
            'status' => null,
            'total_movimentos' => 0,
            'forma_pagamento' => trim($legacyPaymentMethod),
            'forma_pagamento_label' => $this->paymentMethodLabel($legacyPaymentMethod),
            'formas_pagamento' => [],
        ];

        if ($orderId <= 0) {
            return $empty;
        }

        $titulo = Financeiro::query()
            ->where('os_id', $orderId)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first(['id', 'valor', 'status', 'forma_pagamento']);

        if (! $titulo instanceof Financeiro) {
            return $empty;
        }

        $movimentos = FinanceiroMovimento::query()
            ->where('financeiro_id', (int) $titulo->id)
            ->orderBy('id')
            ->get(['valor_movimento', 'forma_pagamento']);

        $valorTitulo = round((float) ($titulo->valor ?? 0), 2);
        $valorRecebido = round((float) $movimentos->sum('valor_movimento'), 2);
        $formas = $movimentos
            ->pluck('forma_pagamento')
            ->map(static fn ($forma): string => trim((string) $forma))
            ->filter(static fn (string $forma): bool => $forma !== '')
            ->unique()
            ->values()
            ->all();

        $fallbackForma = trim((string) ($titulo->forma_pagamento ?? '')) !== ''
            ? (string) $titulo->forma_pagamento
            : trim($legacyPaymentMethod);

        $formaPagamento = count($formas) === 1 ? $formas[0] : (count($formas) > 1 ? 'multiplo' : $fallbackForma);
        $formaPagamentoLabel = $this->paymentMethodLabel($formaPagamento, $formas);

        return [
            'titulo_id' => (int) $titulo->id,
            'valor_titulo' => $valorTitulo,
            'valor_recebido' => $valorRecebido,
            'saldo_aberto' => max(0.0, round($valorTitulo - $valorRecebido, 2)),
            'status' => (string) ($titulo->status ?? ''),
            'total_movimentos' => $movimentos->count(),
            'forma_pagamento' => $formaPagamento,
            'forma_pagamento_label' => $formaPagamentoLabel,
            'formas_pagamento' => array_map(fn (string $forma): array => [
                'codigo' => $forma,
                'label' => $this->paymentMethodLabel($forma),
            ], $formas),
        ];
    }

    /**
     * Auditoria de custo da OS para diferenciar margem real (estoque) de custo
     * previsto no orçamento. O custo real continua vindo somente de saída de
     * estoque vinculada à OS; itens orçados sem movimentação viram alerta
     * operacional, não custo contábil inventado.
     *
     * @return array<string, mixed>
     */
    private function resolveDetailCostAudit(int $orderId): array
    {
        $empty = [
            'orcamento_id' => null,
            'orcamento_numero' => '',
            'orcamento_status' => '',
            'valor_pecas_orcado' => 0.0,
            'custo_pecas_previsto' => 0.0,
            'custo_pecas_real' => 0.0,
            'pecas_orcadas' => 0,
            'saidas_estoque' => 0,
            'pendencia_baixa_estoque' => false,
            'mensagem' => '',
        ];

        if ($orderId <= 0 || ! Schema::hasTable('orcamentos') || ! Schema::hasTable('orcamento_itens')) {
            return $empty;
        }

        $budget = Budget::query()
            ->where('os_id', $orderId)
            ->where(static function (Builder $query): void {
                $query
                    ->whereIn('status', [Budget::STATUS_APPROVED, Budget::STATUS_CONVERTED])
                    ->orWhereNotNull('aprovado_em');
            })
            ->orderByDesc('id')
            ->first(['id', 'numero', 'status']);

        if (! $budget instanceof Budget) {
            return $empty;
        }

        $budgetPieces = DB::table('orcamento_itens')
            ->where('orcamento_id', (int) $budget->id)
            ->where('tipo_item', 'peca')
            ->selectRaw('COUNT(*) as total_itens')
            ->selectRaw('COALESCE(SUM(total), 0) as valor_total')
            ->selectRaw('COALESCE(SUM(COALESCE(preco_custo_referencia, 0) * COALESCE(quantidade, 1)), 0) as custo_previsto')
            ->first();

        $valorPecasOrcado = round((float) ($budgetPieces->valor_total ?? 0), 2);
        $custoPecasPrevisto = round((float) ($budgetPieces->custo_previsto ?? 0), 2);
        $pecasOrcadas = (int) ($budgetPieces->total_itens ?? 0);

        $custoPecasReal = 0.0;
        $saidasEstoque = 0;

        if (Schema::hasTable('movimentacoes') && Schema::hasTable('pecas')) {
            $stockOut = DB::table('movimentacoes')
                ->join('pecas', 'pecas.id', '=', 'movimentacoes.peca_id')
                ->where('movimentacoes.os_id', $orderId)
                ->where('movimentacoes.tipo', 'saida')
                ->selectRaw('COUNT(*) as total_saidas')
                ->selectRaw('COALESCE(SUM(movimentacoes.quantidade * pecas.preco_custo), 0) as custo_real')
                ->first();

            $custoPecasReal = round((float) ($stockOut->custo_real ?? 0), 2);
            $saidasEstoque = (int) ($stockOut->total_saidas ?? 0);
        }

        $pendenciaBaixaEstoque = $pecasOrcadas > 0 && $valorPecasOrcado > 0 && $saidasEstoque === 0;

        return [
            'orcamento_id' => (int) $budget->id,
            'orcamento_numero' => (string) ($budget->numero ?? ''),
            'orcamento_status' => (string) ($budget->status ?? ''),
            'valor_pecas_orcado' => $valorPecasOrcado,
            'custo_pecas_previsto' => $custoPecasPrevisto,
            'custo_pecas_real' => $custoPecasReal,
            'pecas_orcadas' => $pecasOrcadas,
            'saidas_estoque' => $saidasEstoque,
            'pendencia_baixa_estoque' => $pendenciaBaixaEstoque,
            'mensagem' => $pendenciaBaixaEstoque
                ? 'Há peça aprovada no orçamento, mas nenhuma saída de estoque vinculada a esta OS. Se a peça saiu do estoque da assistência, registre a movimentação para a margem real ficar correta.'
                : '',
        ];
    }

    /**
     * @param array<int, string> $formas
     */
    private function paymentMethodLabel(?string $formaPagamento, array $formas = []): string
    {
        $formaPagamento = trim((string) ($formaPagamento ?? ''));
        if ($formaPagamento === '') {
            return '';
        }

        $labels = collect(Financeiro::formaPagamentoOptions())
            ->mapWithKeys(static fn (array $option): array => [(string) $option['value'] => (string) $option['label']])
            ->all();

        if ($formaPagamento === 'multiplo') {
            $labelList = collect($formas)
                ->map(static fn (string $forma): string => $labels[$forma] ?? ucfirst(str_replace('_', ' ', $forma)))
                ->filter()
                ->values()
                ->all();

            return $labelList !== []
                ? 'Múltiplas formas (' . implode(', ', $labelList) . ')'
                : 'Múltiplas formas';
        }

        return $labels[$formaPagamento] ?? ucfirst(str_replace('_', ' ', $formaPagamento));
    }

    /**
     * Etapas de destino permitidas a partir do status atual (catálogo de transições).
     *
     * @return array<int, array<string, mixed>>
     */
    private function mapNextStatusOptions(string $currentCode): array
    {
        $currentCode = trim($currentCode);
        if ($currentCode === '') {
            return [];
        }

        $catalog = $this->loadStatusWorkflowCatalog();
        if ($catalog['status_by_code'] === [] || $catalog['transitions_by_origin'] === []) {
            return [];
        }

        $current = $catalog['status_by_code'][$currentCode] ?? null;
        if (! is_array($current)) {
            return [];
        }

        return $this->mapNextStatusOptionsFromCatalog(
            (int) ($current['id'] ?? 0),
            $catalog['status_by_id'],
            $catalog['transitions_by_origin']
        );
    }

    /**
     * @param array<int, string> $statusCodes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function resolveNextStatusOptionsMap(array $statusCodes): array
    {
        $statusCodes = array_values(array_unique(array_filter(
            array_map(static fn (mixed $statusCode): string => trim((string) $statusCode), $statusCodes),
            static fn (string $statusCode): bool => $statusCode !== ''
        )));

        if ($statusCodes === []) {
            return [];
        }

        $catalog = $this->loadStatusWorkflowCatalog();
        if ($catalog['status_by_code'] === [] || $catalog['transitions_by_origin'] === []) {
            return [];
        }

        $optionsByStatus = [];

        foreach ($statusCodes as $statusCode) {
            $current = $catalog['status_by_code'][$statusCode] ?? null;
            if (! is_array($current)) {
                continue;
            }

            $optionsByStatus[$statusCode] = $this->mapNextStatusOptionsFromCatalog(
                (int) ($current['id'] ?? 0),
                $catalog['status_by_id'],
                $catalog['transitions_by_origin']
            );
        }

        return $optionsByStatus;
    }

    /**
     * Códigos de status de destino permitidos a partir do status informado.
     *
     * @return array<int, string>
     */
    private function allowedTransitionCodes(string $currentCode): array
    {
        return array_values(array_filter(array_map(
            static fn (array $option): string => (string) ($option['codigo'] ?? ''),
            $this->mapNextStatusOptions($currentCode)
        ), static fn (string $code): bool => $code !== ''));
    }

    /**
     * Foto de perfil do equipamento (equipamentos_fotos.is_principal), para a coluna lateral.
     *
     * @return array<string, mixed>|null
     */
    private function mapEquipmentPrincipalPhoto(?Equipment $equipment): ?array
    {
        if (! $equipment instanceof Equipment || (int) ($equipment->id ?? 0) <= 0 || ! Schema::hasTable('equipamentos_fotos')) {
            return null;
        }

        $equipmentId = (int) $equipment->id;

        $photo = DB::table('equipamentos_fotos')
            ->where('equipamento_id', $equipmentId)
            ->orderByDesc('is_principal')
            ->orderBy('id')
            ->first(['id', 'arquivo', 'is_principal']);

        if ($photo === null) {
            return null;
        }

        return [
            'id' => (int) $photo->id,
            'equipamento_id' => $equipmentId,
            'is_principal' => (bool) ($photo->is_principal ?? false),
            'nome_arquivo' => $photo->arquivo !== null ? basename((string) $photo->arquivo) : '',
            'url' => route('api.v1.equipments.photos.show', [
                'equipment' => $equipmentId,
                'photo' => (int) $photo->id,
            ], false),
        ];
    }

    /**
     * Orçamento vinculado à OS (o mais recente), resumido para exibição.
     *
     * @return array<string, mixed>|null
     */
    private function mapLinkedBudget(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $budget = Budget::query()
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first();

        if (! $budget instanceof Budget) {
            return null;
        }

        $status = (string) ($budget->status ?? '');

        return [
            'id' => (int) ($budget->id ?? 0),
            'numero' => (string) ($budget->numero ?? ''),
            'versao' => (int) ($budget->versao ?? 1),
            'tipo_orcamento' => (string) ($budget->tipo_orcamento ?? ''),
            'status' => $status,
            'status_label' => $this->humanizeBudgetStatus($status),
            'aprovado' => $status === Budget::STATUS_APPROVED || ($budget->aprovado_em ?? null) !== null,
            'subtotal' => $this->normalizeDecimalString($budget->subtotal ?? null),
            'desconto' => $this->normalizeDecimalString($budget->desconto ?? null),
            'total' => $this->normalizeDecimalString($budget->total ?? null),
            'validade_data' => $this->formatDate($budget->validade_data ?? null),
            'enviado_em' => $this->formatDateTime($budget->enviado_em ?? null),
            'aprovado_em' => $this->formatDateTime($budget->aprovado_em ?? null),
            'created_at' => $this->formatDateTime($budget->created_at ?? null),
        ];
    }

    /**
     * Modelo operacional do checklist de entrada por tipo de equipamento.
     *
     * Este método é deliberadamente protegido pelo módulo de OS nos controllers:
     * o usuário operacional precisa consultar o checklist vigente para abrir/editar
     * uma OS, mas não recebe permissão para configurar o modelo em Conhecimento.
     *
     * @return array<string, mixed>|null
     */
    public function entryChecklistModelForEquipmentType(int $equipmentTypeId): ?array
    {
        $modelo = $this->resolveEntryChecklistModelForEquipmentType($equipmentTypeId);

        return $modelo instanceof ChecklistModelo ? $this->mapEntryChecklistModel($modelo) : null;
    }

    /**
     * Checklist de entrada (execução mais recente) da OS.
     *
     * @return array<string, mixed>|null
     */
    private function mapEntryChecklist(int $orderId): ?array
    {
        if ($orderId <= 0 || ! Schema::hasTable('checklist_execucoes')) {
            return null;
        }

        $execution = DB::table('checklist_execucoes')
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first();

        if ($execution === null) {
            return null;
        }

        return [
            'id' => (int) $execution->id,
            'checklist_tipo_id' => (int) ($execution->checklist_tipo_id ?? 0),
            'checklist_modelo_id' => (int) ($execution->checklist_modelo_id ?? 0),
            'tipo_equipamento_id' => (int) ($execution->tipo_equipamento_id ?? 0),
            'status' => (string) ($execution->status ?? ''),
            'total_itens' => (int) ($execution->total_itens ?? 0),
            'total_discrepancias' => (int) ($execution->total_discrepancias ?? 0),
            'resumo_texto' => trim((string) ($execution->resumo_texto ?? '')),
            'observacoes_estado' => trim((string) ($execution->observacoes_estado ?? '')),
            'concluido_em' => $this->formatDateTime($execution->concluido_em ?? null),
            'respostas' => $this->mapEntryChecklistResponses((int) $execution->id),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapEntryChecklistResponses(int $executionId): array
    {
        if ($executionId <= 0 || ! Schema::hasTable('checklist_respostas')) {
            return [];
        }

        return DB::table('checklist_respostas')
            ->where('checklist_execucao_id', $executionId)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $response): array => [
                'id' => (int) ($response->id ?? 0),
                'checklist_item_id' => (int) ($response->checklist_item_id ?? 0),
                'descricao_item' => trim((string) ($response->descricao_item ?? '')),
                'ordem' => (int) ($response->ordem ?? 0),
                'status' => trim((string) ($response->status ?? '')),
                'observacao' => trim((string) ($response->observacao ?? '')),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEntryChecklistModel(ChecklistModelo $modelo): array
    {
        return [
            'id' => (int) ($modelo->id ?? 0),
            'checklist_tipo_id' => (int) ($modelo->checklist_tipo_id ?? 0),
            'tipo_equipamento_id' => (int) ($modelo->tipo_equipamento_id ?? 0),
            'nome' => trim((string) ($modelo->nome ?? '')),
            'descricao' => trim((string) ($modelo->descricao ?? '')),
            'itens' => $modelo->itens
                ->filter(static fn ($item): bool => (bool) ($item->ativo ?? true))
                ->map(static fn ($item): array => [
                    'id' => (int) ($item->id ?? 0),
                    'descricao' => trim((string) ($item->descricao ?? '')),
                    'ordem' => (int) ($item->ordem ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    private function resolveEntryChecklistModelForEquipmentType(int $equipmentTypeId): ?ChecklistModelo
    {
        if ($equipmentTypeId <= 0
            || ! Schema::hasTable('checklist_tipos')
            || ! Schema::hasTable('checklist_modelos')
            || ! Schema::hasTable('checklist_itens')
        ) {
            return null;
        }

        $checklistType = ChecklistTipo::query()
            ->where('codigo', self::ENTRY_CHECKLIST_TYPE_CODE)
            ->where('ativo', 1)
            ->first();

        if (! $checklistType instanceof ChecklistTipo) {
            return null;
        }

        return ChecklistModelo::query()
            ->with(['itens' => static function ($query): void {
                $query
                    ->where('ativo', 1)
                    ->orderBy('ordem')
                    ->orderBy('id');
            }])
            ->where('checklist_tipo_id', (int) $checklistType->id)
            ->where('tipo_equipamento_id', $equipmentTypeId)
            ->where('ativo', 1)
            ->orderBy('ordem')
            ->orderBy('id')
            ->first();
    }

    private function humanizeBudgetStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return 'Sem status';
        }

        $map = [
            Budget::STATUS_DRAFT => 'Rascunho',
            Budget::STATUS_PENDING_SEND => 'Pendente de envio',
            Budget::STATUS_SENT => 'Enviado',
            Budget::STATUS_WAITING_REPLY => 'Aguardando resposta',
            Budget::STATUS_WAITING_PACKAGE => 'Aguardando pacote',
            Budget::STATUS_PACKAGE_APPROVED => 'Pacote aprovado',
            Budget::STATUS_PENDING => 'Pendente',
            Budget::STATUS_APPROVED => 'Aprovado',
            Budget::STATUS_RESEND => 'Reenviar orçamento',
            Budget::STATUS_PENDING_OS => 'Pendente abertura de OS',
        ];

        return $map[$status] ?? ucwords(str_replace('_', ' ', $status));
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
            'tipo_nome' => (string) ($equipment->type?->nome ?? ''),
            'marca_id' => (int) ($equipment->marca_id ?? 0),
            'marca_nome' => (string) ($equipment->brand?->nome ?? ''),
            'modelo_id' => (int) ($equipment->modelo_id ?? 0),
            'modelo_nome' => (string) ($equipment->model?->nome ?? ''),
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
     * Timeline unificada de eventos da OS (tabela os_eventos) — ver skill
     * sistema-erp-os-fluxo-fechamento e documentacao de eventos.
     *
     * @param iterable<OrderEvent> $events
     * @return array<int, array<string, mixed>>
     */
    private function mapEventCollection(iterable $events, iterable $documents = []): array
    {
        $items = [];
        $existingDocumentKeys = [];

        foreach ($events as $event) {
            if (! $event instanceof OrderEvent) {
                continue;
            }

            $dados = is_array($event->dados) ? $event->dados : null;
            $item = [
                'id' => (int) ($event->id ?? 0),
                'categoria' => (string) ($event->categoria ?? ''),
                'tipo' => (string) ($event->tipo ?? ''),
                'titulo' => (string) ($event->titulo ?? ''),
                'descricao' => $event->descricao !== null ? (string) $event->descricao : null,
                'dados' => $dados,
                'origem' => (string) ($event->origem ?? 'sistema'),
                'created_at' => $this->formatDateTime($event->created_at ?? null),
                'usuario_id' => (int) ($event->usuario_id ?? 0),
                'usuario' => $this->mapTechnician($event->user),
                '_sort_at' => $this->resolveTimelineSortAt($event->created_at ?? null),
            ];

            if ($item['categoria'] === OrderEvent::CATEGORIA_DOCUMENTO) {
                foreach ($this->extractDocumentEventKeys($dados) as $key) {
                    $existingDocumentKeys[$key] = true;
                }
            }

            $items[] = $item;
        }

        foreach ($documents as $document) {
            if (! $document instanceof OrderDocument) {
                continue;
            }

            $keys = $this->documentIdentityKeys($document);
            $alreadyTracked = false;

            foreach ($keys as $key) {
                if (isset($existingDocumentKeys[$key])) {
                    $alreadyTracked = true;
                    break;
                }
            }

            if ($alreadyTracked) {
                continue;
            }

            foreach ($keys as $key) {
                $existingDocumentKeys[$key] = true;
            }

            $items[] = $this->buildSyntheticDocumentTimelineEvent($document);
        }

        usort($items, static function (array $left, array $right): int {
            return ((int) ($right['_sort_at'] ?? 0)) <=> ((int) ($left['_sort_at'] ?? 0));
        });

        return array_map(static function (array $item): array {
            unset($item['_sort_at']);

            return $item;
        }, $items);
    }

    /**
     * @param array<string, mixed>|null $dados
     * @return array<int, string>
     */
    private function extractDocumentEventKeys(?array $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }

        $keys = [];
        $documentId = (int) ($dados['documento_id'] ?? 0);
        if ($documentId > 0) {
            $keys[] = 'id:' . $documentId;
        }

        $type = strtolower(trim((string) ($dados['tipo_documento'] ?? '')));
        $version = (int) ($dados['versao'] ?? 0);
        if ($type !== '' && $version > 0) {
            $keys[] = 'typev:' . $type . ':' . $version;
        }

        return $keys;
    }

    /**
     * @return array<int, string>
     */
    private function documentIdentityKeys(OrderDocument $document): array
    {
        $keys = [];
        $documentId = (int) ($document->id ?? 0);
        if ($documentId > 0) {
            $keys[] = 'id:' . $documentId;
        }

        $type = strtolower(trim((string) ($document->tipo_documento ?? '')));
        $version = (int) ($document->versao ?? 0);
        if ($type !== '' && $version > 0) {
            $keys[] = 'typev:' . $type . ':' . $version;
        }

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSyntheticDocumentTimelineEvent(OrderDocument $document): array
    {
        $type = strtolower(trim((string) ($document->tipo_documento ?? '')));
        $label = match ($type) {
            'abertura' => 'Comprovante de abertura',
            'orcamento' => 'Orçamento',
            'laudo' => 'Laudo técnico',
            'entrega' => 'Comprovante de entrega',
            'devolucao_sem_reparo' => 'Comprovante de devolução sem reparo',
            default => $this->humanizeDocumentType($type),
        };

        return [
            'id' => 0,
            'categoria' => OrderEvent::CATEGORIA_DOCUMENTO,
            'tipo' => 'documento_cliente_gerado',
            'titulo' => $label . ' gerado',
            'descricao' => $label . ' registrado no acervo da OS.',
            'dados' => [
                'documento_id' => (int) ($document->id ?? 0),
                'tipo_documento' => $type,
                'versao' => (int) ($document->versao ?? 1),
            ],
            'origem' => OrderEvent::ORIGEM_SISTEMA,
            'created_at' => $this->formatDateTime($document->created_at ?? null),
            'usuario_id' => (int) ($document->gerado_por ?? 0),
            'usuario' => $this->mapTechnician($document->generatedBy),
            '_sort_at' => $this->resolveTimelineSortAt($document->created_at ?? null),
        ];
    }

    private function resolveTimelineSortAt($value): int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestamp();
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);

            return $timestamp !== false ? $timestamp : 0;
        }

        return 0;
    }

    /**
     * @param iterable<OrderProcedureHistory> $procedureItems
     * @return array<int, array<string, mixed>>
     */
    private function mapProcedureHistoryCollection(iterable $procedureItems): array
    {
        $items = [];

        foreach ($procedureItems as $procedureItem) {
            if (! $procedureItem instanceof OrderProcedureHistory) {
                continue;
            }

            $items[] = [
                'id' => (int) ($procedureItem->id ?? 0),
                'descricao' => (string) ($procedureItem->descricao ?? ''),
                'created_at' => $this->formatDateTime($procedureItem->created_at ?? null),
                'usuario_id' => (int) ($procedureItem->usuario_id ?? 0),
                'usuario' => $this->mapTechnician($procedureItem->user),
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
    public function statusCatalogOptions(): array
    {
        return $this->mapStatusOptions();
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

    /**
     * @return array{
     *     status_by_id: array<int, array<string, mixed>>,
     *     status_by_code: array<string, array<string, mixed>>,
     *     transitions_by_origin: array<int, array<int, array<string, mixed>>>
     * }
     */
    private function loadStatusWorkflowCatalog(): array
    {
        if (! Schema::hasTable('os_status') || ! Schema::hasTable('os_status_transicoes')) {
            return [
                'status_by_id' => [],
                'status_by_code' => [],
                'transitions_by_origin' => [],
            ];
        }

        $statuses = OrderStatus::query()
            ->get([
                'id',
                'codigo',
                'nome',
                'grupo_macro',
                'cor',
                'icone',
                'ordem_fluxo',
                'status_final',
                'status_pausa',
                'estado_fluxo_padrao',
                'ativo',
            ]);

        $statusById = [];
        $statusByCode = [];

        foreach ($statuses as $status) {
            $row = [
                'id' => (int) ($status->id ?? 0),
                'codigo' => trim((string) ($status->codigo ?? '')),
                'nome' => (string) ($status->nome ?? ''),
                'grupo_macro' => (string) ($status->grupo_macro ?? ''),
                'cor' => (string) ($status->cor ?? ''),
                'icone' => (string) ($status->icone ?? ''),
                'ordem_fluxo' => (int) ($status->ordem_fluxo ?? 0),
                'status_final' => (bool) ($status->status_final ?? false),
                'status_pausa' => (bool) ($status->status_pausa ?? false),
                'estado_fluxo_padrao' => (string) ($status->estado_fluxo_padrao ?? ''),
                'ativo' => (bool) ($status->ativo ?? false),
            ];

            $statusId = (int) $row['id'];
            $statusCode = (string) $row['codigo'];

            if ($statusId > 0) {
                $statusById[$statusId] = $row;
            }

            if ($statusCode !== '') {
                $statusByCode[$statusCode] = $row;
            }
        }

        $transitionsByOrigin = [];

        OrderStatusTransition::query()
            ->where('ativo', 1)
            ->get(['status_origem_id', 'status_destino_id'])
            ->each(static function (OrderStatusTransition $transition) use (&$transitionsByOrigin): void {
                $originId = (int) ($transition->status_origem_id ?? 0);
                $destinationId = (int) ($transition->status_destino_id ?? 0);

                if ($originId <= 0 || $destinationId <= 0) {
                    return;
                }

                $transitionsByOrigin[$originId][] = [
                    'status_destino_id' => $destinationId,
                ];
            });

        return [
            'status_by_id' => $statusById,
            'status_by_code' => $statusByCode,
            'transitions_by_origin' => $transitionsByOrigin,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $statusById
     * @param array<int, array<int, array<string, mixed>>> $transitionsByOrigin
     * @return array<int, array<string, mixed>>
     */
    private function mapNextStatusOptionsFromCatalog(
        int $currentStatusId,
        array $statusById,
        array $transitionsByOrigin
    ): array {
        if ($currentStatusId <= 0 || ! isset($transitionsByOrigin[$currentStatusId])) {
            return [];
        }

        $transitionRows = collect($transitionsByOrigin[$currentStatusId])
            ->sortBy(function (array $transition) use ($statusById): int {
                $targetId = (int) ($transition['status_destino_id'] ?? 0);
                $targetStatus = $statusById[$targetId] ?? null;

                return (int) ($targetStatus['ordem_fluxo'] ?? PHP_INT_MAX);
            })
            ->values();

        return $transitionRows
            ->map(function (array $transition) use ($statusById): ?array {
                $targetId = (int) ($transition['status_destino_id'] ?? 0);
                $targetStatus = $statusById[$targetId] ?? null;

                if (! is_array($targetStatus) || ! (bool) ($targetStatus['ativo'] ?? false)) {
                    return null;
                }

                // Regra de projeto (skill sistema-erp-os-fluxo-fechamento): os
                // status de encerramento (grupo_macro = 'encerrado') nunca
                // aparecem como "próxima etapa" nos dropdowns de status (modal
                // "Alterar status", quick-status da listagem) — só podem ser
                // aplicados pela tela de baixa. Filtrados aqui, na fonte comum.
                if ((string) ($targetStatus['grupo_macro'] ?? '') === OrderStatus::CLOSURE_MACRO_GROUP) {
                    return null;
                }

                return [
                    'codigo' => (string) ($targetStatus['codigo'] ?? ''),
                    'nome' => (string) ($targetStatus['nome'] ?? ''),
                    'grupo_macro' => (string) ($targetStatus['grupo_macro'] ?? ''),
                    'cor' => (string) ($targetStatus['cor'] ?? ''),
                    'icone' => (string) ($targetStatus['icone'] ?? ''),
                    'ordem_fluxo' => (int) ($targetStatus['ordem_fluxo'] ?? 0),
                    'status_final' => (bool) ($targetStatus['status_final'] ?? false),
                    'status_pausa' => (bool) ($targetStatus['status_pausa'] ?? false),
                    'estado_fluxo_padrao' => (string) ($targetStatus['estado_fluxo_padrao'] ?? ''),
                    'ativo' => (bool) ($targetStatus['ativo'] ?? false),
                ];
            })
            ->filter(static fn (?array $target): bool => is_array($target))
            ->unique(static fn (array $target): string => (string) ($target['codigo'] ?? ''))
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
     */
    private function hasEntryChecklistPayload(array $attributes): bool
    {
        return array_key_exists('checklist_entrada', $attributes)
            && is_array($attributes['checklist_entrada']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildEntryChecklistSyncPlan(int $equipmentId, array $payload): array
    {
        $equipmentTypeId = $equipmentId > 0
            ? (int) Equipment::query()->whereKey($equipmentId)->value('tipo_id')
            : 0;

        $modelo = $this->resolveEntryChecklistModelForEquipmentType($equipmentTypeId);
        if (! $modelo instanceof ChecklistModelo) {
            return ['result' => 'entry_checklist_model_not_found'];
        }

        $items = $modelo->itens
            ->filter(static fn ($item): bool => (bool) ($item->ativo ?? true) && trim((string) ($item->descricao ?? '')) !== '')
            ->values();

        if ($items->isEmpty()) {
            return ['result' => 'entry_checklist_model_empty'];
        }

        $responsesByItemId = [];
        foreach ((array) ($payload['respostas'] ?? []) as $response) {
            if (! is_array($response)) {
                return ['result' => 'entry_checklist_invalid_payload'];
            }

            $itemId = (int) ($response['checklist_item_id'] ?? 0);
            $status = trim((string) ($response['status'] ?? 'nao_verificado'));

            if ($itemId <= 0 || ! in_array($status, self::ENTRY_CHECKLIST_RESPONSE_STATUSES, true)) {
                return ['result' => 'entry_checklist_invalid_payload'];
            }

            $responsesByItemId[$itemId] = [
                'status' => $status,
                'observacao' => trim((string) ($response['observacao'] ?? '')),
            ];
        }

        $rows = [];
        $totalDiscrepancias = 0;

        foreach ($items as $item) {
            $itemId = (int) ($item->id ?? 0);
            $incoming = $responsesByItemId[$itemId] ?? null;

            if ($incoming === null && array_key_exists($itemId, $responsesByItemId) === false) {
                $incoming = [
                    'status' => 'nao_verificado',
                    'observacao' => '',
                ];
            }

            if (! array_key_exists($itemId, $responsesByItemId) && $responsesByItemId !== []) {
                // Item ausente no payload: mantém o checklist completo, mas
                // marca explicitamente que não houve conferência do item.
                $incoming = [
                    'status' => 'nao_verificado',
                    'observacao' => '',
                ];
            }

            $status = (string) ($incoming['status'] ?? 'nao_verificado');
            if (! in_array($status, self::ENTRY_CHECKLIST_RESPONSE_STATUSES, true)) {
                return ['result' => 'entry_checklist_invalid_payload'];
            }

            if ($status === 'discrepancia') {
                $totalDiscrepancias++;
            }

            $rows[] = [
                'checklist_item_id' => $itemId,
                'descricao_item' => trim((string) ($item->descricao ?? '')),
                'ordem' => (int) ($item->ordem ?? 0),
                'status' => $status,
                'observacao' => mb_substr((string) ($incoming['observacao'] ?? ''), 0, 1000),
            ];
        }

        $invalidItemIds = array_diff(
            array_keys($responsesByItemId),
            $items->map(static fn ($item): int => (int) ($item->id ?? 0))->all()
        );

        if ($invalidItemIds !== []) {
            return ['result' => 'entry_checklist_invalid_items'];
        }

        return [
            'result' => 'ok',
            'checklist_tipo_id' => (int) ($modelo->checklist_tipo_id ?? 0),
            'checklist_modelo_id' => (int) ($modelo->id ?? 0),
            'tipo_equipamento_id' => $equipmentTypeId,
            'status' => 'preenchido',
            'total_itens' => count($rows),
            'total_discrepancias' => $totalDiscrepancias,
            'resumo_texto' => $totalDiscrepancias > 0
                ? $totalDiscrepancias . ' discrepância(s) registrada(s).'
                : 'Nenhuma discrepancia registrada.',
            'observacoes_estado' => mb_substr(trim((string) ($payload['observacoes_estado'] ?? '')), 0, 2000),
            'respostas' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function applyEntryChecklistSyncPlan(int $orderId, array $plan, Carbon $now): void
    {
        if ($orderId <= 0 || ! Schema::hasTable('checklist_execucoes') || ! Schema::hasTable('checklist_respostas')) {
            return;
        }

        $executionData = [
            'os_id' => $orderId,
            'checklist_tipo_id' => (int) $plan['checklist_tipo_id'],
            'checklist_modelo_id' => (int) $plan['checklist_modelo_id'],
            'tipo_equipamento_id' => (int) $plan['tipo_equipamento_id'],
            'status' => (string) $plan['status'],
            'total_itens' => (int) $plan['total_itens'],
            'total_discrepancias' => (int) $plan['total_discrepancias'],
            'resumo_texto' => (string) $plan['resumo_texto'],
            'observacoes_estado' => (string) $plan['observacoes_estado'],
            'concluido_em' => $now,
            'updated_at' => $now,
        ];

        $executionId = (int) DB::table('checklist_execucoes')
            ->where('os_id', $orderId)
            ->where('checklist_tipo_id', (int) $plan['checklist_tipo_id'])
            ->orderByDesc('id')
            ->value('id');

        if ($executionId > 0) {
            DB::table('checklist_execucoes')
                ->where('id', $executionId)
                ->update($executionData);
        } else {
            $executionData['created_at'] = $now;
            $executionId = (int) DB::table('checklist_execucoes')->insertGetId($executionData);
        }

        DB::table('checklist_respostas')
            ->where('checklist_execucao_id', $executionId)
            ->delete();

        $responses = [];
        foreach ((array) $plan['respostas'] as $response) {
            if (! is_array($response)) {
                continue;
            }

            $responses[] = [
                'checklist_execucao_id' => $executionId,
                'checklist_item_id' => (int) $response['checklist_item_id'],
                'descricao_item' => (string) $response['descricao_item'],
                'ordem' => (int) $response['ordem'],
                'status' => (string) $response['status'],
                'observacao' => (string) $response['observacao'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($responses !== []) {
            DB::table('checklist_respostas')->insert($responses);
        }

        $this->orderEventService->record(
            $orderId,
            OrderEvent::CATEGORIA_REGISTRO,
            OrderEvent::TIPO_CHECKLIST_REGISTRADO,
            'Checklist de entrada registrado',
            (string) $plan['resumo_texto'] !== '' ? (string) $plan['resumo_texto'] : null,
            [
                'execucao_id' => $executionId,
                'total_itens' => (int) $plan['total_itens'],
                'total_discrepancias' => (int) $plan['total_discrepancias'],
            ],
            null,
            OrderEvent::ORIGEM_USUARIO,
            $now
        );
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
        Carbon $timestamp,
        string $eventTipo = OrderEvent::TIPO_STATUS_ALTERADO
    ): void {
        if (Schema::hasTable('os_status_historico')) {
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

        // Timeline unificada (os_eventos): espelha toda transicao de status.
        // O write legado acima permanece intocado — e dependencia operacional
        // do cancelamento de baixa (OrderClosureService resolve o status
        // anterior lendo os_status_historico).
        $isCreation = $eventTipo === OrderEvent::TIPO_OS_CRIADA;
        $this->orderEventService->record(
            $orderId,
            OrderEvent::CATEGORIA_STATUS,
            $eventTipo,
            $isCreation ? 'OS criada' : 'Status alterado',
            $isCreation
                ? ($note ?? 'OS aberta')
                : sprintf('%s → %s', $previousStatus !== null && $previousStatus !== '' ? $previousStatus : 'Sem origem', $newStatus)
                    . ($note !== null && trim($note) !== '' ? ' — ' . $note : ''),
            [
                'status_anterior' => $previousStatus !== '' ? $previousStatus : null,
                'status_novo' => $newStatus,
                'estado_fluxo' => $stateFlow,
            ],
            (int) $actor->id,
            OrderEvent::ORIGEM_USUARIO,
            $timestamp
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    /**
     * Versao publica de sendOrderNotification para outros services do modulo
     * (ex.: OrderClosureService avisando adiantamento/sinal) — mesmo padrao
     * de destinatarios: autor da acao + tecnico responsavel pela OS.
     *
     * @param array<string, mixed> $extra
     */
    public function notifyOrderUsers(
        Order $order,
        User $actor,
        string $kind,
        string $title,
        string $body,
        array $extra = []
    ): void {
        $this->sendOrderNotification($order, $actor, $kind, $title, $body, $extra);
    }

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

    private function sendStatusChangeClientNotification(Order $order, string $newStatus, ?string $observacao): void
    {
        $order->loadMissing('client');
        $telefone = trim((string) ($order->client?->telefone1 ?? ''));

        if ($telefone === '') {
            logger()->warning('[API V1][ORDERS] Cliente sem telefone cadastrado, notificação de status não enviada', [
                'order_id' => $order->id,
            ]);

            return;
        }

        $statusNome = (string) (
            OrderStatus::query()->where('codigo', $newStatus)->value('nome') ?? $newStatus
        );

        $texto = 'Olá! O status da sua OS ' . $order->numero_os . ' foi atualizado para: "' . $statusNome . '".';
        if (trim((string) $observacao) !== '') {
            $texto .= ' ' . trim((string) $observacao);
        }

        // Caminho preferencial: registra a mensagem na Central de Atendimento
        // (inbox) e dispara o envio pelo provedor configurado. Depende do banco
        // 'chat' estar provisionado/acessível.
        try {
            $resultado = $this->whatsappMessagingService->sendSystemMessage(
                $telefone,
                $texto,
                [],
                trim((string) ($order->client?->nome_razao ?? '')) ?: null,
                (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                [
                    'origem' => 'order_status_update',
                    'order_id' => (int) $order->id,
                    'status_novo' => $newStatus,
                ]
            );

            if ((bool) ($resultado['ok'] ?? false)) {
                $this->recordClientMessageEvent($order, $newStatus, $telefone, 'inbox');

                return;
            }

            logger()->warning('[API V1][ORDERS] Envio via inbox retornou falha; tentando envio direto', [
                'order_id' => $order->id,
                'message' => (string) ($resultado['message'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS] Inbox indisponível para notificar cliente; tentando envio direto', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }

        // Fallback: envia direto pelo provedor (Evolution/gateway) sem passar
        // pela Central de Atendimento — garante entrega mesmo quando o banco
        // 'chat' não está disponível neste ambiente.
        try {
            $direto = $this->integrationSettingsService->sendDirectMessage($telefone, $texto);

            if ((bool) ($direto['ok'] ?? false)) {
                $this->recordClientMessageEvent($order, $newStatus, $telefone, 'direto');
            } else {
                logger()->warning('[API V1][ORDERS] Falha ao notificar cliente sobre mudança de status (envio direto)', [
                    'order_id' => $order->id,
                    'message' => (string) ($direto['message'] ?? ''),
                ]);
            }
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS] Falha ao notificar cliente sobre mudança de status (envio direto)', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function generateOpeningDocument(Order $order, User $actor): array
    {
        try {
            $result = $this->orderOpeningPdfService->generate($order, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'generated' => false,
                'document_id' => null,
                'relative_path' => '',
                'absolute_path' => '',
                'file_name' => '',
                'message' => 'Falha inesperada ao gerar o PDF de abertura.',
                'skipped' => false,
            ];
        }

        return [
            'generated' => (bool) ($result['ok'] ?? false),
            'document_id' => isset($result['document_id']) ? (int) $result['document_id'] : null,
            'relative_path' => (string) ($result['relative_path'] ?? ''),
            'absolute_path' => (string) ($result['absolute_path'] ?? ''),
            'file_name' => (string) ($result['file_name'] ?? ''),
            'message' => (string) ($result['message'] ?? ''),
            'skipped' => (bool) ($result['skipped'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $openingDocument
     * @return array<string, mixed>
     */
    private function sanitizeOpeningDocumentFeedback(array $openingDocument): array
    {
        return [
            'generated' => (bool) ($openingDocument['generated'] ?? false),
            'document_id' => isset($openingDocument['document_id']) ? (int) $openingDocument['document_id'] : null,
            'file_name' => (string) ($openingDocument['file_name'] ?? ''),
            'message' => (string) ($openingDocument['message'] ?? ''),
            'skipped' => (bool) ($openingDocument['skipped'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $openingDocument
     * @return array<string, mixed>
     */
    private function sendOpeningDocumentToClient(Order $order, array $openingDocument): array
    {
        if (! (bool) ($openingDocument['generated'] ?? false)) {
            return [
                'requested' => true,
                'sent' => false,
                'channel' => null,
                'message' => 'O PDF de abertura não foi gerado, então o envio ao cliente não pôde ser concluído.',
            ];
        }

        $absolutePath = trim((string) ($openingDocument['absolute_path'] ?? ''));
        if ($absolutePath === '' || ! is_file($absolutePath)) {
            return [
                'requested' => true,
                'sent' => false,
                'channel' => null,
                'message' => 'O arquivo PDF de abertura não foi encontrado para envio ao cliente.',
            ];
        }

        $order->loadMissing([
            'client',
            'equipment',
            'equipment.type',
            'equipment.brand',
            'equipment.model',
            'technician',
        ]);

        $phone = $this->resolveClientNotificationPhone($order);
        if ($phone === '') {
            return [
                'requested' => true,
                'sent' => false,
                'channel' => null,
                'message' => 'Cliente sem telefone cadastrado para receber o PDF de abertura.',
            ];
        }

        $clientName = trim((string) ($order->client?->nome_razao ?? ''));
        $message = $this->renderOpeningClientMessage($order);
        $fileName = trim((string) ($openingDocument['file_name'] ?? ''));
        $fileName = $fileName !== '' ? $fileName : basename($absolutePath);
        $attachment = new UploadedFile($absolutePath, $fileName, 'application/pdf', null, true);

        try {
            $result = $this->whatsappMessagingService->sendSystemMessage(
                $phone,
                $message,
                [$attachment],
                $clientName !== '' ? $clientName : null,
                (int) ($order->cliente_id ?? 0) > 0 ? (int) $order->cliente_id : null,
                [
                    'origem' => 'order_opening_pdf',
                    'order_id' => (int) $order->id,
                    'numero_os' => (string) ($order->numero_os ?? ''),
                    'tipo_documento' => 'abertura',
                ]
            );

            if ((bool) ($result['ok'] ?? false)) {
                $this->recordOpeningDocumentDeliveryEvent($order, $phone, 'inbox_whatsapp');

                return [
                    'requested' => true,
                    'sent' => true,
                    'channel' => 'inbox_whatsapp',
                    'message' => 'PDF de abertura enviado ao cliente.',
                ];
            }

            logger()->warning('[API V1][ORDERS] Falha no envio via inbox do PDF de abertura; tentando envio direto', [
                'order_id' => (int) $order->id,
                'message' => (string) ($result['message'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS] Inbox indisponível para envio do PDF de abertura; tentando envio direto', [
                'order_id' => (int) $order->id,
                'message' => $exception->getMessage(),
            ]);
        }

        try {
            $direct = $this->integrationSettingsService->sendDirectMedia(
                $phone,
                $absolutePath,
                'document',
                $message,
                $fileName
            );

            if ((bool) ($direct['ok'] ?? false)) {
                $this->recordOpeningDocumentDeliveryEvent($order, $phone, 'direct_media');

                return [
                    'requested' => true,
                    'sent' => true,
                    'channel' => 'direct_media',
                    'message' => 'PDF de abertura enviado ao cliente.',
                ];
            }

            return [
                'requested' => true,
                'sent' => false,
                'channel' => null,
                'message' => trim((string) ($direct['message'] ?? '')) !== ''
                    ? (string) $direct['message']
                    : 'Falha ao enviar o PDF de abertura ao cliente.',
            ];
        } catch (Throwable $exception) {
            logger()->warning('[API V1][ORDERS] Falha ao enviar PDF de abertura ao cliente', [
                'order_id' => (int) $order->id,
                'message' => $exception->getMessage(),
            ]);

            return [
                'requested' => true,
                'sent' => false,
                'channel' => null,
                'message' => 'Falha ao enviar o PDF de abertura ao cliente.',
            ];
        }
    }

    private function renderOpeningClientMessage(Order $order): string
    {
        $defaultMessage = 'Sua OS ' . (string) ($order->numero_os ?? '') . ' foi aberta. Segue o comprovante em PDF.';
        if (! Schema::hasTable('whatsapp_templates')) {
            return $defaultMessage;
        }

        $template = WhatsappTemplate::query()
            ->where(function ($query): void {
                $query->where('codigo', 'os_aberta')
                    ->orWhere('evento', 'os_aberta');
            })
            ->where('ativo', true)
            ->orderByDesc('id')
            ->first();

        if (! $template instanceof WhatsappTemplate) {
            return $defaultMessage;
        }

        $equipment = trim(implode(' ', array_filter([
            trim((string) ($order->equipment?->type?->nome ?? '')),
            trim((string) ($order->equipment?->brand?->nome ?? '')),
            trim((string) ($order->equipment?->model?->nome ?? '')),
        ], static fn (string $value): bool => $value !== '')));

        $message = strtr((string) ($template->conteudo ?? ''), [
            '{{numero_os}}' => (string) ($order->numero_os ?? ''),
            '{{cliente_nome}}' => (string) ($order->client?->nome_razao ?? ''),
            '{{equipamento}}' => $equipment,
            '{{equipamento_tipo}}' => (string) ($order->equipment?->type?->nome ?? ''),
            '{{equipamento_marca}}' => (string) ($order->equipment?->brand?->nome ?? ''),
            '{{equipamento_modelo}}' => (string) ($order->equipment?->model?->nome ?? ''),
            '{{equipamento_serie}}' => (string) ($order->equipment?->numero_serie ?? ''),
            '{{data_abertura}}' => $order->data_abertura instanceof Carbon
                ? $order->data_abertura->format('d/m/Y H:i')
                : (string) ($order->data_abertura ?? ''),
            '{{tecnico_nome}}' => (string) ($order->technician?->nome ?? ''),
        ]);

        $message = trim(strip_tags(html_entity_decode($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')));

        return $message !== '' ? $message : $defaultMessage;
    }

    private function resolveClientNotificationPhone(Order $order): string
    {
        foreach ([
            (string) ($order->client?->telefone1 ?? ''),
            (string) ($order->client?->telefone_contato ?? ''),
            (string) ($order->client?->telefone2 ?? ''),
        ] as $phone) {
            $phone = trim($phone);
            if ($phone !== '') {
                return $phone;
            }
        }

        return '';
    }

    private function recordOpeningDocumentDeliveryEvent(Order $order, string $phone, string $channel): void
    {
        $this->orderEventService->record(
            (int) $order->id,
            OrderEvent::CATEGORIA_MENSAGEM,
            OrderEvent::TIPO_WHATSAPP_ENVIADO,
            'PDF de abertura enviado',
            'Comprovante de abertura enviado ao cliente.',
            [
                'origin' => 'order_opening_pdf',
                'tipo_documento' => 'abertura',
                'destino' => $phone,
                'canal' => $channel,
            ],
            null,
            OrderEvent::ORIGEM_SISTEMA
        );
    }

    private function recordClientMessageEvent(Order $order, string $newStatus, string $telefone, string $canal): void
    {
        $this->orderEventService->record(
            (int) $order->id,
            OrderEvent::CATEGORIA_MENSAGEM,
            OrderEvent::TIPO_WHATSAPP_ENVIADO,
            'WhatsApp enviado ao cliente',
            'Cliente notificado sobre a mudança de status da OS.',
            [
                'origin' => 'order_status_update',
                'status_novo' => $newStatus,
                'destino' => $telefone,
                'canal' => $canal,
            ],
            null,
            OrderEvent::ORIGEM_SISTEMA
        );
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
