<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetItem;
use App\Models\BudgetSend;
use App\Models\BudgetStatusHistory;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\Financeiro;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Peca;
use App\Models\Servico;
use App\Models\User;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Financeiro\OsMargemService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Orders\OrderEventService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BudgetWorkflowService
{
    public function __construct(
        private readonly BudgetOrderSyncService $budgetOrderSyncService,
        private readonly BudgetApprovalService $budgetApprovalService,
        private readonly OrderEventService $orderEventService,
        private readonly NotificationDispatchService $notificationDispatchService,
        private readonly FinanceiroService $financeiroService,
        private readonly OsMargemService $osMargemService
    ) {
    }

    /**
     * Encerramentos com equipamento efetivamente entregue/devolvido/descartado
     * ao cliente — mais estreito que OrderStatus::closureCodes() de propósito:
     * esse método também inclui 'cancelado' (usado no dropdown "Encerrar como"
     * e no badge geral "OS encerrada"), mas uma OS pode chegar em 'cancelado'
     * só por sincronização automática do orçamento rejeitado
     * (BudgetOrderSyncService::syncFromBudget()), sem NENHUM lançamento
     * financeiro ter existido. Bloquear a edição do orçamento nesse caso não
     * protegeria nada e só atrapalharia a correção do orçamento rejeitado.
     */
    private const BUDGET_LOCK_CLOSURE_CODES = ['entregue_reparado', 'devolvido_sem_reparo', 'descartado'];

    /**
     * OS encerrada (skill sistema-erp-os-fluxo-fechamento): já houve entrega do
     * equipamento e, em geral, lançamento financeiro — editar o orçamento nesse
     * estado exige confirmação de administrador (ver updateBudget()/createBudget()).
     */
    private function isOrderClosed(?Order $order): bool
    {
        return $order instanceof Order
            && in_array(trim((string) $order->status), self::BUDGET_LOCK_CLOSURE_CODES, true);
    }

    /**
     * @return array{paginator: LengthAwarePaginator, summary: array<string, mixed>, status_options: array<int, array<string, mixed>>}
     */
    public function paginateForUser(User $user, array $filters = []): array
    {
        $query = $this->buildQuery($filters);
        $summary = $this->summary(clone $query);

        $paginator = $query
            ->orderByDesc('orcamentos.created_at')
            ->paginate(
                perPage: max(1, min(100, (int) ($filters['per_page'] ?? 15))),
                page: max(1, (int) ($filters['page'] ?? 1))
            )
            ->withQueryString();

        $paginator->setCollection(
            $paginator->getCollection()->map(fn (Budget $budget): array => $this->budgetListItem($budget))
        );

        return [
            'paginator' => $paginator,
            'summary' => $summary,
            'status_options' => Budget::statusOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showForUser(User $user, int $budgetId): array
    {
        $budget = $this->loadBudget($budgetId);

        if (! $budget instanceof Budget) {
            return ['result' => 'not_found'];
        }

        return [
            'result' => 'ok',
            'budget' => $this->budgetDetail($budget),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(User $user, array $context = []): array
    {
        $selectedClientId = (int) ($context['cliente_id'] ?? 0);
        $selectedOrderId = (int) ($context['os_id'] ?? 0);
        $selectedEquipmentId = 0;
        $selectedClientPhone = '';
        $selectedClientEmail = '';
        $selectedOrderDeadline = '';

        if ($selectedOrderId > 0) {
            $contextOrder = Order::query()->with('client')->find($selectedOrderId);

            if ($contextOrder instanceof Order) {
                if ($selectedClientId <= 0) {
                    $selectedClientId = (int) ($contextOrder->cliente_id ?? 0);
                }

                $selectedEquipmentId = (int) ($contextOrder->equipamento_id ?? 0);

                if ($contextOrder->client instanceof Client) {
                    $selectedClientPhone = (string) ($contextOrder->client->telefone1 ?? '');
                    $selectedClientEmail = (string) ($contextOrder->client->email ?? '');
                }

                if ($contextOrder->data_previsao !== null) {
                    $selectedOrderDeadline = 'Previsão: ' . $contextOrder->data_previsao->format('d/m/Y');
                }
            }
        }

        $clientsQuery = Client::query()
            ->select(['id', 'nome_razao', 'cpf_cnpj', 'telefone1', 'email', 'cidade']);

        if ($selectedClientId > 0) {
            $clientsQuery->orderByRaw('id = ? desc', [$selectedClientId]);
        }

        $clients = $clientsQuery
            ->orderBy('nome_razao')
            ->limit(80)
            ->get()
            ->map(static fn (Client $client): array => [
                'id' => (int) $client->id,
                'nome_razao' => (string) ($client->nome_razao ?? ''),
                'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
                'telefone1' => (string) ($client->telefone1 ?? ''),
                'email' => (string) ($client->email ?? ''),
                'cidade' => (string) ($client->cidade ?? ''),
            ])
            ->values()
            ->all();

        $equipmentQuery = Equipment::query()
            ->with(['client', 'type', 'brand', 'model'])
            ->select(['id', 'cliente_id', 'tipo_id', 'marca_id', 'modelo_id', 'resumo_tecnico', 'numero_serie', 'imei', 'status'])
            ->orderByDesc('id')
            ->limit(80);

        if ($selectedClientId > 0) {
            $equipmentQuery->where('cliente_id', $selectedClientId);
        }

        $equipments = $equipmentQuery->get()->map(static fn (Equipment $equipment): array => [
            'id' => (int) $equipment->id,
            'cliente_id' => (int) ($equipment->cliente_id ?? 0),
            'cliente_nome' => (string) ($equipment->client?->nome_razao ?? ''),
            'tipo_nome' => (string) ($equipment->type?->nome ?? ''),
            'marca_nome' => (string) ($equipment->brand?->nome ?? ''),
            'modelo_nome' => (string) ($equipment->model?->nome ?? ''),
            'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
            'numero_serie' => (string) ($equipment->numero_serie ?? ''),
            'imei' => (string) ($equipment->imei ?? ''),
            'status' => (string) ($equipment->status ?? ''),
        ])->values()->all();

        $ordersQuery = Order::query()
            ->with(['client', 'equipment'])
            ->select(['id', 'numero_os', 'cliente_id', 'equipamento_id', 'status', 'estado_fluxo', 'data_abertura'])
            ->orderByDesc('id')
            ->limit(80);

        if ($selectedClientId > 0) {
            $ordersQuery->where('cliente_id', $selectedClientId);
        }

        if ($selectedOrderId > 0) {
            $ordersQuery->where('id', $selectedOrderId);
        }

        $orders = $ordersQuery->get()->map(static fn (Order $order): array => [
            'id' => (int) $order->id,
            'numero_os' => (string) ($order->numero_os ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
            'equipamento_resumo' => (string) ($order->equipment?->resumo_tecnico ?? ''),
            'status' => (string) ($order->status ?? ''),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'data_abertura' => optional($order->data_abertura)->format('Y-m-d H:i:s'),
        ])->values()->all();

        $services = Servico::query()
            ->select(['id', 'nome', 'descricao', 'valor', 'tipo_equipamento', 'status'])
            ->where('status', 'ativo')
            ->orderBy('nome')
            ->limit(80)
            ->get()
            ->map(static fn (Servico $servico): array => [
                'id' => (int) $servico->id,
                'nome' => (string) ($servico->nome ?? ''),
                'descricao' => (string) ($servico->descricao ?? ''),
                'valor' => (float) ($servico->valor ?? 0),
                'tipo_equipamento' => (string) ($servico->tipo_equipamento ?? ''),
            ])
            ->values()
            ->all();

        $parts = Peca::query()
            ->select(['id', 'codigo', 'nome', 'categoria', 'preco_custo', 'preco_venda', 'quantidade_atual', 'status'])
            ->where('status', 'ativo')
            ->orderBy('nome')
            ->limit(80)
            ->get()
            ->map(static fn (Peca $peca): array => [
                'id' => (int) $peca->id,
                'codigo' => (string) ($peca->codigo ?? ''),
                'nome' => (string) ($peca->nome ?? ''),
                'categoria' => (string) ($peca->categoria ?? ''),
                'preco_custo' => (float) ($peca->preco_custo ?? 0),
                'preco_venda' => (float) ($peca->preco_venda ?? 0),
                'quantidade_atual' => (int) ($peca->quantidade_atual ?? 0),
            ])
            ->values()
            ->all();

        return [
            'selected_client_id' => $selectedClientId,
            'selected_order_id' => $selectedOrderId,
            'selected_equipment_id' => $selectedEquipmentId,
            'selected_client_phone' => $selectedClientPhone,
            'selected_client_email' => $selectedClientEmail,
            'selected_order_deadline' => $selectedOrderDeadline,
            'clients' => $clients,
            'equipments' => $equipments,
            'orders' => $orders,
            'services' => $services,
            'parts' => $parts,
            'tipos_equipamento' => Servico::tiposEquipamentoAtivos(),
            'status_options' => Budget::statusOptions(),
            'type_options' => Budget::typeOptions(),
            'origin_options' => Budget::originOptions(),
            'default_validity_days' => 10,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createBudget(User $user, array $payload, ?User $verifiedAdmin = null): array
    {
        return DB::transaction(function () use ($user, $payload, $verifiedAdmin): array {
            $attributes = $this->normalizePayload($payload, true);
            $budgetAttributes = $attributes;
            unset($budgetAttributes['itens']);

            $osId = (int) ($budgetAttributes['os_id'] ?? 0);
            if ($osId > 0) {
                $order = Order::query()->find($osId);
                if ($this->isOrderClosed($order) && ! ($verifiedAdmin instanceof User)) {
                    return ['result' => 'requires_admin_confirmation'];
                }
            }

            $budget = new Budget();
            $budget->fill($budgetAttributes);
            $budget->numero = (string) ($budgetAttributes['numero'] ?? $this->nextBudgetNumber());
            $budget->versao = max(1, (int) ($budgetAttributes['versao'] ?? 1));
            $budget->tipo_orcamento = $this->resolveType($budgetAttributes, true);
            $budget->status = $this->resolveStatus($budgetAttributes, true);
            $budget->origem = $this->resolveOrigin($budgetAttributes, $budget->os_id !== null);
            $budget->cliente_id = $this->resolveClientId($budgetAttributes, $budget->os_id);
            $budget->equipamento_id = $this->resolveEquipmentId($budgetAttributes, $budget->os_id);
            $budget->responsavel_id = (int) ($budgetAttributes['responsavel_id'] ?? $user->id);
            $budget->criado_por = (int) ($budgetAttributes['criado_por'] ?? $user->id);
            $budget->atualizado_por = (int) ($budgetAttributes['atualizado_por'] ?? $user->id);
            $budget->validade_dias = max(0, (int) ($budgetAttributes['validade_dias'] ?? 10));
            $budget->validade_data = $this->resolveValidityDate($budgetAttributes, $budget->validade_dias);
            $budget->subtotal = $this->resolveMoney($budgetAttributes['subtotal'] ?? null);
            $budget->desconto = $this->resolveMoney($budgetAttributes['desconto'] ?? null);
            $budget->desconto_tipo = $this->resolveAdjustmentMode($budgetAttributes['desconto_tipo'] ?? null);
            $budget->desconto_percentual = $budget->desconto_tipo === Budget::ADJUSTMENT_MODE_PERCENT
                ? $this->resolveDecimal($budgetAttributes['desconto_percentual'] ?? null, 4)
                : null;
            $budget->acrescimo = $this->resolveMoney($budgetAttributes['acrescimo'] ?? null);
            $budget->acrescimo_tipo = $this->resolveAdjustmentMode($budgetAttributes['acrescimo_tipo'] ?? null);
            $budget->acrescimo_percentual = $budget->acrescimo_tipo === Budget::ADJUSTMENT_MODE_PERCENT
                ? $this->resolveDecimal($budgetAttributes['acrescimo_percentual'] ?? null, 4)
                : null;
            $budget->total = 0;
            $budget->save();

            $itemsSubtotal = array_key_exists('itens', $attributes)
                ? $this->syncItems($budget, is_array($attributes['itens'] ?? null) ? $attributes['itens'] : [])
                : null;
            $this->recalculateBudgetFinancials($budget, $itemsSubtotal, $budgetAttributes['subtotal'] ?? null);
            $this->recordStatusHistory(
                $budget,
                null,
                $budget->status,
                'Cadastro inicial do orçamento.',
                'sistema',
                $user->id
            );

            if ((int) ($budget->os_id ?? 0) > 0) {
                $this->orderEventService->record(
                    (int) $budget->os_id,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_CRIADO,
                    'Orçamento criado',
                    sprintf('Orçamento %s criado (R$ %s).', $budget->numero, number_format((float) $budget->total, 2, ',', '.')),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'valor_total' => round((float) $budget->total, 2),
                        'status' => (string) $budget->status,
                    ],
                    (int) $user->id
                );
            }

            $this->budgetOrderSyncService->syncFromBudget($budget, (int) $user->id);

            // Sino: avisa quem criou e o tecnico da OS vinculada (se houver).
            $this->notificationDispatchService->toUsers(
                [(int) $user->id, (int) ($budget->order?->tecnico_id ?? 0)],
                [
                    'kind' => 'orcamento.created',
                    'title' => 'Orçamento criado',
                    'body' => sprintf(
                        'O orçamento %s foi criado (R$ %s).',
                        $budget->numero,
                        number_format((float) $budget->total, 2, ',', '.')
                    ),
                    'route' => '/orcamentos/' . (int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );

            return $this->budgetDetail($this->loadBudgetOrFail((int) $budget->id));
        });
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateBudget(int $budgetId, User $user, array $payload, ?User $verifiedAdmin = null): array
    {
        return DB::transaction(function () use ($budgetId, $user, $payload, $verifiedAdmin): array {
            $budget = $this->loadBudget($budgetId);

            if (! $budget instanceof Budget) {
                return ['result' => 'not_found'];
            }

            $order = $budget->order; // já eager-loaded por loadBudget()
            $osClosed = $this->isOrderClosed($order);

            if ($osClosed && ! ($verifiedAdmin instanceof User)) {
                return ['result' => 'requires_admin_confirmation'];
            }

            $attributes = $this->normalizePayload($payload, false);
            $budgetAttributes = $attributes;
            unset($budgetAttributes['itens']);
            $previousStatus = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            $previousTotal = (float) ($budget->total ?? 0);

            $budget->fill($budgetAttributes);
            if (array_key_exists('numero', $budgetAttributes) && trim((string) $budgetAttributes['numero']) !== '') {
                $budget->numero = trim((string) $budgetAttributes['numero']);
            }
            $budget->tipo_orcamento = $this->resolveType($budgetAttributes, (int) ($budget->os_id ?? 0) > 0);
            $budget->status = $this->resolveStatus($budgetAttributes, false, $previousStatus);
            $budget->origem = $this->resolveOrigin($budgetAttributes, (int) ($budget->os_id ?? 0) > 0);
            $budget->cliente_id = $this->resolveClientId($budgetAttributes, $budget->os_id);
            $budget->equipamento_id = $this->resolveEquipmentId($budgetAttributes, $budget->os_id);
            $budget->responsavel_id = (int) ($budgetAttributes['responsavel_id'] ?? $budget->responsavel_id ?? $user->id);
            $budget->atualizado_por = (int) ($budgetAttributes['atualizado_por'] ?? $user->id);
            $budget->validade_dias = max(0, (int) ($budgetAttributes['validade_dias'] ?? $budget->validade_dias ?? 10));
            $budget->validade_data = $this->resolveValidityDate($budgetAttributes, $budget->validade_dias, $budget->validade_data);
            $budget->desconto = array_key_exists('desconto', $budgetAttributes)
                ? $this->resolveMoney($budgetAttributes['desconto'])
                : (float) ($budget->desconto ?? 0);
            $budget->desconto_tipo = $this->resolveAdjustmentMode(
                $budgetAttributes['desconto_tipo'] ?? $budget->desconto_tipo,
                $this->resolveAdjustmentMode($budget->desconto_tipo)
            );
            $budget->desconto_percentual = $budget->desconto_tipo === Budget::ADJUSTMENT_MODE_PERCENT
                ? $this->resolveDecimal($budgetAttributes['desconto_percentual'] ?? $budget->desconto_percentual, 4)
                : null;
            $budget->acrescimo = array_key_exists('acrescimo', $budgetAttributes)
                ? $this->resolveMoney($budgetAttributes['acrescimo'])
                : (float) ($budget->acrescimo ?? 0);
            $budget->acrescimo_tipo = $this->resolveAdjustmentMode(
                $budgetAttributes['acrescimo_tipo'] ?? $budget->acrescimo_tipo,
                $this->resolveAdjustmentMode($budget->acrescimo_tipo)
            );
            $budget->acrescimo_percentual = $budget->acrescimo_tipo === Budget::ADJUSTMENT_MODE_PERCENT
                ? $this->resolveDecimal($budgetAttributes['acrescimo_percentual'] ?? $budget->acrescimo_percentual, 4)
                : null;
            $budget->total = $previousTotal;
            $budget->save();

            $itemsSubtotal = null;
            if (array_key_exists('itens', $attributes)) {
                $itemsSubtotal = $this->syncItems($budget, is_array($attributes['itens']) ? $attributes['itens'] : []);
            }

            $this->recalculateBudgetFinancials($budget, $itemsSubtotal, $budgetAttributes['subtotal'] ?? $budget->subtotal);

            $totalChanged = abs((float) $budget->total - $previousTotal) > 0.009;
            $financialAdjustment = null;

            if ($osClosed && $totalChanged && $order instanceof Order) {
                // Edição autorizada por admin numa OS encerrada mudou o valor:
                // o título a receber (e, se necessário, os movimentos já
                // registrados) precisam refletir o valor corrigido — senão o
                // financeiro fica dessincronizado da realidade.
                $financialAdjustment = $this->correctClosedOrderFinancials($order, (float) $budget->total);
            } elseif (
                ! $osClosed
                && $totalChanged
                && $previousStatus === Budget::STATUS_APPROVED
                && $budget->status === $previousStatus
            ) {
                // OS ainda aberta e o valor mudou depois de já aprovado pelo
                // cliente: volta a exigir aprovação (reenviar_orcamento já
                // aparece automaticamente com o botão "Reenviar para
                // aprovação" em orcamentos/show.blade.php). Só sobrescreve o
                // status se nada mais no payload já tiver mudado explicitamente.
                $budget->status = Budget::STATUS_RESEND;
                $budget->save();
            }

            if ($previousStatus !== $budget->status) {
                $this->recordStatusHistory(
                    $budget,
                    $previousStatus,
                    $budget->status,
                    'Status atualizado pelo desktop.',
                    'sistema',
                    $user->id
                );
            }

            if ((int) ($budget->os_id ?? 0) > 0) {
                $descricao = sprintf('Orçamento %s atualizado (R$ %s).', $budget->numero, number_format((float) $budget->total, 2, ',', '.'));
                $dados = [
                    'orcamento_id' => (int) $budget->id,
                    'numero' => (string) $budget->numero,
                    'status_anterior' => $previousStatus,
                    'status_novo' => (string) $budget->status,
                    'valor_total' => round((float) $budget->total, 2),
                ];

                if ($osClosed && $verifiedAdmin instanceof User) {
                    $dados['autorizado_por_admin'] = [
                        'id' => (int) $verifiedAdmin->id,
                        'email' => (string) $verifiedAdmin->email,
                    ];
                    $dados['total_anterior'] = round($previousTotal, 2);
                    $dados['total_novo'] = round((float) $budget->total, 2);
                    $descricao = 'Edição em OS encerrada autorizada por administrador. ' . $descricao;
                }

                $this->orderEventService->record(
                    (int) $budget->os_id,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_ATUALIZADO,
                    'Orçamento atualizado',
                    $descricao,
                    $dados,
                    (int) $user->id
                );

                if (($financialAdjustment['ajustado'] ?? false) === true) {
                    $this->orderEventService->record(
                        (int) $budget->os_id,
                        OrderEvent::CATEGORIA_FINANCEIRO,
                        OrderEvent::TIPO_MOVIMENTO_REGISTRADO,
                        'Movimento de recebimento ajustado',
                        sprintf(
                            'Recebimento reduzido em R$ %s após correção do orçamento em OS encerrada.',
                            number_format((float) ($financialAdjustment['valor_liberado'] ?? 0), 2, ',', '.')
                        ),
                        ['ajustes' => $financialAdjustment['ajustes'] ?? []],
                        (int) $user->id
                    );
                }
            }

            $this->budgetOrderSyncService->syncFromBudget($budget, (int) $user->id);

            if ($osClosed && $totalChanged && $order instanceof Order) {
                $this->osMargemService->calcularParaOs((int) $order->id);
            }

            return $this->budgetDetail($this->loadBudgetOrFail((int) $budget->id));
        });
    }

    /**
     * Corrige o título a receber (e, se preciso, os movimentos já baixados)
     * de uma OS encerrada para acompanhar o novo total do orçamento — usado
     * apenas na edição admin-autorizada de orçamento com OS já fechada.
     * Retorna null quando a OS não tem título a receber (ex.: devolvido sem
     * reparo / descartado nunca geram lançamento financeiro).
     *
     * @return array{ajustado: bool, ajustes?: array<int, array<string, mixed>>, valor_liberado?: float}|null
     */
    private function correctClosedOrderFinancials(Order $order, float $novoTotal): ?array
    {
        $financeiro = Financeiro::query()
            ->where('os_id', $order->id)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $financeiro instanceof Financeiro) {
            return null;
        }

        $ajuste = $this->financeiroService->reduceMovementsToTotal($financeiro, $novoTotal);
        $this->financeiroService->update($financeiro, ['valor' => $novoTotal]);

        return $ajuste;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBudget(int $budgetId, User $user): array
    {
        return DB::transaction(function () use ($budgetId, $user): array {
            $budget = Budget::query()->with(['items', 'histories', 'sends', 'approvals'])->find($budgetId);

            if (! $budget instanceof Budget) {
                return ['result' => 'not_found'];
            }

            // Snapshot ANTES do hard delete para a timeline da OS.
            $osId = (int) ($budget->os_id ?? 0);
            if ($osId > 0) {
                $this->orderEventService->record(
                    $osId,
                    OrderEvent::CATEGORIA_ORCAMENTO,
                    OrderEvent::TIPO_ORCAMENTO_EXCLUIDO,
                    'Orçamento excluído',
                    sprintf('Orçamento %s (R$ %s) excluído.', $budget->numero, number_format((float) $budget->total, 2, ',', '.')),
                    [
                        'orcamento_id' => (int) $budget->id,
                        'numero' => (string) $budget->numero,
                        'valor_total' => round((float) $budget->total, 2),
                        'status' => (string) $budget->status,
                    ],
                    (int) $user->id
                );
            }

            $budget->items()->delete();
            $budget->histories()->delete();
            $budget->sends()->delete();
            $budget->approvals()->delete();
            $budget->delete();

            return ['result' => 'ok'];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetListItem(Budget $budget): array
    {
        $status = (string) ($budget->status ?? Budget::STATUS_DRAFT);
        $client = $budget->client;
        $equipment = $budget->equipment;
        $order = $budget->order;

        $links = [];
        if ((int) ($budget->os_id ?? 0) > 0) {
            $links[] = 'OS ' . (string) ($order?->numero_os ?? ('#' . (int) $budget->os_id));
        }
        if ((int) ($budget->equipamento_id ?? 0) > 0) {
            $links[] = 'Equipamento #' . (int) $budget->equipamento_id;
        }
        if ((int) ($budget->conversa_id ?? 0) > 0) {
            $links[] = 'Conversa #' . (int) $budget->conversa_id;
        }

        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-' . (int) $budget->id)),
            'versao' => (int) ($budget->versao ?? 1),
            'tipo_orcamento' => (string) ($budget->tipo_orcamento ?? Budget::TYPE_PREVIEW),
            'tipo_label' => Budget::typeLabel($budget->tipo_orcamento),
            'status' => $status,
            'status_label' => Budget::statusLabel($status),
            'status_color' => $this->statusColor($status),
            'origem' => (string) ($budget->origem ?? 'manual'),
            'origem_label' => Budget::originLabel($budget->origem),
            'cliente_nome' => trim((string) ($client?->nome_razao ?? ($budget->cliente_nome_avulso ?? ''))),
            'cliente_documento' => trim((string) ($client?->cpf_cnpj ?? '')),
            'equipamento_resumo' => trim((string) ($equipment?->resumo_tecnico ?? '')),
            'os_numero' => trim((string) ($order?->numero_os ?? '')),
            'vinculos' => implode(' | ', $links),
            'validade_dias' => (int) ($budget->validade_dias ?? 0),
            'validade_data' => optional($budget->validade_data)->format('d/m/Y'),
            'subtotal' => round((float) ($budget->subtotal ?? 0), 2),
            'desconto' => round((float) ($budget->desconto ?? 0), 2),
            'desconto_tipo' => $this->resolveAdjustmentMode($budget->desconto_tipo),
            'desconto_percentual' => $budget->desconto_percentual !== null ? round((float) $budget->desconto_percentual, 4) : null,
            'acrescimo' => round((float) ($budget->acrescimo ?? 0), 2),
            'acrescimo_tipo' => $this->resolveAdjustmentMode($budget->acrescimo_tipo),
            'acrescimo_percentual' => $budget->acrescimo_percentual !== null ? round((float) $budget->acrescimo_percentual, 4) : null,
            'total' => round((float) ($budget->total ?? 0), 2),
            'total_formatado' => number_format((float) ($budget->total ?? 0), 2, ',', '.'),
            'updated_at' => optional($budget->updated_at)->format('d/m/Y H:i'),
            'created_at' => optional($budget->created_at)->format('d/m/Y H:i'),
            'can_edit' => ! in_array($status, [Budget::STATUS_CONVERTED], true),
            'can_delete' => in_array($status, [Budget::STATUS_DRAFT, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetDetail(Budget $budget): array
    {
        $client = $budget->client;
        $equipment = $budget->equipment;
        $order = $budget->order;
        $status = (string) ($budget->status ?? Budget::STATUS_DRAFT);
        $canSendApproval = in_array($status, [
            Budget::STATUS_DRAFT,
            Budget::STATUS_PENDING_SEND,
            Budget::STATUS_SENT,
            Budget::STATUS_WAITING_REPLY,
            Budget::STATUS_PENDING,
            Budget::STATUS_RESEND,
        ], true);
        $publicLink = $canSendApproval || trim((string) ($budget->token_publico ?? '')) !== ''
            ? $this->budgetApprovalService->ensurePublicApprovalUrl($budget)
            : '';

        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-' . (int) $budget->id)),
            'versao' => (int) ($budget->versao ?? 1),
            'tipo_orcamento' => (string) ($budget->tipo_orcamento ?? Budget::TYPE_PREVIEW),
            'tipo_label' => Budget::typeLabel($budget->tipo_orcamento),
            'status' => $status,
            'status_label' => Budget::statusLabel($status),
            'status_color' => $this->statusColor($status),
            'origem' => (string) ($budget->origem ?? 'manual'),
            'origem_label' => Budget::originLabel($budget->origem),
            'titulo' => (string) ($budget->titulo ?? ''),
            'cliente_nome_avulso' => (string) ($budget->cliente_nome_avulso ?? ''),
            'telefone_contato' => (string) ($budget->telefone_contato ?? ''),
            'email_contato' => (string) ($budget->email_contato ?? ''),
            'validade_dias' => (int) ($budget->validade_dias ?? 0),
            'validade_data' => optional($budget->validade_data)->format('d/m/Y'),
            'token_publico' => (string) ($budget->token_publico ?? ''),
            'token_expira_em' => optional($budget->token_expira_em)->format('d/m/Y H:i'),
            'enviado_em' => optional($budget->enviado_em)->format('d/m/Y H:i'),
            'aprovado_em' => optional($budget->aprovado_em)->format('d/m/Y H:i'),
            'rejeitado_em' => optional($budget->rejeitado_em)->format('d/m/Y H:i'),
            'motivo_rejeicao' => (string) ($budget->motivo_rejeicao ?? ''),
            'subtotal' => round((float) ($budget->subtotal ?? 0), 2),
            'desconto' => round((float) ($budget->desconto ?? 0), 2),
            'desconto_tipo' => $this->resolveAdjustmentMode($budget->desconto_tipo),
            'desconto_percentual' => $budget->desconto_percentual !== null ? round((float) $budget->desconto_percentual, 4) : null,
            'acrescimo' => round((float) ($budget->acrescimo ?? 0), 2),
            'acrescimo_tipo' => $this->resolveAdjustmentMode($budget->acrescimo_tipo),
            'acrescimo_percentual' => $budget->acrescimo_percentual !== null ? round((float) $budget->acrescimo_percentual, 4) : null,
            'total' => round((float) ($budget->total ?? 0), 2),
            'total_formatado' => number_format((float) ($budget->total ?? 0), 2, ',', '.'),
            'prazo_execucao' => (string) ($budget->prazo_execucao ?? ''),
            'observacoes' => (string) ($budget->observacoes ?? ''),
            'condicoes' => (string) ($budget->condicoes ?? ''),
            'numero_os' => (string) ($order?->numero_os ?? ''),
            'cliente' => $client ? [
                'id' => (int) $client->id,
                'nome_razao' => (string) ($client->nome_razao ?? ''),
                'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
                'telefone1' => (string) ($client->telefone1 ?? ''),
                'email' => (string) ($client->email ?? ''),
            ] : null,
            'equipamento' => $equipment ? [
                'id' => (int) $equipment->id,
                'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
                'numero_serie' => (string) ($equipment->numero_serie ?? ''),
                'imei' => (string) ($equipment->imei ?? ''),
            ] : null,
            'os' => $order ? [
                'id' => (int) $order->id,
                'numero_os' => (string) ($order->numero_os ?? ''),
                'status' => (string) ($order->status ?? ''),
                'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
                'is_encerrada' => $this->isOrderClosed($order),
            ] : null,
            'responsavel' => $budget->responsible ? [
                'id' => (int) $budget->responsible->id,
                'nome' => (string) ($budget->responsible->nome ?? ''),
                'email' => (string) ($budget->responsible->email ?? ''),
            ] : null,
            'itens' => $budget->items->sortBy('ordem')->values()->map(fn (BudgetItem $item): array => [
                'id' => (int) $item->id,
                'tipo_item' => (string) ($item->tipo_item ?? 'servico'),
                'referencia_id' => $item->referencia_id !== null ? (int) $item->referencia_id : null,
                'descricao' => (string) ($item->descricao ?? ''),
                'quantidade' => (float) ($item->quantidade ?? 0),
                'valor_unitario' => (float) ($item->valor_unitario ?? 0),
                'desconto' => (float) ($item->desconto ?? 0),
                'desconto_tipo' => $this->resolveAdjustmentMode($item->desconto_tipo),
                'desconto_percentual' => $item->desconto_percentual !== null ? round((float) $item->desconto_percentual, 4) : null,
                'acrescimo' => (float) ($item->acrescimo ?? 0),
                'acrescimo_tipo' => $this->resolveAdjustmentMode($item->acrescimo_tipo),
                'acrescimo_percentual' => $item->acrescimo_percentual !== null ? round((float) $item->acrescimo_percentual, 4) : null,
                'total' => (float) ($item->total ?? 0),
                'observacoes' => (string) ($item->observacoes ?? ''),
                'preco_custo_referencia' => (float) ($item->preco_custo_referencia ?? 0),
                'preco_venda_referencia' => (float) ($item->preco_venda_referencia ?? 0),
                'preco_base' => (float) ($item->preco_base ?? 0),
                'percentual_encargos' => (float) ($item->percentual_encargos ?? 0),
                'valor_encargos' => (float) ($item->valor_encargos ?? 0),
                'percentual_margem' => (float) ($item->percentual_margem ?? 0),
                'valor_margem' => (float) ($item->valor_margem ?? 0),
                'valor_recomendado' => (float) ($item->valor_recomendado ?? 0),
                'modo_precificacao' => (string) ($item->modo_precificacao ?? ''),
            ])->all(),
            'historico' => $budget->histories->sortByDesc('created_at')->take(10)->values()->map(static fn (BudgetStatusHistory $history): array => [
                'id' => (int) $history->id,
                'status_anterior' => (string) ($history->status_anterior ?? ''),
                'status_novo' => (string) ($history->status_novo ?? ''),
                'observacao' => (string) ($history->observacao ?? ''),
                'origem' => (string) ($history->origem ?? 'sistema'),
                'alterado_por' => (int) ($history->alterado_por ?? 0),
                'alterado_por_nome' => (string) ($history->user?->nome ?? ''),
                'created_at' => optional($history->created_at)->format('d/m/Y H:i'),
            ])->all(),
            'aprovacoes' => $budget->approvals->sortByDesc('created_at')->take(10)->values()->map(static fn (BudgetApproval $approval): array => [
                'id' => (int) $approval->id,
                'acao' => (string) ($approval->acao ?? ''),
                'origem' => (string) ($approval->origem ?? ''),
                'usuario_nome' => (string) ($approval->usuario_nome ?? ($approval->user?->nome ?? '')),
                'resposta_cliente' => (string) ($approval->resposta_cliente ?? ''),
                'observacao' => (string) ($approval->observacao ?? ''),
                'created_at' => optional($approval->created_at)->format('d/m/Y H:i'),
            ])->all(),
            'envios' => $budget->sends->sortByDesc('created_at')->take(10)->values()->map(static fn (BudgetSend $send): array => [
                'id' => (int) $send->id,
                'canal' => (string) ($send->canal ?? ''),
                'destino' => (string) ($send->destino ?? ''),
                'status' => (string) ($send->status ?? ''),
                'provedor' => (string) ($send->provedor ?? ''),
                'documento_path' => (string) ($send->documento_path ?? ''),
                'enviado_por' => (int) ($send->enviado_por ?? 0),
                'enviado_por_nome' => (string) ($send->sender?->nome ?? ''),
                'enviado_em' => optional($send->enviado_em)->format('d/m/Y H:i'),
            ])->all(),
            'status_options' => Budget::statusOptions(),
            'type_options' => Budget::typeOptions(),
            'origin_options' => Budget::originOptions(),
            'can_edit' => ! in_array($status, [Budget::STATUS_CONVERTED], true),
            'can_delete' => in_array($status, [Budget::STATUS_DRAFT, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_send_approval' => $canSendApproval,
            'link_publico' => $publicLink,
            'created_at' => optional($budget->created_at)->format('d/m/Y H:i'),
            'updated_at' => optional($budget->updated_at)->format('d/m/Y H:i'),
        ];
    }

    /**
     * @param Builder<Budget> $query
     * @return array<string, mixed>
     */
    private function summary(Builder $query): array
    {
        $totalValue = (float) (clone $query)->sum('total');
        $counts = [];

        foreach (array_column(Budget::statusOptions(), 'value') as $status) {
            $counts[$status] = (clone $query)->where('orcamentos.status', $status)->count();
        }

        return array_merge([
            'total' => (clone $query)->count(),
            'total_value' => round($totalValue, 2),
            'by_status' => $counts,
        ], $counts);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildQuery(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $type = trim((string) ($filters['tipo'] ?? $filters['type'] ?? ''));
        $origin = trim((string) ($filters['origem'] ?? $filters['origin'] ?? ''));
        $clientId = (int) ($filters['cliente_id'] ?? $filters['client_id'] ?? 0);
        $orderId = (int) ($filters['os_id'] ?? $filters['order_id'] ?? 0);

        $query = Budget::query()->with(['client', 'equipment', 'order', 'responsible', 'creator', 'updater']);

        if ($search !== '') {
            $query->withSearch($search);
        }

        if ($status !== '') {
            $query->where('orcamentos.status', $status);
        }

        if ($type !== '') {
            $query->where('orcamentos.tipo_orcamento', $type);
        }

        if ($origin !== '') {
            $query->where('orcamentos.origem', $origin);
        }

        if ($clientId > 0) {
            $query->where('orcamentos.cliente_id', $clientId);
        }

        if ($orderId > 0) {
            $query->where('orcamentos.os_id', $orderId);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, bool $creating): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }

            $normalized[$key] = $value;
        }

        if ($creating && ! array_key_exists('numero', $normalized)) {
            $normalized['numero'] = $this->nextBudgetNumber();
        }

        if (! array_key_exists('versao', $normalized) || (int) ($normalized['versao'] ?? 0) <= 0) {
            $normalized['versao'] = 1;
        }

        if (! array_key_exists('validade_dias', $normalized) || (int) ($normalized['validade_dias'] ?? 0) <= 0) {
            $normalized['validade_dias'] = 10;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveType(array $attributes, bool $fromOrder): string
    {
        if ($fromOrder) {
            return Budget::TYPE_ASSISTANCE;
        }

        $type = strtolower(trim((string) ($attributes['tipo_orcamento'] ?? '')));

        return in_array($type, [Budget::TYPE_PREVIEW, Budget::TYPE_ASSISTANCE], true) ? $type : Budget::TYPE_PREVIEW;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveStatus(array $attributes, bool $creating, ?string $fallback = null): string
    {
        $status = strtolower(trim((string) ($attributes['status'] ?? '')));
        if ($status !== '' && array_key_exists($status, Budget::statusLabels())) {
            return $status;
        }

        return $creating ? Budget::STATUS_DRAFT : ($fallback ?? Budget::STATUS_DRAFT);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveOrigin(array $attributes, bool $fromOrder): string
    {
        if ($fromOrder) {
            return 'os';
        }

        $origin = strtolower(trim((string) ($attributes['origem'] ?? '')));
        return in_array($origin, array_column(Budget::originOptions(), 'value'), true) ? $origin : 'manual';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveClientId(array $attributes, mixed $orderId): ?int
    {
        $clientId = (int) ($attributes['cliente_id'] ?? 0);
        if ($clientId > 0) {
            return $clientId;
        }

        $osId = (int) $orderId;
        if ($osId <= 0) {
            return null;
        }

        $order = Order::query()->find($osId);
        if (! $order instanceof Order) {
            return null;
        }

        return (int) ($order->cliente_id ?? 0) ?: null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveEquipmentId(array $attributes, mixed $orderId): ?int
    {
        $equipmentId = (int) ($attributes['equipamento_id'] ?? 0);
        if ($equipmentId > 0) {
            return $equipmentId;
        }

        $osId = (int) $orderId;
        if ($osId <= 0) {
            return null;
        }

        $order = Order::query()->find($osId);
        if (! $order instanceof Order) {
            return null;
        }

        return (int) ($order->equipamento_id ?? 0) ?: null;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveValidityDate(array $attributes, int $validityDays, mixed $fallback = null): ?string
    {
        $validityDate = trim((string) ($attributes['validade_data'] ?? ''));
        if ($validityDate !== '') {
            return Carbon::parse($validityDate)->toDateString();
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            return Carbon::parse($fallback)->toDateString();
        }

        return now()->addDays(max(0, $validityDays))->toDateString();
    }

    /**
     * @param mixed $value
     */
    private function resolveMoney(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = (string) $value;
        $normalized = str_replace(['R$', '%', ' '], '', $normalized);

        if (str_contains($normalized, ',')) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        }

        return round((float) $normalized, 2);
    }

    private function resolveDecimal(mixed $value, int $scale = 4): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,.\-]/u', '', trim((string) $value)) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '.' || $normalized === ',') {
            return 0.0;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace(',', '', $normalized);
            }
        } elseif ($lastComma !== false) {
            $normalized = str_replace('.', '', $normalized);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($lastDot !== false) {
            $parts = explode('.', $normalized);
            $lastPart = (string) end($parts);

            if (count($parts) > 2 || strlen($lastPart) === 3) {
                $normalized = str_replace('.', '', $normalized);
            }
        }

        return round((float) $normalized, $scale);
    }

    private function resolveAdjustmentMode(mixed $value, string $fallback = Budget::ADJUSTMENT_MODE_VALUE): string
    {
        $mode = strtolower(trim((string) $value));

        return in_array($mode, [Budget::ADJUSTMENT_MODE_VALUE, Budget::ADJUSTMENT_MODE_PERCENT], true)
            ? $mode
            : $fallback;
    }

    /**
     * @return array{mode:string,percent:?float,amount:float}
     */
    private function resolveAdjustment(float $base, mixed $type, mixed $percentual, mixed $amount): array
    {
        $mode = $this->resolveAdjustmentMode($type);
        $percent = $mode === Budget::ADJUSTMENT_MODE_PERCENT
            ? max(0, $this->resolveDecimal($percentual, 4))
            : null;

        if ($mode === Budget::ADJUSTMENT_MODE_PERCENT) {
            return [
                'mode' => $mode,
                'percent' => $percent,
                'amount' => round($base * (($percent ?? 0) / 100), 2),
            ];
        }

        return [
            'mode' => $mode,
            'percent' => null,
            'amount' => max(0, $this->resolveMoney($amount)),
        ];
    }

    private function sumBudgetItems(int $budgetId): float
    {
        return round((float) BudgetItem::query()
            ->where('orcamento_id', $budgetId)
            ->sum('total'), 2);
    }

    private function budgetHasItems(int $budgetId): bool
    {
        return BudgetItem::query()
            ->where('orcamento_id', $budgetId)
            ->exists();
    }

    private function recalculateBudgetFinancials(Budget $budget, ?float $itemsSubtotal = null, mixed $subtotalFallback = null): void
    {
        $subtotal = $itemsSubtotal;

        if ($subtotal === null) {
            $subtotal = $this->budgetHasItems((int) $budget->id)
                ? $this->sumBudgetItems((int) $budget->id)
                : $this->resolveMoney($subtotalFallback ?? $budget->subtotal);
        }

        $discount = $this->resolveAdjustment(
            $subtotal,
            $budget->desconto_tipo,
            $budget->desconto_percentual,
            $budget->desconto
        );
        $addition = $this->resolveAdjustment(
            $subtotal,
            $budget->acrescimo_tipo,
            $budget->acrescimo_percentual,
            $budget->acrescimo
        );

        $budget->updateQuietly([
            'subtotal' => round($subtotal, 2),
            'desconto' => round($discount['amount'], 2),
            'desconto_tipo' => $discount['mode'],
            'desconto_percentual' => $discount['percent'],
            'acrescimo' => round($addition['amount'], 2),
            'acrescimo_tipo' => $addition['mode'],
            'acrescimo_percentual' => $addition['percent'],
            'total' => round(max(0, $subtotal - $discount['amount'] + $addition['amount']), 2),
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function resolveTotal(array $attributes, float $subtotal, float $desconto, float $acrescimo, ?float $fallback = null): float
    {
        if (array_key_exists('total', $attributes) && $attributes['total'] !== null && $attributes['total'] !== '') {
            return $this->resolveMoney($attributes['total']);
        }

        if ($subtotal > 0 || $desconto > 0 || $acrescimo > 0) {
            return round(max(0, $subtotal - $desconto + $acrescimo), 2);
        }

        return round((float) ($fallback ?? 0), 2);
    }

    private function nextBudgetNumber(): string
    {
        $prefix = 'ORC-' . now()->format('ym') . '-';
        $last = Budget::query()
            ->where('numero', 'like', $prefix . '%')
            ->orderByDesc('id')
            ->value('numero');

        $sequence = 1;
        if (is_string($last) && Str::startsWith($last, $prefix)) {
            $sequence = max(1, (int) substr($last, strlen($prefix)) + 1);
        }

        return $prefix . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function statusColor(string $status): string
    {
        foreach (Budget::statusOptions() as $option) {
            if ((string) ($option['value'] ?? '') === $status) {
                return (string) ($option['color'] ?? '#64748b');
            }
        }

        return '#64748b';
    }

    /**
     * @param array<int, mixed> $items
     */
    private function syncItems(Budget $budget, array $items): float
    {
        BudgetItem::query()->where('orcamento_id', $budget->id)->delete();

        $normalizedItems = [];
        $order = 1;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $tipoItem = trim((string) ($item['tipo_item'] ?? 'servico')) ?: 'servico';
            $referenciaId = isset($item['referencia_id']) ? (int) $item['referencia_id'] : null;
            $descricao = trim((string) ($item['descricao'] ?? ''));
            $quantidade = max(0, (float) ($item['quantidade'] ?? 1));
            $valorUnitario = max(0, (float) ($item['valor_unitario'] ?? 0));
            $observacoes = trim((string) ($item['observacoes'] ?? '')) ?: null;

            $referenceData = $this->resolveItemReferenceData($tipoItem, $referenciaId);
            if ($descricao === '' && isset($referenceData['descricao'])) {
                $descricao = (string) $referenceData['descricao'];
            }
            if ($valorUnitario <= 0 && isset($referenceData['valor_unitario'])) {
                $valorUnitario = (float) $referenceData['valor_unitario'];
            }

            $base = round($quantidade * $valorUnitario, 2);
            $discount = $this->resolveAdjustment(
                $base,
                $item['desconto_tipo'] ?? null,
                $item['desconto_percentual'] ?? null,
                $item['desconto'] ?? 0
            );
            $addition = $this->resolveAdjustment(
                $base,
                $item['acrescimo_tipo'] ?? null,
                $item['acrescimo_percentual'] ?? null,
                $item['acrescimo'] ?? 0
            );
            $total = round($base - $discount['amount'] + $addition['amount'], 2);

            $normalizedItems[] = [
                'orcamento_id' => $budget->id,
                'tipo_item' => $tipoItem,
                'referencia_id' => $referenciaId,
                'descricao' => $descricao,
                'quantidade' => $quantidade,
                'valor_unitario' => round($valorUnitario, 2),
                'desconto' => round($discount['amount'], 2),
                'desconto_tipo' => $discount['mode'],
                'desconto_percentual' => $discount['percent'],
                'acrescimo' => round($addition['amount'], 2),
                'acrescimo_tipo' => $addition['mode'],
                'acrescimo_percentual' => $addition['percent'],
                'total' => round($total, 2),
                'ordem' => (int) ($item['ordem'] ?? $order),
                'observacoes' => $observacoes,
                'preco_custo_referencia' => (float) ($referenceData['preco_custo_referencia'] ?? 0),
                'preco_venda_referencia' => (float) ($referenceData['preco_venda_referencia'] ?? 0),
                'preco_base' => (float) ($referenceData['preco_base'] ?? $valorUnitario),
                'percentual_encargos' => (float) ($referenceData['percentual_encargos'] ?? 0),
                'valor_encargos' => (float) ($referenceData['valor_encargos'] ?? 0),
                'percentual_margem' => (float) ($referenceData['percentual_margem'] ?? 0),
                'valor_margem' => (float) ($referenceData['valor_margem'] ?? 0),
                'valor_recomendado' => (float) ($referenceData['valor_recomendado'] ?? 0),
                'modo_precificacao' => (string) ($referenceData['modo_precificacao'] ?? ($item['modo_precificacao'] ?? 'manual')),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $order++;
        }

        if ($normalizedItems === []) {
            return 0.0;
        }

        BudgetItem::query()->insert($normalizedItems);

        return round(array_reduce($normalizedItems, static fn (float $carry, array $item): float => $carry + (float) ($item['total'] ?? 0), 0.0), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveItemReferenceData(string $type, ?int $referenceId): array
    {
        if ($referenceId === null || $referenceId <= 0) {
            return [];
        }

        if ($type === 'servico') {
            $service = Servico::query()->find($referenceId);
            if ($service instanceof Servico) {
                $value = (float) ($service->valor ?? 0);

                return [
                    'descricao' => (string) ($service->nome ?? ''),
                    'valor_unitario' => $value,
                    'preco_base' => $value,
                    'preco_custo_referencia' => (float) ($service->custo_direto_padrao ?? 0),
                    'preco_venda_referencia' => $value,
                    'percentual_encargos' => 0,
                    'valor_encargos' => 0,
                    'percentual_margem' => 0,
                    'valor_margem' => 0,
                    'valor_recomendado' => $value,
                    'modo_precificacao' => 'manual',
                ];
            }
        }

        if ($type === 'peca') {
            $part = Peca::query()->find($referenceId);
            if ($part instanceof Peca) {
                $cost = (float) ($part->preco_custo ?? 0);
                $sale = (float) ($part->preco_venda ?? 0);

                return [
                    'descricao' => (string) ($part->nome ?? ''),
                    'valor_unitario' => $sale > 0 ? $sale : $cost,
                    'preco_base' => $cost > 0 ? $cost : $sale,
                    'preco_custo_referencia' => $cost,
                    'preco_venda_referencia' => $sale,
                    'percentual_encargos' => 0,
                    'valor_encargos' => 0,
                    'percentual_margem' => 0,
                    'valor_margem' => 0,
                    'valor_recomendado' => $sale > 0 ? $sale : $cost,
                    'modo_precificacao' => 'manual',
                ];
            }
        }

        return [];
    }

    private function recordStatusHistory(
        Budget $budget,
        ?string $previousStatus,
        string $newStatus,
        ?string $observacao,
        string $origem,
        ?int $userId
    ): void {
        BudgetStatusHistory::query()->create([
            'orcamento_id' => $budget->id,
            'status_anterior' => $previousStatus,
            'status_novo' => $newStatus,
            'observacao' => $observacao,
            'origem' => $origem,
            'alterado_por' => $userId,
            'created_at' => now(),
        ]);
    }

    private function loadBudget(int $budgetId): ?Budget
    {
        return Budget::query()
            ->with(['client', 'equipment', 'order', 'responsible', 'creator', 'updater', 'items', 'histories.user', 'sends.sender', 'approvals.user'])
            ->find($budgetId);
    }

    private function loadBudgetOrFail(int $budgetId): Budget
    {
        $budget = $this->loadBudget($budgetId);

        if (! $budget instanceof Budget) {
            abort(404);
        }

        return $budget;
    }
}
