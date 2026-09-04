<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetApproval;
use App\Models\BudgetItem;
use App\Models\BudgetSend;
use App\Models\BudgetStatusHistory;
use App\Models\Client;
use App\Models\Equipment;
use App\Models\EquipmentType;
use App\Models\EstoqueCategoria;
use App\Models\EstoqueSubcategoria;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderStatus;
use App\Models\Peca;
use App\Models\Servico;
use App\Support\ModoPrecificacao;
use App\Support\VisibilidadeCusto;
use App\Models\User;
use App\Services\Financeiro\FinanceiroService;
use App\Services\Financeiro\OsMargemService;
use App\Services\Financeiro\PrecificacaoService;
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
        private readonly OsMargemService $osMargemService,
        private readonly BudgetCommercialTermsService $budgetCommercialTermsService,
        private readonly PrecificacaoService $precificacaoService,
        private readonly BudgetRevisionService $budgetRevisionService
    ) {}

    /**
     * Catálogo paginado e mínimo para seletores de cliente do orçamento.
     *
     * A consulta fica no domínio de orçamentos porque o formulário já permite
     * selecionar um cliente mesmo quando o operador não possui acesso ao módulo
     * completo de clientes. Somente os campos indispensáveis ao orçamento são
     * expostos.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateClientOptions(array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($filters['per_page'] ?? 15)));

        $query = Client::query()
            ->select(['id', 'nome_razao', 'cpf_cnpj', 'telefone1', 'email']);

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(static function (Builder $builder) use ($term): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(nome_razao, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(cpf_cnpj, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(telefone1, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$term]);
            });
        }

        $paginator = $query
            ->orderBy('nome_razao')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $paginator->setCollection(
            $paginator->getCollection()->map(static fn (Client $client): array => [
                'id' => (int) $client->id,
                'nome_razao' => trim((string) ($client->nome_razao ?? '')),
                'telefone1' => trim((string) ($client->telefone1 ?? '')),
                'email' => trim((string) ($client->email ?? '')),
            ])
        );

        return $paginator;
    }

    /**
     * OS encerrada (skill sistema-erp-os-fluxo-fechamento): já houve entrega do
     * equipamento e, em geral, lançamento financeiro — editar o orçamento nesse
     * estado exige confirmação de administrador (ver updateBudget()/createBudget()).
     * Usa OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES (mais estreito que
     * closureCodes() — exclui 'cancelado', que pode ser atingido sem nenhum
     * lançamento financeiro real via BudgetOrderSyncService::syncFromBudget()).
     */
    private function isOrderClosed(?Order $order): bool
    {
        return $order instanceof Order
            && in_array(trim((string) $order->status), OrderStatus::FINANCIAL_IMPACT_CLOSURE_CODES, true);
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
     * Catálogo mínimo e paginado usado exclusivamente na abertura de OS.
     *
     * `cliente_id` e `somente_aprovados` atendem a abertura de OS a partir de
     * um orçamento já aprovado: escolhido o cliente, o técnico vê apenas os
     * orçamentos dele que o cliente autorizou — e a OS nasce em
     * "Aguardando Reparo" (ver OrderWorkflowService::pendingOrderStatusForBudgetLink).
     *
     * @return array{paginator: LengthAwarePaginator}
     */
    public function paginateLinkableForOrder(array $filters = []): array
    {
        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $clientId = (int) ($filters['cliente_id'] ?? 0);
        $onlyApproved = filter_var($filters['somente_aprovados'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $perPage = max(1, min(30, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->linkableForOrderQuery();
        if ($clientId > 0) {
            $query->where('orcamentos.cliente_id', $clientId);
        }
        if ($onlyApproved) {
            $query->whereIn('orcamentos.status', Budget::approvedForOrderLinkStatuses());
        }
        if ($search !== '') {
            // Trata curingas como texto do usuário. Isso evita que entradas
            // como "%" ampliem a busca para todo o catálogo e elevem o custo
            // da consulta de contagem/paginação.
            $likeSearch = addcslashes($search, '\\%_');
            $query->where(function (Builder $builder) use ($likeSearch): void {
                $builder
                    ->where('orcamentos.numero', 'like', '%'.$likeSearch.'%')
                    ->orWhere('orcamentos.cliente_nome_avulso', 'like', '%'.$likeSearch.'%')
                    ->orWhere('orcamentos.equipamento_tipo_avulso', 'like', '%'.$likeSearch.'%')
                    ->orWhere('orcamentos.equipamento_marca_avulso', 'like', '%'.$likeSearch.'%')
                    ->orWhere('orcamentos.equipamento_modelo_avulso', 'like', '%'.$likeSearch.'%')
                    ->orWhereHas('client', static function (Builder $clientQuery) use ($likeSearch): void {
                        $clientQuery->where('nome_razao', 'like', '%'.$likeSearch.'%')
                            ->orWhere('cpf_cnpj', 'like', '%'.$likeSearch.'%')
                            ->orWhere('telefone1', 'like', '%'.$likeSearch.'%');
                    })
                    ->orWhereHas('equipment', static function (Builder $equipmentQuery) use ($likeSearch): void {
                        $equipmentQuery->where('resumo_tecnico', 'like', '%'.$likeSearch.'%')
                            ->orWhere('numero_serie', 'like', '%'.$likeSearch.'%')
                            ->orWhere('imei', 'like', '%'.$likeSearch.'%');
                    });
            });
        }

        $paginator = $query
            ->orderByDesc('orcamentos.aprovado_em')
            ->orderByDesc('orcamentos.id')
            ->paginate(perPage: $perPage, page: $page);
        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Budget $budget): array => $this->linkableBudgetListItem($budget)
            )
        );

        return ['paginator' => $paginator];
    }

    /**
     * Catálogo paginado de orçamentos avulsos (sem cliente cadastrado) em
     * aberto, usado para sugerir vínculo ao cadastrar um cliente rápido a
     * partir da Nova OS. Não altera elegibilidade de vínculo — quem decide
     * isso continua sendo linkableForOrderQuery()/validateBudgetForOrderLink.
     *
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator}
     */
    public function paginateAvulsoContacts(array $filters = []): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $perPage = max(1, min(15, (int) ($filters['per_page'] ?? 15)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        $query = $this->avulsoContactsQuery();
        if ($search !== '') {
            $likeSearch = addcslashes($search, '\\%_');
            $query->where(function (Builder $builder) use ($likeSearch): void {
                $builder
                    ->where('orcamentos.cliente_nome_avulso', 'like', '%'.$likeSearch.'%')
                    ->orWhere('orcamentos.telefone_contato', 'like', '%'.$likeSearch.'%');
            });
        }

        $paginator = $query
            ->orderByDesc('orcamentos.id')
            ->paginate(perPage: $perPage, page: $page);
        $paginator->setCollection(
            $paginator->getCollection()->map(
                fn (Budget $budget): array => $this->avulsoContactListItem($budget)
            )
        );

        return ['paginator' => $paginator];
    }

    /**
     * @return array<string, mixed>
     */
    public function showLinkableForOrder(int $budgetId): array
    {
        $budget = $this->linkableForOrderQuery()->find($budgetId);

        if (! $budget instanceof Budget) {
            return ['result' => 'not_found'];
        }

        return [
            'result' => 'ok',
            'budget' => $this->linkableBudgetDetail($budget),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formData(User $user, array $context = []): array
    {
        $selectedClientId = (int) ($context['cliente_id'] ?? 0);
        $selectedOrderId = (int) ($context['os_id'] ?? 0);
        // Orçamento em edição: sua própria OS vinculada nunca deve sumir da
        // lista por já "ter orçamento" — o orçamento é este mesmo.
        $excludeBudgetId = (int) ($context['orcamento_id'] ?? 0);
        $selectedEquipmentId = 0;
        $selectedClientPhone = '';
        $selectedClientEmail = '';
        $selectedOrderDeadline = '';
        $selectedOrderRelato = '';

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
                    $selectedOrderDeadline = 'Previsão: '.$contextOrder->data_previsao->format('d/m/Y');
                }

                $selectedOrderRelato = trim((string) ($contextOrder->relato_cliente ?? ''));
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

        $equipmentQuery = $this->clientEquipmentQuery();

        if ($selectedClientId > 0) {
            $equipmentQuery->where('cliente_id', $selectedClientId);
        }

        $equipments = $equipmentQuery->get()
            ->map(fn (Equipment $equipment): array => $this->mapEquipmentOption($equipment))
            ->values()
            ->all();

        $ordersQuery = $this->clientOrdersQuery();

        if ($selectedClientId > 0) {
            $ordersQuery->where('cliente_id', $selectedClientId);
        }

        // Com uma OS pré-selecionada (orçamento aberto a partir da OS) mostramos
        // só aquela — mesmo encerrada. Sem OS pré-selecionada, listamos apenas as
        // OS abertas (status fora do grupo_macro 'encerrado') que ainda não têm
        // nenhum orçamento vinculado.
        if ($selectedOrderId > 0) {
            $ordersQuery->where('id', $selectedOrderId);
        } else {
            $this->constrainToOpenOrders($ordersQuery);
            $this->excludeOrdersWithExistingBudget($ordersQuery, $excludeBudgetId);
        }

        $orders = $ordersQuery->get()
            ->map(fn (Order $order): array => $this->mapOrderOption($order))
            ->values()
            ->all();

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
                'quantidade_atual' => (float) ($peca->quantidade_atual ?? 0),
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
            'selected_order_relato' => $selectedOrderRelato,
            'clients' => $clients,
            'equipments' => $equipments,
            'orders' => $orders,
            'services' => $services,
            'parts' => $parts,
            'tipos_equipamento' => Servico::tiposEquipamentoAtivos(),
            // Taxonomia de estoque (Grupo → Categoria → Subcategoria) —
            // obrigatória no cadastro rápido de peça deste orçamento. Mesmo
            // formato achatado que EstoqueController::formData() já expõe.
            'grupos' => EquipmentType::query()
                ->where('ativo', 1)
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->map(static fn (EquipmentType $tipo): array => [
                    'id' => (int) $tipo->id,
                    'nome' => (string) $tipo->nome,
                ])
                ->values()
                ->all(),
            'estoque_categorias' => EstoqueCategoria::activeOptions(),
            'estoque_subcategorias' => EstoqueSubcategoria::activeOptions(),
            'status_options' => Budget::statusOptions(),
            'type_options' => Budget::typeOptions(),
            'origin_options' => Budget::originOptions(),
            'condicoes_comerciais_catalogo' => $this->budgetCommercialTermsService->catalog(),
            'default_validity_days' => 10,
        ];
    }

    /**
     * Contexto dependente do cliente escolhido no formulário de orçamento: as OS
     * abertas e os equipamentos cadastrados daquele cliente. Consumido de forma
     * assíncrona quando o usuário troca o cliente no Select2, para que os campos
     * "OS vinculada" e "Equipamento cadastrado" listem apenas o que pertence ao
     * cliente selecionado (e a OS vinculada só apareça se houver OS aberta).
     *
     * @return array{orders: array<int, array<string, mixed>>, equipments: array<int, array<string, mixed>>}
     */
    public function clientContext(int $clientId, int $excludeBudgetId = 0): array
    {
        if ($clientId <= 0) {
            return ['orders' => [], 'equipments' => []];
        }

        $ordersQuery = $this->clientOrdersQuery()->where('cliente_id', $clientId);
        $this->constrainToOpenOrders($ordersQuery);
        $this->excludeOrdersWithExistingBudget($ordersQuery, $excludeBudgetId);

        $orders = $ordersQuery->get()
            ->map(fn (Order $order): array => $this->mapOrderOption($order))
            ->values()
            ->all();

        $equipments = $this->clientEquipmentQuery()
            ->where('cliente_id', $clientId)
            ->get()
            ->map(fn (Equipment $equipment): array => $this->mapEquipmentOption($equipment))
            ->values()
            ->all();

        return [
            'orders' => $orders,
            'equipments' => $equipments,
        ];
    }

    /**
     * @return Builder<Equipment>
     */
    private function clientEquipmentQuery(): Builder
    {
        return Equipment::query()
            ->with(['client', 'type', 'brand', 'model', 'photos' => static function ($photoQuery): void {
                $photoQuery->select(['id', 'equipamento_id', 'is_principal']);
            }])
            ->select(['id', 'cliente_id', 'tipo_id', 'marca_id', 'modelo_id', 'resumo_tecnico', 'numero_serie', 'imei', 'status'])
            ->orderByDesc('id')
            ->limit(80);
    }

    /**
     * @return Builder<Order>
     */
    private function clientOrdersQuery(): Builder
    {
        return Order::query()
            ->with(['client', 'equipment'])
            ->select(['id', 'numero_os', 'cliente_id', 'equipamento_id', 'status', 'estado_fluxo', 'data_abertura', 'relato_cliente'])
            ->orderByDesc('id')
            ->limit(80);
    }

    /**
     * Restringe a query de OS às abertas — status fora do grupo_macro
     * 'encerrado' (ver OrderStatus::closureCodes()). Se o catálogo não devolver
     * nenhum código de encerramento, mantém a query intacta.
     *
     * @param  Builder<Order>  $query
     */
    private function constrainToOpenOrders(Builder $query): void
    {
        $closureCodes = OrderStatus::closureCodes();

        if ($closureCodes !== []) {
            $query->whereNotIn('status', $closureCodes);
        }
    }

    /**
     * Exclui OS que já têm QUALQUER orçamento vinculado (independente do
     * status — inclusive rascunho, rejeitado, vencido ou cancelado): o
     * histórico de orçamentos é por OS, então uma nova OS deve ser aberta (ou
     * o orçamento existente reaproveitado/excluído) em vez de vincular um
     * segundo orçamento à mesma OS. $excludeBudgetId preserva a própria OS do
     * orçamento em edição, que não deve sumir da lista por já "ter orçamento"
     * — o orçamento é este mesmo.
     *
     * @param  Builder<Order>  $query
     */
    private function excludeOrdersWithExistingBudget(Builder $query, int $excludeBudgetId = 0): void
    {
        $query->whereNotIn('id', function ($subQuery) use ($excludeBudgetId): void {
            $subQuery->select('os_id')
                ->from('orcamentos')
                ->whereNotNull('os_id');

            if ($excludeBudgetId > 0) {
                $subQuery->where('id', '!=', $excludeBudgetId);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEquipmentOption(Equipment $equipment): array
    {
        return [
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
            'foto_principal_id' => $this->principalPhotoId($equipment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapOrderOption(Order $order): array
    {
        return [
            'id' => (int) $order->id,
            'numero_os' => (string) ($order->numero_os ?? ''),
            'cliente_id' => (int) ($order->cliente_id ?? 0),
            'cliente_nome' => (string) ($order->client?->nome_razao ?? ''),
            'equipamento_id' => (int) ($order->equipamento_id ?? 0),
            'equipamento_resumo' => (string) ($order->equipment?->resumo_tecnico ?? ''),
            'relato_cliente' => (string) ($order->relato_cliente ?? ''),
            'status' => (string) ($order->status ?? ''),
            'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
            'data_abertura' => optional($order->data_abertura)->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * ID da foto principal do equipamento (0 quando não há foto). Prioriza a
     * marcada como principal; na ausência, a de menor id (a mais antiga).
     */
    private function principalPhotoId(Equipment $equipment): int
    {
        $photos = $equipment->relationLoaded('photos') ? $equipment->photos : $equipment->photos()->get();

        if ($photos->isEmpty()) {
            return 0;
        }

        $principal = $photos->firstWhere('is_principal', true)
            ?? $photos->sortBy('id')->first();

        return (int) ($principal->id ?? 0);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createBudget(User $user, array $payload, ?User $verifiedAdmin = null): array
    {
        return DB::transaction(function () use ($user, $payload, $verifiedAdmin): array {
            $attributes = $this->normalizePayload($payload, true);
            $budgetAttributes = $attributes;
            unset($budgetAttributes['itens'], $budgetAttributes['formas_pagamento']);

            $osId = (int) ($budgetAttributes['os_id'] ?? 0);
            if ($osId > 0) {
                $order = Order::query()->find($osId);
                if ($this->isOrderClosed($order) && ! ($verifiedAdmin instanceof User)) {
                    return ['result' => 'requires_admin_confirmation'];
                }
            }

            $budget = new Budget;
            $budget->fill($budgetAttributes);
            $budget->numero = (string) ($budgetAttributes['numero'] ?? $this->nextBudgetNumber());
            $budget->versao = max(1, (int) ($budgetAttributes['versao'] ?? 1));
            $budget->tipo_orcamento = $this->resolveType($budgetAttributes, $osId > 0);
            $budget->status = $this->resolveStatus($budgetAttributes, true);
            $budget->origem = $this->resolveOrigin($budgetAttributes, $osId > 0);
            $budget->cliente_id = $this->resolveClientId($budgetAttributes, $budget->os_id);
            $budget->equipamento_id = $this->resolveEquipmentId($budgetAttributes, $budget->os_id);
            $budget->envolve_equipamento = $this->resolveEnvolveEquipamento($budgetAttributes, true);
            $this->applyClientEquipmentExclusivity($budget);
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
            $paymentCodes = $this->budgetCommercialTermsService->normalizeCodes(
                is_array($attributes['formas_pagamento'] ?? null) ? $attributes['formas_pagamento'] : []
            );
            $budget->garantia_dias = $this->budgetCommercialTermsService
                ->normalizeWarrantyDays($budgetAttributes['garantia_dias'] ?? null);
            $budget->parcelas_sem_juros = $this->budgetCommercialTermsService
                ->normalizeInstallments($budgetAttributes['parcelas_sem_juros'] ?? null, $paymentCodes);
            $budget->total = 0;
            $budget->save();

            $this->budgetCommercialTermsService->syncPaymentMethods($budget, $paymentCodes);

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
                    'route' => '/orcamentos/'.(int) $budget->id,
                    'icon' => 'receipt',
                    'orcamento_id' => (int) $budget->id,
                    'os_id' => (int) ($budget->os_id ?? 0),
                ]
            );

            return $this->budgetDetail($this->loadBudgetOrFail((int) $budget->id));
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateBudget(int $budgetId, User $user, array $payload, ?User $verifiedAdmin = null): array
    {
        return DB::transaction(function () use ($budgetId, $user, $payload, $verifiedAdmin): array {
            $budget = $this->loadBudgetForUpdate($budgetId);

            if (! $budget instanceof Budget) {
                return ['result' => 'not_found'];
            }

            if ((string) ($budget->status ?? '') === Budget::STATUS_CONVERTED) {
                return $this->updateConvertedBudget($budget, $user, $payload, $verifiedAdmin);
            }

            $order = $budget->order; // já eager-loaded por loadBudget()
            $osClosed = $this->isOrderClosed($order);

            if ($osClosed && ! ($verifiedAdmin instanceof User)) {
                return ['result' => 'requires_admin_confirmation'];
            }

            $attributes = $this->normalizePayload($payload, false);
            $budgetAttributes = $attributes;
            unset($budgetAttributes['itens'], $budgetAttributes['formas_pagamento']);
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
            $budget->envolve_equipamento = $this->resolveEnvolveEquipamento(
                $budgetAttributes,
                (bool) ($budget->getOriginal('envolve_equipamento') ?? true)
            );
            $this->applyClientEquipmentExclusivity($budget);
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
            // Payload parcial (ex.: mudança só de status) não pode apagar as
            // condições comerciais já acordadas: cada campo só é tocado quando
            // vem explicitamente na requisição.
            $paymentCodes = array_key_exists('formas_pagamento', $attributes)
                ? $this->budgetCommercialTermsService->normalizeCodes(
                    is_array($attributes['formas_pagamento']) ? $attributes['formas_pagamento'] : []
                )
                : $budget->paymentMethods->sortBy('ordem')->pluck('forma_codigo')->map(strval(...))->all();

            if (array_key_exists('garantia_dias', $budgetAttributes)) {
                $budget->garantia_dias = $this->budgetCommercialTermsService
                    ->normalizeWarrantyDays($budgetAttributes['garantia_dias']);
            }

            if (array_key_exists('parcelas_sem_juros', $budgetAttributes) || array_key_exists('formas_pagamento', $attributes)) {
                $budget->parcelas_sem_juros = $this->budgetCommercialTermsService->normalizeInstallments(
                    $budgetAttributes['parcelas_sem_juros'] ?? $budget->parcelas_sem_juros,
                    $paymentCodes
                );
            }

            $budget->total = $previousTotal;
            $budget->save();

            if (array_key_exists('formas_pagamento', $attributes)) {
                $this->budgetCommercialTermsService->syncPaymentMethods($budget, $paymentCodes);
            }

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
                    $descricao = 'Edição em OS encerrada autorizada por administrador. '.$descricao;
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
     * Edição de um orçamento já `convertido`: chamado de dentro da mesma
     * transação de updateBudget() (não abre transação própria). Campos
     * operacionais (Budget::CONVERTED_EDITABLE_FIELDS) aplicam direto, sem
     * aprovação nova. Campos financeiros/cliente
     * (Budget::CONVERTED_REVISION_FIELDS) exigem uma revisão aprovada pelo
     * cliente (BudgetRevisionService) — nunca são aplicados neste método.
     * Qualquer outro campo, se vier com valor diferente do atual, é rejeitado
     * (o orçamento convertido só pode mudar nesses dois grupos).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function updateConvertedBudget(Budget $budget, User $user, array $payload, ?User $verifiedAdmin): array
    {
        $order = $budget->order; // já eager-loaded por loadBudgetForUpdate()

        // Macrofase da OS encerrada ou cancelada: nenhuma mudança (nem
        // operacional, nem de revisão) sem confirmação de administrador —
        // mesmo padrão de bypass já usado para OS encerrada em
        // updateBudget(), só que aqui o critério é mais amplo (grupo_macro
        // 'encerrado' inteiro + 'cancelado', não só o subconjunto com
        // impacto financeiro).
        if ($this->budgetApprovalService->orderIsSettled($budget) && ! ($verifiedAdmin instanceof User)) {
            return ['result' => 'requires_admin_confirmation_converted'];
        }

        $attributes = $this->normalizePayload($payload, false);

        $allowedKeys = array_merge(Budget::CONVERTED_EDITABLE_FIELDS, Budget::CONVERTED_REVISION_FIELDS);
        $technicalKeys = ['admin_email', 'admin_password', 'propor_revisao', 'numero', 'versao', 'status'];
        $violations = [];
        foreach ($attributes as $key => $value) {
            if (in_array($key, $allowedKeys, true) || in_array($key, $technicalKeys, true)) {
                continue;
            }
            if (! $this->convertedBudgetValueMatches($budget, $key, $value)) {
                $violations[] = $key;
            }
        }
        if ($violations !== []) {
            return [
                'result' => 'validation_error',
                'message' => 'Orçamento convertido: campo não editável fora da lista permitida.',
                'details' => ['fields' => $violations],
            ];
        }

        $revisionDiffFields = [];
        foreach (Budget::CONVERTED_REVISION_FIELDS as $field) {
            if ($field === 'itens' || ! array_key_exists($field, $attributes)) {
                continue;
            }
            if (! $this->convertedBudgetValueMatches($budget, $field, $attributes[$field])) {
                $revisionDiffFields[] = $field;
            }
        }
        $itemsChanged = array_key_exists('itens', $attributes)
            && $this->convertedBudgetItemsDiffer($budget, is_array($attributes['itens']) ? $attributes['itens'] : []);
        if ($itemsChanged) {
            $revisionDiffFields[] = 'itens';
        }

        if ($revisionDiffFields !== []) {
            if (! filter_var($attributes['propor_revisao'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return ['result' => 'confirmation_required', 'fields' => $revisionDiffFields];
            }

            if ($this->budgetRevisionService->hasUnresolvedRevision($budget)) {
                $pending = $this->budgetRevisionService->pendingRevisionFor($budget);

                return ['result' => 'revision_conflict', 'revision_id' => (int) ($pending?->id ?? 0)];
            }

            $revisionAttributes = array_intersect_key($attributes, array_flip(Budget::CONVERTED_REVISION_FIELDS));
            unset($revisionAttributes['itens']);

            $revision = $this->budgetRevisionService->spawnRevision($budget, $user, $revisionAttributes);

            if ($itemsChanged) {
                $itemsSubtotal = $this->syncItems($revision, is_array($attributes['itens']) ? $attributes['itens'] : []);
                $this->recalculateBudgetFinancials($revision, $itemsSubtotal, $revisionAttributes['subtotal'] ?? $revision->subtotal);
            }

            return ['result' => 'revision_created', 'revision' => $this->budgetListItem($revision->fresh())]
                + $this->budgetDetail($this->loadBudgetOrFail((int) $budget->id));
        }

        // Sem mudança de valor/cliente: aplica os campos operacionais direto,
        // sem passar por revisão nem tocar em status/sincronização de OS.
        if (array_key_exists('telefone_contato', $attributes)) {
            $budget->telefone_contato = $attributes['telefone_contato'];
        }
        if (array_key_exists('email_contato', $attributes)) {
            $budget->email_contato = $attributes['email_contato'];
        }
        if (array_key_exists('relato_cliente', $attributes)) {
            $budget->relato_cliente = $attributes['relato_cliente'];
        }
        if (array_key_exists('prazo_execucao', $attributes)) {
            $budget->prazo_execucao = $attributes['prazo_execucao'];
        }

        if (array_key_exists('validade_data', $attributes) || array_key_exists('validade_dias', $attributes)) {
            $newValidityDays = max(0, (int) ($attributes['validade_dias'] ?? $budget->validade_dias ?? 10));
            $newValidityDate = $this->resolveValidityDate($attributes, $newValidityDays, $budget->validade_data);
            $currentValidityDate = $budget->validade_data instanceof Carbon
                ? $budget->validade_data->toDateString()
                : trim((string) $budget->validade_data);

            if ($currentValidityDate !== '' && $newValidityDate !== null && $newValidityDate < $currentValidityDate) {
                return [
                    'result' => 'validation_error',
                    'message' => 'A validade de um orçamento convertido só pode ser adiada, nunca antecipada.',
                    'details' => ['fields' => ['validade_data']],
                ];
            }

            $budget->validade_dias = $newValidityDays;
            $budget->validade_data = $newValidityDate;
        }

        if (array_key_exists('envolve_equipamento', $attributes)) {
            $budget->envolve_equipamento = $this->resolveEnvolveEquipamento(
                $attributes,
                (bool) ($budget->envolve_equipamento ?? true)
            );
        }
        $budget->equipamento_id = $this->resolveEquipmentId($attributes, $budget->os_id);
        foreach ([
            'equipamento_tipo_id', 'equipamento_marca_id', 'equipamento_modelo_id',
            'equipamento_tipo_avulso', 'equipamento_marca_avulso', 'equipamento_modelo_avulso',
            'equipamento_cor', 'equipamento_cor_hex', 'equipamento_cor_rgb',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $budget->{$field} = $attributes[$field];
            }
        }
        $this->applyClientEquipmentExclusivity($budget);

        $garantiaChanged = false;
        if (array_key_exists('garantia_dias', $attributes)) {
            $normalizedGarantia = $this->budgetCommercialTermsService->normalizeWarrantyDays($attributes['garantia_dias']);
            $garantiaChanged = $normalizedGarantia !== (int) ($budget->garantia_dias ?? 0);
            $budget->garantia_dias = $normalizedGarantia;
        }

        $paymentCodes = array_key_exists('formas_pagamento', $attributes)
            ? $this->budgetCommercialTermsService->normalizeCodes(
                is_array($attributes['formas_pagamento']) ? $attributes['formas_pagamento'] : []
            )
            : null;

        if ($paymentCodes !== null || array_key_exists('parcelas_sem_juros', $attributes)) {
            $currentCodes = $paymentCodes ?? $budget->paymentMethods->sortBy('ordem')->pluck('forma_codigo')->map(strval(...))->all();
            $budget->parcelas_sem_juros = $this->budgetCommercialTermsService->normalizeInstallments(
                $attributes['parcelas_sem_juros'] ?? $budget->parcelas_sem_juros,
                $currentCodes
            );
        }

        $budget->atualizado_por = (int) $user->id;
        $budget->save();

        if ($paymentCodes !== null) {
            $this->budgetCommercialTermsService->syncPaymentMethods($budget, $paymentCodes);
        }

        // Prazo de garantia é o que vai para o termo de garantia impresso na
        // entrega (`{{ os.garantia_dias }}`) — uma correção deliberada no
        // orçamento precisa refletir na OS, não só ficar registrada aqui.
        if ($garantiaChanged && $order instanceof Order) {
            $order->forceFill(['garantia_dias' => (int) ($budget->garantia_dias ?? 0)])->save();
        }

        if ((int) ($budget->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $budget->os_id,
                OrderEvent::CATEGORIA_ORCAMENTO,
                OrderEvent::TIPO_ORCAMENTO_ATUALIZADO,
                'Orçamento atualizado',
                sprintf('Orçamento convertido %s atualizado (campos operacionais).', $budget->numero),
                [
                    'orcamento_id' => (int) $budget->id,
                    'numero' => (string) $budget->numero,
                ],
                (int) $user->id
            );
        }

        // Nunca chama syncFromBudget()/osMargemService aqui: status não muda
        // e nada acima toca em valor — não há nada de OS para ressincronizar.
        return $this->budgetDetail($this->loadBudgetOrFail((int) $budget->id));
    }

    private function convertedBudgetValueMatches(Budget $budget, string $key, mixed $value): bool
    {
        $current = $budget->getAttribute($key);

        if ($current instanceof Carbon) {
            $current = $current->toDateString();
        }

        $normalizedValue = is_string($value) ? trim($value) : $value;
        $normalizedCurrent = is_string($current) ? trim($current) : $current;

        if (is_bool($normalizedCurrent) || is_bool($normalizedValue)) {
            return filter_var($normalizedValue, FILTER_VALIDATE_BOOLEAN) === filter_var($normalizedCurrent, FILTER_VALIDATE_BOOLEAN);
        }

        if (is_numeric($normalizedValue) && is_numeric($normalizedCurrent)) {
            return abs((float) $normalizedValue - (float) $normalizedCurrent) < 0.0001;
        }

        return (string) ($normalizedValue ?? '') === (string) ($normalizedCurrent ?? '');
    }

    /**
     * Diferença estrutural entre os itens submetidos e os itens atuais do
     * orçamento (descrição/quantidade/valor unitário/desconto/acréscimo por
     * linha, além da contagem) — usado só para decidir se um envio de
     * orçamento convertido precisa virar uma revisão. Não recalcula preço:
     * quem faz isso é syncItems(), só chamado depois que já se sabe que uma
     * revisão será criada.
     *
     * @param  array<int, mixed>  $submittedItems
     */
    private function convertedBudgetItemsDiffer(Budget $budget, array $submittedItems): bool
    {
        $current = $budget->items->sortBy('ordem')->values();

        if (count($submittedItems) !== $current->count()) {
            return true;
        }

        foreach (array_values($submittedItems) as $index => $item) {
            if (! is_array($item)) {
                return true;
            }

            $existing = $current->get($index);
            if (! $existing instanceof BudgetItem) {
                return true;
            }

            $descricao = trim((string) ($item['descricao'] ?? ''));
            $quantidade = (float) ($item['quantidade'] ?? 0);
            $valorUnitario = (float) ($item['valor_unitario'] ?? 0);
            $desconto = (float) ($item['desconto'] ?? 0);
            $acrescimo = (float) ($item['acrescimo'] ?? 0);

            if (
                $descricao !== trim((string) $existing->descricao)
                || abs($quantidade - (float) $existing->quantidade) > 0.0001
                || abs($valorUnitario - (float) $existing->valor_unitario) > 0.009
                || abs($desconto - (float) $existing->desconto) > 0.009
                || abs($acrescimo - (float) $existing->acrescimo) > 0.009
            ) {
                return true;
            }
        }

        return false;
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
        return $this->financeiroService->correctReceivableTitleForOrder($order, $novoTotal);
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteBudget(int $budgetId, User $user): array
    {
        return DB::transaction(function () use ($budgetId, $user): array {
            $budget = Budget::query()
                ->with(['items', 'histories', 'sends', 'approvals'])
                ->lockForUpdate()
                ->find($budgetId);

            if (! $budget instanceof Budget) {
                return ['result' => 'not_found'];
            }

            $status = (string) ($budget->status ?? Budget::STATUS_DRAFT);
            if ($status === Budget::STATUS_CONVERTED) {
                return ['result' => 'immutable'];
            }

            if (! in_array($status, [
                Budget::STATUS_DRAFT,
                Budget::STATUS_REJECTED,
                Budget::STATUS_CANCELLED,
            ], true)) {
                return ['result' => 'not_deletable'];
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
    private function linkableForOrderQuery(): Builder
    {
        return Budget::query()
            ->with([
                'client:id,nome_razao',
                'equipment:id,cliente_id,resumo_tecnico',
            ])
            ->where('orcamentos.tipo_orcamento', Budget::TYPE_PREVIEW)
            ->whereIn('orcamentos.status', Budget::linkableToOrderStatuses())
            ->whereNull('orcamentos.os_id')
            ->whereNull('orcamentos.orcamento_revisao_de_id');
    }

    /**
     * Orçamentos avulsos (sem cliente cadastrado) ainda ativos — exclui
     * rascunho e estados terminais (recusado/vencido/cancelado/convertido),
     * que não representam mais um atendimento em aberto.
     */
    private function avulsoContactsQuery(): Builder
    {
        return Budget::query()
            ->where('orcamentos.tipo_orcamento', Budget::TYPE_PREVIEW)
            ->whereNull('orcamentos.cliente_id')
            ->whereNotIn('orcamentos.status', [
                Budget::STATUS_DRAFT,
                Budget::STATUS_REJECTED,
                Budget::STATUS_EXPIRED,
                Budget::STATUS_CANCELLED,
                Budget::STATUS_CONVERTED,
            ]);
    }

    private function isLinkableForOrder(Budget $budget): bool
    {
        return (string) ($budget->tipo_orcamento ?? '') === Budget::TYPE_PREVIEW
            && in_array((string) ($budget->status ?? ''), Budget::linkableToOrderStatuses(), true)
            && (int) ($budget->os_id ?? 0) <= 0
            // Defesa em profundidade: uma revisão de orçamento convertido
            // (ver BudgetRevisionService) sempre herda o os_id do base, então
            // o check acima já bastaria — mas nunca deve virar origem de uma
            // segunda OS mesmo se esse invariante mudar.
            && (int) ($budget->orcamento_revisao_de_id ?? 0) <= 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function linkableBudgetListItem(Budget $budget): array
    {
        $clientName = trim((string) ($budget->client?->nome_razao ?? $budget->cliente_nome_avulso ?? ''));
        $equipmentLabel = trim((string) ($budget->equipment?->resumo_tecnico ?? ''))
            ?: $this->eventualEquipmentLabel($budget);

        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-'.(int) $budget->id)),
            'cliente_id' => (int) ($budget->cliente_id ?? 0) ?: null,
            'cliente_nome' => $clientName,
            // O equipamento do orçamento é obrigatório na OS gerada a partir
            // dele (validateBudgetForOrderLink); o cliente precisa dele para
            // já selecionar o mesmo equipamento no formulário.
            'equipamento_id' => (int) ($budget->equipamento_id ?? 0) ?: null,
            'equipamento_resumo' => $equipmentLabel,
            'total' => round((float) ($budget->total ?? 0), 2),
            'total_formatado' => number_format((float) ($budget->total ?? 0), 2, ',', '.'),
            'aprovado_em' => optional($budget->aprovado_em)->format('d/m/Y H:i'),
            'status' => (string) ($budget->status ?? ''),
            'status_label' => Budget::statusLabel($budget->status),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function avulsoContactListItem(Budget $budget): array
    {
        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-'.(int) $budget->id)),
            'cliente_nome_avulso' => trim((string) ($budget->cliente_nome_avulso ?? '')),
            'telefone_contato' => trim((string) ($budget->telefone_contato ?? '')),
            'email_contato' => trim((string) ($budget->email_contato ?? '')),
            'equipamento_resumo' => $this->eventualEquipmentLabel($budget),
            'total_formatado' => number_format((float) ($budget->total ?? 0), 2, ',', '.'),
            'status' => (string) ($budget->status ?? ''),
            'status_label' => Budget::statusLabel($budget->status),
            'linkable' => in_array((string) ($budget->status ?? ''), Budget::linkableToOrderStatuses(), true),
        ];
    }

    /**
     * Retorna somente o contexto necessário para pré-preencher a Nova OS.
     *
     * @return array<string, mixed>
     */
    private function linkableBudgetDetail(Budget $budget): array
    {
        return array_merge($this->linkableBudgetListItem($budget), [
            'tipo_orcamento' => (string) $budget->tipo_orcamento,
            'status' => (string) $budget->status,
            'relato_cliente' => (string) ($budget->relato_cliente ?? ''),
            'cliente_nome_avulso' => (string) ($budget->cliente_nome_avulso ?? ''),
            'telefone_contato' => (string) ($budget->telefone_contato ?? ''),
            'email_contato' => (string) ($budget->email_contato ?? ''),
            'envolve_equipamento' => (bool) ($budget->envolve_equipamento ?? true),
            'equipamento_tipo_avulso' => (string) ($budget->equipamento_tipo_avulso ?? ''),
            'equipamento_marca_avulso' => (string) ($budget->equipamento_marca_avulso ?? ''),
            'equipamento_modelo_avulso' => (string) ($budget->equipamento_modelo_avulso ?? ''),
            'equipamento_cor' => (string) ($budget->equipamento_cor ?? ''),
            'equipamento_eventual_label' => $this->eventualEquipmentLabel($budget),
            'cliente' => $budget->client ? [
                'id' => (int) $budget->client->id,
                'nome_razao' => (string) ($budget->client->nome_razao ?? ''),
            ] : null,
            'equipamento' => $budget->equipment ? [
                'id' => (int) $budget->equipment->id,
            ] : null,
        ]);
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
        $canSendApproval = in_array($status, [
            Budget::STATUS_DRAFT,
            Budget::STATUS_PENDING_SEND,
            Budget::STATUS_SENT,
            Budget::STATUS_WAITING_REPLY,
            Budget::STATUS_PENDING,
            Budget::STATUS_RESEND,
        ], true);
        // Orçamento já decidido: o dropdown troca "enviar para aprovação" por
        // "enviar para o cliente consultar" — mesmo mecanismo, sem reabrir a
        // decisão (ver BudgetApprovalService::dispatchForApproval).
        $canSendClientView = in_array($status, Budget::approvedForOrderLinkStatuses(), true);
        $isRevision = $this->budgetRevisionService->isRevision($budget);
        $pendingRevision = $status === Budget::STATUS_CONVERTED
            ? $this->budgetRevisionService->pendingRevisionFor($budget)
            : null;

        $links = [];
        if ((int) ($budget->os_id ?? 0) > 0) {
            $links[] = 'OS '.(string) ($order?->numero_os ?? ('#'.(int) $budget->os_id));
        }
        if ((int) ($budget->equipamento_id ?? 0) > 0) {
            $links[] = 'Equipamento #'.(int) $budget->equipamento_id;
        }
        if ((int) ($budget->conversa_id ?? 0) > 0) {
            $links[] = 'Conversa #'.(int) $budget->conversa_id;
        }

        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-'.(int) $budget->id)),
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
            'equipamento_resumo' => trim((string) ($equipment?->resumo_tecnico ?? '')) ?: $this->eventualEquipmentLabel($budget),
            'envolve_equipamento' => (bool) ($budget->envolve_equipamento ?? true),
            'os_numero' => trim((string) ($order?->numero_os ?? '')),
            'vinculos' => implode(' | ', $links),
            'telefone_contato' => trim((string) ($budget->telefone_contato ?? '')),
            'email_contato' => trim((string) ($budget->email_contato ?? '')),
            'cliente_email' => trim((string) ($client?->email ?? '')),
            'enviado_em' => optional($budget->enviado_em)->format('d/m/Y H:i'),
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
            // Convertido também pode ser editado agora (edição limitada — ver
            // BudgetWorkflowService::updateConvertedBudget()), então não
            // exclui mais nenhum status daqui.
            'can_edit' => true,
            'can_delete' => in_array($status, [Budget::STATUS_DRAFT, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_approve' => ! in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_reject' => ! in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_cancel' => ! in_array($status, [Budget::STATUS_CONVERTED, Budget::STATUS_CANCELLED], true),
            'can_generate_os' => $this->isLinkableForOrder($budget),
            'can_send_approval' => $canSendApproval,
            'can_send_client_view' => $canSendClientView,
            'is_revision' => $isRevision,
            'revision_base' => $isRevision && $budget->revisionBase instanceof Budget ? [
                'id' => (int) $budget->revisionBase->id,
                'numero' => (string) $budget->revisionBase->numero,
            ] : null,
            'has_pending_revision' => $pendingRevision instanceof Budget,
            'pending_revision' => $pendingRevision instanceof Budget ? [
                'id' => (int) $pendingRevision->id,
                'numero' => (string) $pendingRevision->numero,
                'status' => (string) $pendingRevision->status,
                'status_label' => Budget::statusLabel($pendingRevision->status),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function budgetDetail(Budget $budget): array
    {
        // Visibilidade de custo do usuario da requisicao (specs/037). Resolvida
        // uma vez aqui e capturada pelas arrow functions do mapeamento.
        $veCusto = VisibilidadeCusto::mostraNumero(
            VisibilidadeCusto::paraUsuario(auth()->user())
        );

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
        $canSendClientView = in_array($status, Budget::approvedForOrderLinkStatuses(), true);
        $publicLink = $canSendApproval || $canSendClientView || trim((string) ($budget->token_publico ?? '')) !== ''
            ? $this->budgetApprovalService->ensurePublicApprovalUrl($budget)
            : '';
        $isRevision = $this->budgetRevisionService->isRevision($budget);
        $revisionBase = $isRevision ? $budget->revisionBase : null;
        $pendingRevision = $status === Budget::STATUS_CONVERTED
            ? $this->budgetRevisionService->pendingRevisionFor($budget)
            : null;

        return [
            'id' => (int) $budget->id,
            'numero' => (string) ($budget->numero ?? ('ORC-'.(int) $budget->id)),
            'versao' => (int) ($budget->versao ?? 1),
            'tipo_orcamento' => (string) ($budget->tipo_orcamento ?? Budget::TYPE_PREVIEW),
            'tipo_label' => Budget::typeLabel($budget->tipo_orcamento),
            'status' => $status,
            'status_label' => Budget::statusLabel($status),
            'status_color' => $this->statusColor($status),
            'origem' => (string) ($budget->origem ?? 'manual'),
            'origem_label' => Budget::originLabel($budget->origem),
            'titulo' => (string) ($budget->titulo ?? ''),
            'relato_cliente' => (string) ($budget->relato_cliente ?? ''),
            'cliente_nome_avulso' => (string) ($budget->cliente_nome_avulso ?? ''),
            'telefone_contato' => (string) ($budget->telefone_contato ?? ''),
            'email_contato' => (string) ($budget->email_contato ?? ''),
            'envolve_equipamento' => (bool) ($budget->envolve_equipamento ?? true),
            'equipamento_tipo_avulso' => (string) ($budget->equipamento_tipo_avulso ?? ''),
            'equipamento_marca_avulso' => (string) ($budget->equipamento_marca_avulso ?? ''),
            'equipamento_modelo_avulso' => (string) ($budget->equipamento_modelo_avulso ?? ''),
            'equipamento_cor' => (string) ($budget->equipamento_cor ?? ''),
            'equipamento_eventual_label' => $this->eventualEquipmentLabel($budget),
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
            'garantia_dias' => $budget->garantia_dias !== null ? (int) $budget->garantia_dias : null,
            'garantia_label' => Budget::warrantyLabel($budget->garantia_dias),
            'parcelas_sem_juros' => $budget->parcelas_sem_juros !== null ? (int) $budget->parcelas_sem_juros : null,
            'condicoes_comerciais' => $this->budgetCommercialTermsService->forBudget($budget),
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
                'tipo_nome' => (string) ($equipment->type?->nome ?? ''),
                'marca_nome' => (string) ($equipment->brand?->nome ?? ''),
                'modelo_nome' => (string) ($equipment->model?->nome ?? ''),
                'numero_serie' => (string) ($equipment->numero_serie ?? ''),
                'imei' => (string) ($equipment->imei ?? ''),
            ] : null,
            'os' => $order ? [
                'id' => (int) $order->id,
                'numero_os' => (string) ($order->numero_os ?? ''),
                'status' => (string) ($order->status ?? ''),
                'estado_fluxo' => (string) ($order->estado_fluxo ?? ''),
                'is_encerrada' => $this->isOrderClosed($order),
                // Mais amplo que is_encerrada: cobre a macrofase 'encerrado'
                // inteira (não só o subconjunto com impacto financeiro) mais
                // 'cancelado'. É o gate usado na edição de orçamento
                // convertido (ver BudgetWorkflowService::
                // updateConvertedBudget() e BudgetApprovalService::
                // orderIsSettled()); is_encerrada continua sendo o gate de
                // pré-conversão, sem mudança.
                'os_settled' => $this->budgetApprovalService->orderIsSettled($budget),
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
                // `valor_recomendado` e `modo_precificacao` valem para todos: o
                // primeiro e o piso (quem vende precisa saber que passou dele) e
                // o segundo nao revela custo. O resto e composicao de custo e
                // some para quem nao tem permissao financeira.
                //
                // Redigido AQUI, no payload: ate a Fase 4 estes campos eram
                // zeros e o vazamento era inocuo. Agora carregam custo real.
                'valor_recomendado' => (float) ($item->valor_recomendado ?? 0),
                'modo_precificacao' => (string) ($item->modo_precificacao ?? ''),
            ] + ($veCusto ? [
                'preco_custo_referencia' => (float) ($item->preco_custo_referencia ?? 0),
                'preco_venda_referencia' => (float) ($item->preco_venda_referencia ?? 0),
                'preco_base' => (float) ($item->preco_base ?? 0),
                'percentual_encargos' => (float) ($item->percentual_encargos ?? 0),
                'valor_encargos' => (float) ($item->valor_encargos ?? 0),
                'percentual_margem' => (float) ($item->percentual_margem ?? 0),
                'valor_margem' => (float) ($item->valor_margem ?? 0),
            ] : []))->all(),
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
            // Convertido também pode ser editado agora (edição limitada — ver
            // BudgetWorkflowService::updateConvertedBudget()), então não
            // exclui mais nenhum status daqui.
            'can_edit' => true,
            'can_delete' => in_array($status, [Budget::STATUS_DRAFT, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_send_approval' => $canSendApproval,
            'can_send_client_view' => $canSendClientView,
            'can_approve' => ! in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_reject' => ! in_array($status, [Budget::STATUS_APPROVED, Budget::STATUS_PENDING_OS, Budget::STATUS_CONVERTED, Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED], true),
            'can_cancel' => ! in_array($status, [Budget::STATUS_CONVERTED, Budget::STATUS_CANCELLED], true),
            'can_generate_os' => $this->isLinkableForOrder($budget),
            'has_registered_client' => $client !== null,
            'link_publico' => $publicLink,
            'is_revision' => $isRevision,
            'revision_base' => $revisionBase instanceof Budget ? [
                'id' => (int) $revisionBase->id,
                'numero' => (string) $revisionBase->numero,
            ] : null,
            'has_pending_revision' => $pendingRevision instanceof Budget,
            'pending_revision' => $pendingRevision instanceof Budget ? [
                'id' => (int) $pendingRevision->id,
                'numero' => (string) $pendingRevision->numero,
                'status' => (string) $pendingRevision->status,
                'status_label' => Budget::statusLabel($pendingRevision->status),
            ] : null,
            'converted_editable_fields' => Budget::CONVERTED_EDITABLE_FIELDS,
            'converted_revision_fields' => Budget::CONVERTED_REVISION_FIELDS,
            'created_at' => optional($budget->created_at)->format('d/m/Y H:i'),
            'updated_at' => optional($budget->updated_at)->format('d/m/Y H:i'),
        ];
    }

    /**
     * @param  Builder<Budget>  $query
     * @return array<string, mixed>
     */
    private function summary(Builder $query): array
    {
        // Uma agregacao, nao quinze. Antes este metodo rodava um COUNT por
        // status (sao 13 em Budget::statusOptions()), mais um SUM e mais um
        // COUNT total — cada um varrendo o conjunto filtrado inteiro, a cada
        // carregamento da listagem de orcamentos. Um GROUP BY entrega os mesmos
        // numeros numa passada so'.
        $rows = (clone $query)
            ->reorder()
            ->groupBy('orcamentos.status')
            ->select('orcamentos.status')
            ->selectRaw('COUNT(*) as itens')
            ->selectRaw('SUM(orcamentos.total) as valor')
            ->get();

        // Todos os status aparecem no retorno, inclusive os zerados: a tela
        // monta os chips de filtro a partir destas chaves, e um status ausente
        // sumiria do painel em vez de mostrar zero.
        $counts = array_fill_keys(array_column(Budget::statusOptions(), 'value'), 0);
        $total = 0;
        $totalValue = 0.0;

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $itens = (int) $row->itens;

            // Status fora do catalogo (registro legado, valor gravado a mao)
            // entra no total e no valor, mas NAO cria chave nova em by_status —
            // o formato antigo expunha exatamente as chaves de statusOptions(),
            // e a tela itera sobre elas.
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $itens;
            }

            $total += $itens;
            $totalValue += (float) $row->valor;
        }

        return array_merge([
            'total' => $total,
            'total_value' => round($totalValue, 2),
            'by_status' => $counts,
        ], $counts);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildQuery(array $filters = []): Builder
    {
        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $type = trim((string) ($filters['tipo'] ?? $filters['type'] ?? ''));
        $origin = trim((string) ($filters['origem'] ?? $filters['origin'] ?? ''));
        $clientId = (int) ($filters['cliente_id'] ?? $filters['client_id'] ?? 0);
        $orderId = (int) ($filters['os_id'] ?? $filters['order_id'] ?? 0);

        $query = Budget::query()->with(['client', 'equipment', 'order', 'responsible', 'creator', 'updater', 'revisionBase']);

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
     * @param  array<string, mixed>  $payload
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
     * O tipo é derivado exclusivamente da presença de OS, garantindo a divisão
     * clara entre os dois fluxos: com OS = "equipamento na assistência";
     * sem OS = "prévio" (avulso). O tipo enviado no payload é ignorado de
     * propósito — um orçamento sem OS nunca é "assistência" (e vice-versa).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function resolveType(array $attributes, bool $fromOrder): string
    {
        return $fromOrder ? Budget::TYPE_ASSISTANCE : Budget::TYPE_PREVIEW;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveEnvolveEquipamento(array $attributes, bool $default): bool
    {
        if (! array_key_exists('envolve_equipamento', $attributes) || $attributes['envolve_equipamento'] === null) {
            return $default;
        }

        return (bool) filter_var($attributes['envolve_equipamento'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Exclusividade cliente cadastrado × eventual e equipamento cadastrado ×
     * eventual (regra do usuário: sem conflito de dados). Também zera todo o
     * equipamento quando o orçamento não envolve aparelho (serviço puro, ex.:
     * visita técnica, instalação de cabo de rede).
     */
    private function applyClientEquipmentExclusivity(Budget $budget): void
    {
        // Cliente cadastrado tem prioridade sobre o nome eventual.
        if ((int) ($budget->cliente_id ?? 0) > 0) {
            $budget->cliente_nome_avulso = null;
        }

        if (! $budget->envolve_equipamento) {
            $budget->equipamento_id = null;
            $this->clearEventualEquipment($budget);

            return;
        }

        if ((int) ($budget->equipamento_id ?? 0) > 0) {
            // Equipamento cadastrado tem prioridade: descarta o eventual.
            $this->clearEventualEquipment($budget);
        } elseif ($this->hasEventualEquipment($budget)) {
            // Só há equipamento eventual: garante que nenhum equipamento_id órfão fique.
            $budget->equipamento_id = null;
        }
    }

    private function hasEventualEquipment(Budget $budget): bool
    {
        foreach (['equipamento_tipo_avulso', 'equipamento_marca_avulso', 'equipamento_modelo_avulso', 'equipamento_cor'] as $field) {
            if (trim((string) ($budget->{$field} ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function clearEventualEquipment(Budget $budget): void
    {
        $budget->equipamento_tipo_avulso = null;
        $budget->equipamento_marca_avulso = null;
        $budget->equipamento_modelo_avulso = null;
        $budget->equipamento_cor = null;
    }

    /**
     * Rótulo composto do equipamento eventual (tipo/marca/modelo · cor), usado
     * quando não há equipamento cadastrado — espelha o cliente_nome_avulso.
     */
    private function eventualEquipmentLabel(Budget $budget): string
    {
        $principal = trim(implode(' ', array_filter([
            trim((string) ($budget->equipamento_tipo_avulso ?? '')),
            trim((string) ($budget->equipamento_marca_avulso ?? '')),
            trim((string) ($budget->equipamento_modelo_avulso ?? '')),
        ])));

        $cor = trim((string) ($budget->equipamento_cor ?? ''));
        if ($cor !== '') {
            return $principal !== '' ? $principal.' · '.$cor : $cor;
        }

        return $principal;
    }

    /**
     * @param  array<string, mixed>  $attributes
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
     * @param  array<string, mixed>  $attributes
     */
    private function resolveOrigin(array $attributes, bool $fromOrder): string
    {
        if ($fromOrder) {
            return 'os';
        }

        $origin = strtolower(trim((string) ($attributes['origem'] ?? '')));

        // "os" só é uma origem válida quando $fromOrder é true (os_id
        // realmente preenchido). O campo "Origem" do formulário é editável
        // independente do campo "OS" (que tem allow-clear no Select2) — sem
        // essa trava, limpar só a OS e salvar deixava o orçamento com o
        // rótulo "veio de uma OS" sem nenhum os_id de verdade, escondendo a
        // ação "Ver OS" e mentindo sobre a origem no card de detalhes.
        if ($origin === 'os') {
            $origin = 'manual';
        }

        return in_array($origin, array_column(Budget::originOptions(), 'value'), true) ? $origin : 'manual';
    }

    /**
     * @param  array<string, mixed>  $attributes
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
     * @param  array<string, mixed>  $attributes
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
     * @param  array<string, mixed>  $attributes
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
     * @param  array<string, mixed>  $attributes
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
        $prefix = 'ORC-'.now()->format('ym').'-';
        $last = Budget::query()
            ->where('numero', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('numero');

        $sequence = 1;
        if (is_string($last) && Str::startsWith($last, $prefix)) {
            $sequence = max(1, (int) substr($last, strlen($prefix)) + 1);
        }

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
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
     * @param  array<int, mixed>  $items
     */
    /**
     * Regrava os itens do orcamento.
     *
     * ARMADILHA: este metodo APAGA e reinsere tudo a cada save. Enquanto os
     * campos de precificacao eram zeros literais isso era inofensivo; agora
     * que eles carregam a cotacao, editar a observacao de um orcamento ja
     * aprovado o REPRECIFICARIA pelas configuracoes de hoje — e um snapshot
     * que se recalcula nao e snapshot de nada.
     *
     * Por isso, em orcamento fechado, a cotacao das linhas anteriores e
     * preservada em vez de recalculada.
     */
    private function syncItems(Budget $budget, array $items): float
    {
        $cotacaoCongelada = $this->cotacaoCongelada($budget);

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

            // Orcamento fechado: reaproveita a cotacao gravada na primeira vez,
            // chaveada por tipo+referencia. Sem isso, cada save reescreveria o
            // historico com os parametros de hoje.
            $congelada = $cotacaoCongelada[$tipoItem.':'.(string) $referenciaId] ?? null;
            if ($congelada !== null) {
                $referenceData = $congelada + $referenceData;
            }

            $custoReferencia = (float) ($referenceData['preco_custo_referencia'] ?? 0);
            $vendaReferencia = (float) ($referenceData['preco_venda_referencia'] ?? 0);
            $valorRecomendado = (float) ($referenceData['valor_recomendado'] ?? 0);

            // Margem contra o preco efetivamente cobrado nesta linha.
            $valorMargem = round($valorUnitario - $custoReferencia, 2);
            $percentualMargem = $valorUnitario > 0
                ? round(($valorMargem / $valorUnitario) * 100, 2)
                : 0.0;

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
                'preco_custo_referencia' => $custoReferencia,
                'preco_venda_referencia' => $vendaReferencia,
                'preco_base' => (float) ($referenceData['preco_base'] ?? $valorUnitario),
                'percentual_encargos' => (float) ($referenceData['percentual_encargos'] ?? 0),
                'valor_encargos' => (float) ($referenceData['valor_encargos'] ?? 0),
                // Margem REAL cobrada, nao a meta das configuracoes. Guardar a
                // meta faria a coluna dizer 45% numa linha que o vendedor
                // descontou para 5% — mentira gravada. A meta mora em
                // `valor_recomendado`, e e contra ela que o semaforo compara.
                'percentual_margem' => $percentualMargem,
                'valor_margem' => $valorMargem,
                'valor_recomendado' => $valorRecomendado,
                // Resolvido por COMPARACAO no servidor. Vindo do cliente (como
                // era ate specs/037, com 'manual' literal em cinco lugares), a
                // coluna registraria intencao declarada, nao fato.
                'modo_precificacao' => ModoPrecificacao::resolver(
                    $valorUnitario,
                    $valorRecomendado > 0 ? $valorRecomendado : null,
                    $vendaReferencia > 0 ? $vendaReferencia : null,
                    $referenciaId !== null && $referenciaId > 0 && $referenceData !== []
                ),
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
     * Cotacao ja gravada, para orcamento que nao pode mais ser reprecificado.
     *
     * Vazio quando o orcamento ainda esta aberto — ai recotar e o certo, porque
     * o operador ainda esta montando a proposta.
     *
     * @return array<string, array<string, float>>
     */
    private function cotacaoCongelada(Budget $budget): array
    {
        $fechados = [
            Budget::STATUS_APPROVED,
            Budget::STATUS_PACKAGE_APPROVED,
            Budget::STATUS_PENDING_OS,
            Budget::STATUS_REJECTED,
            Budget::STATUS_EXPIRED,
        ];

        if (! in_array((string) $budget->status, $fechados, true)) {
            return [];
        }

        return BudgetItem::query()
            ->where('orcamento_id', $budget->id)
            ->get()
            ->mapWithKeys(static fn (BudgetItem $item): array => [
                (string) $item->tipo_item.':'.(string) ($item->referencia_id ?? '') => [
                    'preco_custo_referencia' => (float) ($item->preco_custo_referencia ?? 0),
                    'preco_venda_referencia' => (float) ($item->preco_venda_referencia ?? 0),
                    'preco_base' => (float) ($item->preco_base ?? 0),
                    'percentual_encargos' => (float) ($item->percentual_encargos ?? 0),
                    'valor_encargos' => (float) ($item->valor_encargos ?? 0),
                    'valor_recomendado' => (float) ($item->valor_recomendado ?? 0),
                ],
            ])
            ->all();
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

                // Chama o motor de precificacao (specs/037). Ate esta entrega
                // estes campos eram gravados com ZERO literal e ninguem os lia:
                // a coluna existia e nao respondia nada.
                $cotacao = $this->precificacaoService->simulateServico([
                    'servico_id' => $referenceId,
                    'tempo_padrao_horas' => (float) ($service->tempo_padrao_horas ?? 0),
                    'custo_direto_padrao' => (float) ($service->custo_direto_padrao ?? 0),
                    'valor_cadastro' => $value,
                    'tipo_equipamento' => (string) ($service->tipo_equipamento ?? ''),
                ]);

                return [
                    'descricao' => (string) ($service->nome ?? ''),
                    'valor_unitario' => $value,
                    'preco_base' => (float) ($cotacao['custo_total'] ?? $value),
                    // Custo direto apenas: a mao de obra so entra quando os
                    // cadastros forem revisados sob o rotulo novo de
                    // `custo_direto_padrao` — antes disso somaria duas vezes
                    // nas linhas que ja a incluem (specs/037, Fase 3).
                    'preco_custo_referencia' => (float) ($service->custo_direto_padrao ?? 0),
                    'preco_venda_referencia' => $value,
                    'percentual_encargos' => (float) ($cotacao['risco_percentual'] ?? 0),
                    'valor_encargos' => (float) ($cotacao['valor_risco'] ?? 0),
                    'valor_recomendado' => (float) ($cotacao['preco_minimo'] ?? $value),
                ];
            }
        }

        if ($type === 'peca') {
            $part = Peca::query()->find($referenceId);
            if ($part instanceof Peca) {
                $cost = (float) ($part->preco_custo ?? 0);
                $sale = (float) ($part->preco_venda ?? 0);

                $cotacao = $this->precificacaoService->simulatePeca([
                    'peca_id' => $referenceId,
                    'preco_custo' => $cost,
                    'preco_venda' => $sale,
                    'categoria' => (string) ($part->categoria ?? ''),
                ]);

                return [
                    'descricao' => (string) ($part->nome ?? ''),
                    'valor_unitario' => $sale > 0 ? $sale : $cost,
                    'preco_base' => (float) ($cotacao['preco_base'] ?? ($cost > 0 ? $cost : $sale)),
                    'preco_custo_referencia' => $cost,
                    'preco_venda_referencia' => $sale,
                    'percentual_encargos' => (float) ($cotacao['percentual_encargos'] ?? 0),
                    'valor_encargos' => (float) ($cotacao['valor_encargos'] ?? 0),
                    'valor_recomendado' => (float) ($cotacao['valor_recomendado'] ?? ($sale > 0 ? $sale : $cost)),
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
            ->with(['client', 'equipment.brand', 'equipment.model', 'equipment.type', 'order', 'responsible', 'creator', 'updater', 'items', 'paymentMethods', 'histories.user', 'sends.sender', 'approvals.user', 'revisionBase'])
            ->find($budgetId);
    }

    private function loadBudgetForUpdate(int $budgetId): ?Budget
    {
        return Budget::query()
            ->with(['client', 'equipment.brand', 'equipment.model', 'equipment.type', 'order', 'responsible', 'creator', 'updater', 'items', 'paymentMethods', 'histories.user', 'sends.sender', 'approvals.user', 'revisionBase'])
            ->lockForUpdate()
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
