<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Peca;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Orders\OrderWorkflowService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardSummaryService
{
    /**
     * @var array<int, string>
     */
    private const MONTH_LABELS = [
        1 => 'Jan',
        2 => 'Fev',
        3 => 'Mar',
        4 => 'Abr',
        5 => 'Mai',
        6 => 'Jun',
        7 => 'Jul',
        8 => 'Ago',
        9 => 'Set',
        10 => 'Out',
        11 => 'Nov',
        12 => 'Dez',
    ];

    /**
     * @var array<int, string>
     */
    private const MONTH_NAMES = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro',
    ];

    /**
     * Coluna gerada/indexada (migration 2026_06_30_120000) equivalente a
     * COALESCE(os.data_abertura, os.data_entrada, os.status_atualizado_em,
     * os.updated_at, os.created_at). Usar a coluna em vez do COALESCE inline
     * permite que filtros baseados em range (ver monthRange()) usem o
     * indice idx_os_data_abertura_efetiva.
     */
    private const OPEN_DATE_SQL = 'os.data_abertura_efetiva';

    /**
     * Data operacional de entrega reparada. Intencionalmente nao usa
     * status_atualizado_em/updated_at/created_at: esses campos refletem
     * importacoes ou edicoes em lote e distorcem o grafico mensal de entregas.
     */
    private const REPAIRED_DELIVERY_DATE_SQL = Order::REVENUE_DATE_SQL;

    /**
     * Expressão SQL para a data de referência usada no alerta de "OS parada".
     */
    private const STALE_REFERENCE_SQL = OrderWorkflowService::STALE_REFERENCE_SQL;

    /**
     * TTL curto: o painel tolera ate 1 minuto de atraso em troca de evitar
     * ~15 queries de agregacao a cada carregamento/refresh do dashboard.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Dias sem movimentacao de status a partir dos quais uma OS conta como
     * "parada". Era um literal dentro de buildAlerts(); virou constante porque
     * agora tres consumidores dependem do MESMO numero: o alerta agregado, o
     * marcador de prioridade de cada OS recente e o link que abre a lista de
     * OS ja filtrada por esse limiar. Divergir aqui faria o painel prometer
     * uma contagem que a lista de destino nao reproduz.
     */
    public const STALE_ORDER_DAYS = 15;

    /**
     * Teto de itens criticos devolvidos ao painel de estoque. O contador
     * (low_stock_total) continua sendo o numero real.
     */
    private const LOW_STOCK_PREVIEW_LIMIT = 8;

    /**
     * Paleta própria do gráfico de status. Não usa diretamente a cor do
     * catálogo porque vários status compartilham a mesma classe visual
     * ("primary", "danger" etc.), o que prejudica a leitura do doughnut.
     *
     * @var array<int, string>
     */
    private const STATUS_CHART_COLORS = [
        '#ef4444',
        '#64748b',
        '#0ea5e9',
        '#6f5afc',
        '#22c55e',
        '#f97316',
        '#14b8a6',
        '#a855f7',
        '#eab308',
        '#ec4899',
        '#2563eb',
        '#84cc16',
        '#f43f5e',
        '#0891b2',
        '#8b5cf6',
        '#10b981',
        '#fb923c',
        '#6366f1',
        '#06b6d4',
        '#d946ef',
        '#65a30d',
        '#dc2626',
        '#0284c7',
        '#7c3aed',
    ];

    /**
     * Paleta categórica validada (ordem fixa = mecanismo de segurança contra
     * daltonismo — nunca reordenar por índice solto). 8 matizes é o teto de
     * uma paleta categórica; o 9º tipo em diante entra em "Outros" em vez de
     * gerar mais uma cor. ΔE mínimo adjacente 24.2 (light, protanopia).
     *
     * @var array<int, string>
     */
    private const EQUIPMENT_CHART_COLORS = [
        '#2a78d6', // blue
        '#1baf7a', // aqua
        '#eda100', // yellow
        '#008300', // green
        '#4a3aa7', // violet (próximo do --desktop-primary #6f5afc)
        '#e34948', // red
        '#e87ba4', // magenta
        '#eb6834', // orange
    ];

    private const EQUIPMENT_CHART_MAX_TYPES = 8;

    // Cor neutra fixa para o agregado "Outros" — nunca deve competir com as
    // 8 cores de identidade categórica acima nem gerar um matiz novo.
    private const EQUIPMENT_CHART_OTHER_COLOR = '#94a3b8';

    public function __construct(
        private readonly OrderWorkflowService $orderWorkflowService,
        private readonly RbacAuthorizationService $rbacAuthorizationService
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(User $user, array $filters = []): array
    {
        return Cache::remember(
            $this->buildCacheKey($user, $filters),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildUncached($user, $filters)
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function buildCacheKey(User $user, array $filters): string
    {
        ksort($filters);

        return sprintf('dashboard:summary:user:%d:%s', $user->id, md5(json_encode($filters)));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function buildUncached(User $user, array $filters = []): array
    {
        $access = $this->buildAccessFlags($user);
        $canViewOrders = $access['can_view_orders'];

        $availableYears = $canViewOrders ? $this->availableOrderYears($user) : [(int) now()->year];
        $selectedYear = $this->normalizeYear($filters['ano'] ?? null, $availableYears);
        $equipmentPeriod = $this->normalizeEquipmentPeriod($filters, $availableYears);

        $monthlyChart = $canViewOrders ? $this->buildMonthlyChart($user, $selectedYear) : $this->emptyMonthlyChart($selectedYear);
        $statusChart = $canViewOrders ? $this->buildStatusChart($user) : $this->emptyStatusChart();
        $equipmentTypesChart = $canViewOrders
            ? $this->buildEquipmentTypesChart($user, $equipmentPeriod['ano'])
            : $this->emptyEquipmentTypesChart($equipmentPeriod);
        $financialSummary = $canViewOrders
            ? $this->buildFinancialSummary($user, $access)
            : $this->emptyFinancialSummary($access);
        $technicianSummary = $canViewOrders
            ? $this->buildTechnicianSummary($user, $access)
            : $this->emptyTechnicianSummary();
        $lowStock = $this->buildLowStock($access);

        // Gate proprio: a evolucao financeira anual e leitura de dinheiro, e
        // segue o mesmo criterio do hero card e do resumo financeiro. Sem
        // financeiro:visualizar o payload sai zerado e a secao nao renderiza.
        $financialMonthlyChart = $access['has_financial_access']
            ? $this->buildFinancialMonthlyChart($selectedYear)
            : $this->emptyFinancialMonthlyChart($selectedYear);

        return [
            'access' => $access,
            'stats' => $this->buildStats($user, $access, $financialSummary, $technicianSummary, $lowStock['total']),
            'hero_card' => $this->buildHeroCard($user, $access, $financialSummary, $technicianSummary),
            'context_card' => $this->buildContextCard($access, $financialSummary, $technicianSummary),
            'charts' => [
                'monthly' => $monthlyChart,
                'financial_monthly' => $financialMonthlyChart,
                'status' => $statusChart,
                'equipment_types' => $equipmentTypesChart,
                'financial' => $financialSummary,
                'technician' => $technicianSummary,
            ],
            'filters' => [
                'year' => $selectedYear,
                'years' => $availableYears,
                'equipment_month' => $equipmentPeriod['mes'],
                'equipment_year' => $equipmentPeriod['ano'],
                'equipment_years' => $equipmentPeriod['years'],
                'months' => self::MONTH_NAMES,
            ],
            'alerts' => $canViewOrders ? $this->buildAlerts($user) : ['os_paradas' => 0, 'orcamentos_pendentes' => 0, 'prontos_retirada' => 0],
            'recent_orders' => $this->buildRecentOrders($user, $access),
            'recent_clients' => $this->buildRecentClients($user),
            'recent_equipments' => $this->buildRecentEquipments($user),
            'low_stock' => $lowStock['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAccessFlags(User $user): array
    {
        $profile = mb_strtolower(trim((string) ($user->perfil ?? '')));

        return [
            'profile' => $profile !== '' ? $profile : 'usuario',
            'is_technician' => $profile === 'tecnico',
            'has_financial_access' => $this->rbacAuthorizationService->allows($user, 'financeiro', 'visualizar'),
            'can_view_orders' => $this->rbacAuthorizationService->allows($user, 'os', 'visualizar'),
            'can_view_stock' => $this->rbacAuthorizationService->allows($user, 'estoque', 'visualizar'),
            'can_view_clients' => $this->rbacAuthorizationService->allows($user, 'clientes', 'visualizar'),
            'can_view_equipments' => $this->rbacAuthorizationService->allows($user, 'equipamentos', 'visualizar'),
            'can_view_users' => $this->rbacAuthorizationService->allows($user, 'usuarios', 'visualizar'),
            'can_view_groups' => $this->rbacAuthorizationService->allows($user, 'grupos', 'visualizar'),
        ];
    }

    /**
     * Query base das OS acessíveis ao usuário (com join no catálogo de status),
     * já com o escopo de técnico aplicado. Cada build* monta sua própria
     * agregação em cima desta query, evitando carregar as OS inteiras em PHP.
     */
    private function baseOrdersQuery(User $user): Builder
    {
        $query = DB::table('os')
            ->leftJoin('os_status', 'os_status.codigo', '=', 'os.status');

        if ($this->isTechnician($user)) {
            $query->where('os.tecnico_id', (int) $user->id);
        }

        return $query;
    }

    /**
     * Query base das OS que ainda estão na posse da assistência. Este escopo
     * precisa permanecer alinhado com OrderWorkflowService::status_scope=open,
     * usado na listagem operacional de OS.
     */
    private function openOperationalOrdersQuery(User $user): Builder
    {
        $query = $this->baseOrdersQuery($user);
        $this->applyOperationalOpenScope($query);

        return $query;
    }

    /**
     * Entregas que geram RECEITA — só o reparo entregue e pago
     * (OrderStatus::REVENUE_CLOSURE_CODE), mais o caso "entregue com pendência
     * financeira" cujo encerramento final será o pago. Alimenta faturamento do
     * mês, receita do mês anterior e comissão. NÃO usar para a contagem
     * operacional de "entregue" — sem custo/garantia (R$0) não podem entrar aqui.
     */
    private function revenueDeliveredOrdersQuery(User $user): Builder
    {
        $query = $this->baseOrdersQuery($user);
        $this->applyRevenueDeliveryScope($query);

        return $query;
    }

    /**
     * Versao query-builder de Order::scopeReceitaReconhecida(). A regra vive no
     * model; aqui ela e reaplicada porque o painel monta as consultas em
     * DB::table('os') por performance, e um scope Eloquent nao alcanca isso.
     * Mudou uma, muda a outra.
     */
    private function applyRevenueDeliveryScope(Builder $query): void
    {
        $query->where(static function (Builder $scopeQuery): void {
            $scopeQuery
                ->where('os.status', OrderStatus::REVENUE_CLOSURE_CODE)
                ->orWhere('os.status_final_pendente_pagamento', OrderStatus::REVENUE_CLOSURE_CODE);
        });
    }

    /**
     * Entregas OPERACIONAIS — todo reparo entregue ao cliente, com ou sem
     * cobrança: pago, sem custo e garantia
     * (OrderStatus::REPAIRED_DELIVERY_CODES), mais o caso entregue com pendência
     * cujo encerramento final é um desses. Alimenta o card "Equipamento
     * Entregue" e o gráfico mensal de entregues reparadas. NÃO usar para somar
     * receita (sem custo/garantia são R$0).
     */
    private function deliveredOperationalOrdersQuery(User $user): Builder
    {
        $query = $this->baseOrdersQuery($user);
        $this->applyDeliveredOperationalScope($query);

        return $query;
    }

    private function applyDeliveredOperationalScope(Builder $query): void
    {
        $query->where(static function (Builder $scopeQuery): void {
            $scopeQuery
                ->whereIn('os.status', OrderStatus::REPAIRED_DELIVERY_CODES)
                ->orWhereIn('os.status_final_pendente_pagamento', OrderStatus::REPAIRED_DELIVERY_CODES);
        });
    }

    private function applyOperationalOpenScope(Builder $query): void
    {
        $closureCodes = OrderStatus::closureCodes();

        if ($closureCodes === []) {
            return;
        }

        // Mesma regra de OrderWorkflowService::applyOperationalStatusScope()
        // (status_scope=open) — OS so sai do escopo "aberta" quando os.status
        // literalmente esta em closureCodes(). Nao filtrar por
        // os.status_final_pendente_pagamento: uma OS entregue com pendencia
        // financeira continua aberta ate a baixa ser de fato quitada.
        $query->whereNotIn('os.status', $closureCodes);
    }

    /**
     * @return array<int, int>
     */
    private function availableOrderYears(User $user): array
    {
        $yearExpression = $this->datePartExpression(self::OPEN_DATE_SQL, 'year');

        $years = $this->baseOrdersQuery($user)
            ->selectRaw($yearExpression . ' as y')
            ->pluck('y')
            ->map(static fn ($year): int => (int) $year)
            ->filter(static fn (int $year): bool => $year > 0)
            ->values()
            ->all();

        $currentYear = (int) now()->year;
        $years[] = $currentYear;
        $years = array_values(array_unique(array_map(static fn ($year): int => (int) $year, $years)));
        rsort($years, SORT_NUMERIC);

        return $years === [] ? [$currentYear] : $years;
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, int> $availableYears
     * @return array{mes:int, ano:int, years:array<int, int>}
     */
    private function normalizeEquipmentPeriod(array $filters, array $availableYears): array
    {
        $months = array_keys(self::MONTH_NAMES);
        $month = (int) ($filters['equip_mes'] ?? now()->month);
        if (! in_array($month, $months, true)) {
            $month = (int) now()->month;
        }

        $years = array_values(array_unique(array_map(static fn ($year): int => (int) $year, $availableYears)));
        if ($years === []) {
            $years = [(int) now()->year];
        }

        $year = (int) ($filters['equip_ano'] ?? now()->year);
        if (! in_array($year, $years, true)) {
            $year = (int) $years[0];
        }

        return [
            'mes' => $month,
            'ano' => $year,
            'years' => $years,
        ];
    }

    /**
     * @param array<int, int> $availableYears
     */
    private function normalizeYear(mixed $requestedYear, array $availableYears): int
    {
        $year = (int) $requestedYear;
        if ($year > 0 && in_array($year, $availableYears, true)) {
            return $year;
        }

        return (int) ($availableYears[0] ?? now()->year);
    }

    /**
     * Limites [inicio, fim) de um ano ou mes especifico, para filtrar por
     * range (>= inicio AND < fim) em vez de YEAR()/MONTH(coluna) = ?. Range
     * sobre a coluna gerada/indexada permite "type: range" no plano de
     * execucao; YEAR()/MONTH() sempre forcam scan (mesmo com indice).
     *
     * @return array{0: string, 1: string}
     */
    private function periodBounds(int $year, ?int $month = null): array
    {
        $start = $month === null
            ? Carbon::create($year, 1, 1)->startOfDay()
            : Carbon::create($year, $month, 1)->startOfDay();

        $end = $month === null
            ? $start->copy()->addYear()
            : $start->copy()->addMonthNoOverflow();

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }

    /**
     * @param 'year'|'month' $part
     */
    private function datePartExpression(string $column, string $part): string
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return match ($part) {
                'year' => "CAST(strftime('%Y', {$column}) AS INTEGER)",
                'month' => "CAST(strftime('%m', {$column}) AS INTEGER)",
            };
        }

        return match ($part) {
            'year' => "YEAR({$column})",
            'month' => "MONTH({$column})",
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(
        User $user,
        array $access,
        array $financialSummary,
        array $technicianSummary,
        int $lowStockTotal = 0
    ): array {
        $clientCount = $access['can_view_clients'] ? Client::query()->count() : 0;
        $equipmentCount = $access['can_view_equipments'] ? Equipment::query()->count() : 0;
        $userCount = $access['can_view_users'] ? User::query()->count() : 0;
        $groupCount = $access['can_view_groups'] ? DB::table('grupos')->count() : 0;

        $totalOrders = 0;
        $deliveredOrders = 0;
        $openOrders = 0;

        if ($access['can_view_orders']) {
            $row = $this->baseOrdersQuery($user)
                ->selectRaw('COUNT(*) as total')
                ->first();

            $totalOrders = (int) ($row->total ?? 0);
            $deliveredOrders = $this->deliveredOperationalOrdersQuery($user)->count();
            $openOrders = $this->openOperationalOrdersQuery($user)->count();
        }

        return [
            'orders' => $openOrders,
            'total_abertas' => $openOrders,
            'clients' => $clientCount,
            'total_clients' => $clientCount,
            'equipments' => $equipmentCount,
            'total_equipments' => $equipmentCount,
            'users' => $userCount,
            'groups' => $groupCount,
            'total_os' => $totalOrders,
            'equipamento_entregue' => $deliveredOrders,
            'equipamento_entregue_total' => $deliveredOrders,
            'equipamento_entregue_mes_atual' => $financialSummary['delivered_operational_current_month_count'] ?? 0,
            'faturamento_mes' => $financialSummary['receitas'] ?? 0.0,
            'faturamento_mes_anterior' => $financialSummary['previous_month_revenue'] ?? 0.0,
            'despesas_pagas_mes' => $financialSummary['despesas_pagas'] ?? 0.0,
            'despesas_pagas_mes_anterior' => $financialSummary['despesas_pagas_mes_anterior'] ?? 0.0,
            'recebido_mes' => $financialSummary['recebido_mes'] ?? 0.0,
            'recebido_mes_anterior' => $financialSummary['recebido_mes_anterior'] ?? 0.0,
            'despesas_pendentes' => $financialSummary['pendentes'] ?? 0.0,
            'comissao_acumulada' => $technicianSummary['commission_total'] ?? 0.0,
            'low_stock_total' => $lowStockTotal,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildHeroCard(User $user, array $access, array $financialSummary, array $technicianSummary): array
    {
        if ($access['has_financial_access']) {
            return [
                'type' => 'financial',
                'label' => 'Faturamento mês',
                'value' => $financialSummary['receitas'] ?? 0.0,
                'value_type' => 'money',
                // Faturamento e caixa sao pilares diferentes: faturar muito e
                // receber pouco e exatamente o quadro que quebra empresa. O
                // card mostra o faturado e diz, na legenda, quanto virou
                // dinheiro — o gap fica visivel sem abrir relatorio nenhum.
                'meta' => $this->heroFinancialMeta($financialSummary),
                'icon' => 'bi-currency-dollar',
                'accent' => '#16a34a',
                'action_label' => 'Ajuda do painel',
                'action_url' => null,
            ];
        }

        if ($access['is_technician']) {
            return [
                'type' => 'technician',
                'label' => 'Comissões acumuladas',
                'value' => $technicianSummary['commission_total'] ?? 0.0,
                'value_type' => 'money',
                'meta' => 'Comissões estimadas neste mês.',
                'icon' => 'bi-wallet2',
                'accent' => '#16a34a',
                'action_label' => 'Ver minhas OS',
                'action_url' => route('api.v1.orders.index', [], false),
            ];
        }

        return [
            'type' => 'technician',
            'label' => 'Técnico destaque',
            'value' => (string) ($technicianSummary['highlight_name'] ?? 'Nenhum técnico'),
            'value_type' => 'text',
            'meta' => (int) ($technicianSummary['highlight_total'] ?? 0) . ' OS em manutenção',
            'icon' => 'bi-person-gear',
            'accent' => '#16a34a',
            'action_label' => 'Ver operação',
            'action_url' => route('api.v1.orders.index', [], false),
        ];
    }

    /**
     * Legenda do card de faturamento: diz a base (competencia, e o que ela
     * inclui) e quanto do mes ja virou caixa.
     *
     * @param array<string, mixed> $financialSummary
     */
    private function heroFinancialMeta(array $financialSummary): string
    {
        $recebido = (float) ($financialSummary['recebido_mes'] ?? 0);

        return 'OS entregues e vendas do mês. '
            . 'R$ ' . number_format($recebido, 2, ',', '.') . ' já recebidos no caixa.';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContextCard(array $access, array $financialSummary, array $technicianSummary): array
    {
        if ($access['has_financial_access']) {
            // Painel de CAIXA, deliberadamente numa base so: antes misturava
            // receita operacional de OS com custo estimado de OS aberta e
            // chamava a diferenca de "resultado caixa", que nao correspondia a
            // regime nenhum. Aqui tudo vem de movimento realizado, o que o
            // deixa distinto do card de faturamento (competencia) acima.
            return [
                'type' => 'financial',
                'title' => 'Resumo financeiro',
                'subtitle' => 'Entradas e saídas de caixa do mês corrente.',
                'chart' => [
                    'labels' => ['Recebido', 'Despesas pagas', 'Resultado caixa', 'Pendentes'],
                    'values' => [
                        (float) ($financialSummary['recebido_mes'] ?? 0),
                        (float) ($financialSummary['despesas_pagas'] ?? 0),
                        (float) ($financialSummary['resultado_caixa'] ?? 0),
                        (float) ($financialSummary['pendentes'] ?? 0),
                    ],
                ],
                'legend' => [
                    ['label' => 'Recebido', 'color' => '#16a34a'],
                    ['label' => 'Despesas pagas', 'color' => '#ef4444'],
                    ['label' => 'Resultado caixa', 'color' => '#6366f1'],
                    ['label' => 'Pendentes', 'color' => '#f59e0b'],
                ],
            ];
        }

        return [
            'type' => 'technician',
            'title' => 'OS em manutenção por técnico',
            'subtitle' => 'Visão operacional para priorizar atendimento.',
            'chart' => [
                'labels' => $technicianSummary['labels'] ?? [],
                'values' => $technicianSummary['values'] ?? [],
            ],
            'legend' => [
                ['label' => 'OS em manutenção', 'color' => '#6366f1'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMonthlyChart(User $user, int $year): array
    {
        [$yearStart, $yearEnd] = $this->periodBounds($year);
        $monthExpression = $this->datePartExpression(self::OPEN_DATE_SQL, 'month');
        $deliveryMonthExpression = $this->datePartExpression(self::REPAIRED_DELIVERY_DATE_SQL, 'month');

        $opened = $this->baseOrdersQuery($user)
            ->selectRaw($monthExpression . ' as mes, COUNT(*) as total')
            ->whereRaw(self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?', [$yearStart, $yearEnd])
            ->groupByRaw($monthExpression)
            ->pluck('total', 'mes');

        $delivered = $this->deliveredOperationalOrdersQuery($user)
            ->selectRaw($deliveryMonthExpression . ' as mes, COUNT(*) as total')
            ->whereRaw(
                self::REPAIRED_DELIVERY_DATE_SQL . ' >= ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' < ?',
                [$yearStart, $yearEnd]
            )
            ->groupByRaw($deliveryMonthExpression)
            ->pluck('total', 'mes');

        $points = [];
        for ($month = 1; $month <= 12; $month++) {
            $points[] = [
                'mes' => $month,
                'label' => self::MONTH_LABELS[$month],
                'total' => (int) ($opened[$month] ?? 0),
                'entregues_reparadas' => (int) ($delivered[$month] ?? 0),
            ];
        }

        return $this->monthlyChartPayload($year, $points);
    }

    /**
     * @param array<int, array<string, mixed>> $points
     * @return array<string, mixed>
     */
    /**
     * Evolucao financeira mes a mes do ano: faturamento, recebido, despesa fixa
     * e variavel, e o lucro liquido — a serie que responde "estou dando lucro
     * ou prejuizo".
     *
     * Devolve os DOIS regimes no mesmo payload, porque nenhum sozinho conta a
     * verdade nesta base: hoje so uma fracao do custo lancado tem baixa
     * registrada, entao um grafico so de caixa mostraria a assistencia
     * lucrativa; e um so de competencia esconderia que o dinheiro nao entrou. O
     * alternador da tela troca de serie sem refetch.
     *
     * Segue o padrao de performance de buildMonthlyChart(): uma passada
     * agregada por serie, resolvida no banco com GROUP BY mes, nunca laco em
     * PHP sobre linhas cruas.
     *
     * @return array<string, mixed>
     */
    /**
     * Base dos titulos a RECEBER sem OS, por competencia — o mesmo recorte que
     * faturamentoNaoOs() usa para o card, para o grafico e o cartao nunca
     * anunciarem faturamentos diferentes.
     */
    private function competenciaReceberQuery(): EloquentBuilder
    {
        return Financeiro::query()
            ->where('financeiro.tipo', Financeiro::TIPO_RECEBER)
            ->where('financeiro.status', '!=', Financeiro::STATUS_CANCELADO)
            ->where('financeiro.impacta_dre', true)
            ->where(static function (EloquentBuilder $q): void {
                $q->whereNull('financeiro.os_id')->orWhereNotNull('financeiro.venda_id');
            });
    }

    /**
     * Base dos titulos a PAGAR que entram no DRE, por competencia.
     */
    private function competenciaPagarQuery(): EloquentBuilder
    {
        return Financeiro::query()
            ->where('financeiro.tipo', Financeiro::TIPO_PAGAR)
            ->where('financeiro.status', '!=', Financeiro::STATUS_CANCELADO)
            ->where('financeiro.impacta_dre', true);
    }

    /**
     * Base das BAIXAS de um tipo de titulo — mesma fonte e mesmo filtro
     * (`impacta_fluxo_caixa`) de paidPagarTotal()/paidReceberTotal() e do Fluxo
     * de Caixa, para os tres concordarem sobre o que saiu e entrou.
     */
    private function movimentoQuery(string $tipo): EloquentBuilder
    {
        return FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', $tipo)
            ->where('financeiro.impacta_fluxo_caixa', true);
    }

    /**
     * Monta os 12 meses e as duas leituras de lucro.
     *
     * Competencia: receita liquida + outras receitas - despesas. E exatamente a
     * formula do resultado_liquido do DRE, para o grafico nao virar uma
     * terceira verdade sobre o mesmo mes.
     *
     * Caixa: recebido - despesas pagas, identico ao resultado_caixa do painel.
     *
     * @param \Illuminate\Support\Collection<int|string, mixed> $faturamentoOs
     * @param \Illuminate\Support\Collection<int|string, mixed> $descontosOs
     * @param \Illuminate\Support\Collection<int|string, mixed> $receitasNaoOs
     * @param \Illuminate\Support\Collection<int|string, mixed> $devolucoes
     * @param \Illuminate\Support\Collection<int|string, mixed> $despesasCompetencia
     * @param \Illuminate\Support\Collection<int|string, mixed> $recebido
     * @param \Illuminate\Support\Collection<int|string, mixed> $despesasCaixa
     * @return array<string, mixed>
     */
    private function financialMonthlyPayload(
        int $year,
        $faturamentoOs,
        $descontosOs,
        $receitasNaoOs,
        $devolucoes,
        $despesasCompetencia,
        $recebido,
        $despesasCaixa
    ): array {
        $competencia = [
            'faturamento' => [], 'recebido' => [], 'deducoes' => [], 'outras_receitas' => [],
            'despesa_fixa' => [], 'despesa_variavel' => [], 'lucro' => [],
        ];
        $caixa = $competencia;

        for ($mes = 1; $mes <= 12; $mes++) {
            $naoOs = $receitasNaoOs[$mes] ?? null;
            $despC = $despesasCompetencia[$mes] ?? null;
            $despX = $despesasCaixa[$mes] ?? null;

            $faturamento = round((float) ($faturamentoOs[$mes] ?? 0) + (float) ($naoOs->operacional ?? 0), 2);
            $deducoes = round((float) ($descontosOs[$mes] ?? 0) + (float) ($devolucoes[$mes] ?? 0), 2);
            $outras = round((float) ($naoOs->outras ?? 0), 2);
            $fixaC = round((float) ($despC->fixa ?? 0), 2);
            $variavelC = round((float) ($despC->variavel ?? 0), 2);

            $recebidoMes = round((float) ($recebido[$mes] ?? 0), 2);
            $fixaX = round((float) ($despX->fixa ?? 0), 2);
            $variavelX = round((float) ($despX->variavel ?? 0), 2);

            $competencia['faturamento'][] = $faturamento;
            $competencia['recebido'][] = $recebidoMes;
            $competencia['deducoes'][] = $deducoes;
            $competencia['outras_receitas'][] = $outras;
            $competencia['despesa_fixa'][] = $fixaC;
            $competencia['despesa_variavel'][] = $variavelC;
            $competencia['lucro'][] = round($faturamento - $deducoes + $outras - $fixaC - $variavelC, 2);

            $caixa['faturamento'][] = $faturamento;
            $caixa['recebido'][] = $recebidoMes;
            $caixa['deducoes'][] = 0.0;
            $caixa['outras_receitas'][] = 0.0;
            $caixa['despesa_fixa'][] = $fixaX;
            $caixa['despesa_variavel'][] = $variavelX;
            $caixa['lucro'][] = round($recebidoMes - $fixaX - $variavelX, 2);
        }

        return [
            'year' => $year,
            'labels' => array_values(self::MONTH_LABELS),
            // Ate onde o ano ja aconteceu. A tela tracaja o que vem depois, para
            // custo fixo ja lancado em mes futuro nao se passar por realizado.
            'mes_atual' => (int) now()->month,
            'ano_corrente' => (int) now()->year,
            'regimes' => [
                'competencia' => $competencia,
                'caixa' => $caixa,
            ],
            'legend' => [
                ['key' => 'despesa_fixa', 'label' => 'Despesa fixa', 'color' => '#94a3b8', 'type' => 'bar'],
                ['key' => 'despesa_variavel', 'label' => 'Despesa variável', 'color' => '#f59e0b', 'type' => 'bar'],
                ['key' => 'faturamento', 'label' => 'Faturamento', 'color' => '#6f5afc', 'type' => 'line'],
                ['key' => 'recebido', 'label' => 'Recebido', 'color' => '#0ea5e9', 'type' => 'dashed'],
                ['key' => 'lucro', 'label' => 'Lucro líquido', 'color' => '#16a34a', 'type' => 'diverging'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFinancialMonthlyChart(int $year): array
    {
        $zeros = array_fill(0, 12, 0.0);
        $serie = [
            'faturamento' => $zeros, 'recebido' => $zeros, 'deducoes' => $zeros,
            'outras_receitas' => $zeros, 'despesa_fixa' => $zeros,
            'despesa_variavel' => $zeros, 'lucro' => $zeros,
        ];

        return [
            'year' => $year,
            'labels' => array_values(self::MONTH_LABELS),
            'mes_atual' => (int) now()->month,
            'ano_corrente' => (int) now()->year,
            'regimes' => ['competencia' => $serie, 'caixa' => $serie],
            'legend' => [],
        ];
    }

    private function buildFinancialMonthlyChart(int $year): array
    {
        [$yearStart, $yearEnd] = $this->periodBounds($year);
        $inicioDia = Carbon::parse($yearStart)->toDateString();
        $fimDia = Carbon::parse($yearEnd)->subDay()->toDateString();

        $mesEntrega = $this->datePartExpression(Order::REVENUE_DATE_SQL, 'month');
        $mesCompetencia = $this->datePartExpression('COALESCE(financeiro.data_competencia, financeiro.data_vencimento)', 'month');
        $mesMovimento = $this->datePartExpression('financeiro_movimentos.data_movimento', 'month');

        // --- Competencia -----------------------------------------------
        $faturamentoOs = Order::query()
            ->receitaReconhecida()
            ->selectRaw($mesEntrega . ' as mes, COALESCE(SUM(os.valor_total), 0) as total')
            ->whereRaw(Order::REVENUE_DATE_SQL . ' >= ? AND ' . Order::REVENUE_DATE_SQL . ' < ?', [$yearStart, $yearEnd])
            ->groupByRaw($mesEntrega)
            ->pluck('total', 'mes');

        $descontosOs = Order::query()
            ->receitaReconhecida()
            ->selectRaw($mesEntrega . ' as mes, COALESCE(SUM(os.desconto), 0) as total')
            ->whereRaw(Order::REVENUE_DATE_SQL . ' >= ? AND ' . Order::REVENUE_DATE_SQL . ' < ?', [$yearStart, $yearEnd])
            ->groupByRaw($mesEntrega)
            ->pluck('total', 'mes');

        // Receita operacional sem OS (venda de balcao) e "outras receitas"
        // (avulsas, lancamento sem grupo) numa consulta so, separadas por CASE.
        $receitasNaoOs = $this->competenciaReceberQuery()
            ->selectRaw(
                $mesCompetencia . ' as mes,'
                . ' COALESCE(SUM(CASE WHEN financeiro.grupo_dre = ? THEN financeiro.valor ELSE 0 END), 0) as operacional,'
                . ' COALESCE(SUM(CASE WHEN financeiro.grupo_dre IS NULL OR financeiro.grupo_dre <> ? THEN financeiro.valor ELSE 0 END), 0) as outras',
                [Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL, Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL]
            )
            ->whereBetween(DB::raw('COALESCE(financeiro.data_competencia, financeiro.data_vencimento)'), [$inicioDia, $fimDia])
            ->groupByRaw($mesCompetencia)
            ->get()
            ->keyBy('mes');

        $devolucoes = $this->competenciaPagarQuery()
            ->where('financeiro.origem_tipo', Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO)
            ->selectRaw($mesCompetencia . ' as mes, COALESCE(SUM(financeiro.valor), 0) as total')
            ->whereBetween(DB::raw('COALESCE(financeiro.data_competencia, financeiro.data_vencimento)'), [$inicioDia, $fimDia])
            ->groupByRaw($mesCompetencia)
            ->pluck('total', 'mes');

        // Despesas por competencia, fixa x variavel numa consulta so.
        //
        // Deliberadamente SEM a heuristica de "fixo mensal reaparece em meses
        // futuros" que FinanceiroReportService::groupByCompetencia() aplica:
        // ela existe para o recorte de UM mes. Repetida mes a mes num grafico
        // anual, dezembro carregaria o aluguel do ano inteiro.
        $despesasCompetencia = $this->competenciaPagarQuery()
            ->where(function ($q): void {
                $q->whereNull('financeiro.origem_tipo')
                    ->orWhere('financeiro.origem_tipo', '<>', Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO);
            })
            ->selectRaw(
                $mesCompetencia . ' as mes,'
                . ' COALESCE(SUM(CASE WHEN financeiro.dre_fixo_mensal = 1 THEN financeiro.valor ELSE 0 END), 0) as fixa,'
                . ' COALESCE(SUM(CASE WHEN financeiro.dre_fixo_mensal = 1 THEN 0 ELSE financeiro.valor END), 0) as variavel'
            )
            ->whereBetween(DB::raw('COALESCE(financeiro.data_competencia, financeiro.data_vencimento)'), [$inicioDia, $fimDia])
            ->groupByRaw($mesCompetencia)
            ->get()
            ->keyBy('mes');

        // --- Caixa -------------------------------------------------------
        $recebido = $this->movimentoQuery(Financeiro::TIPO_RECEBER)
            ->selectRaw($mesMovimento . ' as mes, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total')
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicioDia, $fimDia])
            ->groupByRaw($mesMovimento)
            ->pluck('total', 'mes');

        $despesasCaixa = $this->movimentoQuery(Financeiro::TIPO_PAGAR)
            ->selectRaw(
                $mesMovimento . ' as mes,'
                . ' COALESCE(SUM(CASE WHEN financeiro.dre_fixo_mensal = 1 THEN financeiro_movimentos.valor_movimento ELSE 0 END), 0) as fixa,'
                . ' COALESCE(SUM(CASE WHEN financeiro.dre_fixo_mensal = 1 THEN 0 ELSE financeiro_movimentos.valor_movimento END), 0) as variavel'
            )
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicioDia, $fimDia])
            ->groupByRaw($mesMovimento)
            ->get()
            ->keyBy('mes');

        return $this->financialMonthlyPayload(
            $year,
            $faturamentoOs,
            $descontosOs,
            $receitasNaoOs,
            $devolucoes,
            $despesasCompetencia,
            $recebido,
            $despesasCaixa
        );
    }

    private function monthlyChartPayload(int $year, array $points): array
    {
        return [
            'year' => $year,
            'labels' => array_values(self::MONTH_LABELS),
            'points' => $points,
            'series' => [
                [
                    'key' => 'abertas',
                    'label' => 'OS abertas',
                    'color' => '#6f5afc',
                    'backgroundColor' => 'rgba(111, 90, 252, 0.18)',
                    'data' => array_map(static fn (array $point): int => (int) $point['total'], $points),
                ],
                [
                    'key' => 'entregues_reparadas',
                    'label' => 'OS entregues reparadas',
                    'color' => '#16a34a',
                    'backgroundColor' => 'rgba(22, 163, 74, 0.18)',
                    'data' => array_map(static fn (array $point): int => (int) $point['entregues_reparadas'], $points),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMonthlyChart(int $year): array
    {
        $points = [];
        for ($month = 1; $month <= 12; $month++) {
            $points[] = [
                'mes' => $month,
                'label' => self::MONTH_LABELS[$month],
                'total' => 0,
                'entregues_reparadas' => 0,
            ];
        }

        return $this->monthlyChartPayload($year, $points);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusChart(User $user): array
    {
        $rows = $this->openOperationalOrdersQuery($user)
            ->selectRaw("
                CASE WHEN os.status IS NULL OR TRIM(os.status) = '' THEN 'sem_status' ELSE os.status END as status_code,
                COALESCE(NULLIF(TRIM(os_status.nome), ''), CASE WHEN os.status IS NULL OR TRIM(os.status) = '' THEN 'Sem status' ELSE os.status END) as nome,
                os_status.cor as cor,
                COALESCE(NULLIF(TRIM(os_status.grupo_macro), ''), 'outros') as grupo_macro,
                COUNT(*) as total
            ")
            ->groupByRaw('os.status, os_status.nome, os_status.cor, os_status.grupo_macro')
            ->orderByDesc('total')
            ->orderByRaw('MAX(os.id) DESC')
            ->get();

        $items = [];
        $colors = [];
        $totalOpen = 0;

        foreach ($rows as $index => $row) {
            $total = (int) $row->total;
            $totalOpen += $total;
            $color = $this->chartColor(self::STATUS_CHART_COLORS, (int) $index);

            $items[] = [
                'codigo' => (string) $row->status_code,
                'nome' => (string) $row->nome,
                'cor' => $color,
                'grupo_macro' => (string) $row->grupo_macro,
                'total' => $total,
            ];

            $colors[] = $color;
        }

        return [
            'total' => $totalOpen,
            'labels' => array_map(static fn (array $item): string => $item['nome'], $items),
            'series' => [
                [
                    'key' => 'status',
                    'label' => 'OS em aberto',
                    'data' => array_map(static fn (array $item): int => $item['total'], $items),
                    'backgroundColor' => $colors,
                ],
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatusChart(): array
    {
        return [
            'total' => 0,
            'labels' => [],
            'series' => [
                [
                    'key' => 'status',
                    'label' => 'OS em aberto',
                    'data' => [],
                    'backgroundColor' => [],
                ],
            ],
            'items' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEquipmentTypesChart(User $user, int $year): array
    {
        $hasTiposTable = Schema::hasTable('equipamentos_tipos');
        $monthExpression = $this->datePartExpression(self::OPEN_DATE_SQL, 'month');

        $query = $this->baseOrdersQuery($user)
            ->leftJoin('equipamentos', 'equipamentos.id', '=', 'os.equipamento_id');

        $labelExpr = "COALESCE(equipamentos.desktop_modalidade, '')";
        $groupByRaw = 'equipamentos.desktop_modalidade';
        if ($hasTiposTable) {
            $query->leftJoin('equipamentos_tipos', 'equipamentos_tipos.id', '=', 'equipamentos.tipo_id');
            $labelExpr = "COALESCE(NULLIF(TRIM(equipamentos_tipos.nome), ''), equipamentos.desktop_modalidade, '')";
            $groupByRaw = 'equipamentos_tipos.nome, equipamentos.desktop_modalidade';
        }

        [$periodStart, $periodEnd] = $this->periodBounds($year);

        $rows = $query
            ->selectRaw("{$monthExpression} as mes, {$labelExpr} as raw_label, COUNT(*) as total")
            ->whereRaw(self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?', [$periodStart, $periodEnd])
            ->groupByRaw($monthExpression . ', ' . $groupByRaw)
            ->orderByRaw($monthExpression . ' ASC')
            ->get();

        $monthlyByLabel = [];
        $totalsByLabel = [];
        foreach ($rows as $row) {
            $month = (int) $row->mes;
            if ($month < 1 || $month > 12) {
                continue;
            }

            $label = $this->normalizeEquipmentLabel((string) $row->raw_label);
            if ($label === '') {
                $label = 'Não informado';
            }

            if (! isset($monthlyByLabel[$label])) {
                $monthlyByLabel[$label] = array_fill(1, 12, 0);
                $totalsByLabel[$label] = 0;
            }

            $total = (int) $row->total;
            $monthlyByLabel[$label][$month] += $total;
            $totalsByLabel[$label] += $total;
        }

        $labels = array_keys($totalsByLabel);
        usort($labels, static function (string $left, string $right) use ($totalsByLabel): int {
            $byTotal = $totalsByLabel[$right] <=> $totalsByLabel[$left];

            return $byTotal !== 0 ? $byTotal : strcasecmp($left, $right);
        });

        $topLabels = array_slice($labels, 0, self::EQUIPMENT_CHART_MAX_TYPES);
        $overflowLabels = array_slice($labels, self::EQUIPMENT_CHART_MAX_TYPES);
        $series = [];
        $items = [];
        $totalsByMonth = array_fill(1, 12, 0);

        foreach ($topLabels as $index => $label) {
            $data = array_values($monthlyByLabel[$label] ?? array_fill(1, 12, 0));
            $color = $this->chartColor(self::EQUIPMENT_CHART_COLORS, (int) $index);

            $series[] = [
                'key' => 'tipo_' . ($index + 1),
                'label' => $label,
                'data' => $data,
                'backgroundColor' => $color,
                'color' => $color,
                'total' => (int) ($totalsByLabel[$label] ?? 0),
            ];

            $items[] = [
                'tipo_nome' => $label,
                'total' => (int) ($totalsByLabel[$label] ?? 0),
                'cor' => $color,
                'data' => $data,
            ];

            for ($month = 1; $month <= 12; $month++) {
                $totalsByMonth[$month] += (int) ($monthlyByLabel[$label][$month] ?? 0);
            }
        }

        if ($overflowLabels !== []) {
            $otherMonthly = array_fill(1, 12, 0);
            $otherTotal = 0;

            foreach ($overflowLabels as $label) {
                $otherTotal += (int) ($totalsByLabel[$label] ?? 0);
                for ($month = 1; $month <= 12; $month++) {
                    $otherMonthly[$month] += (int) ($monthlyByLabel[$label][$month] ?? 0);
                    $totalsByMonth[$month] += (int) ($monthlyByLabel[$label][$month] ?? 0);
                }
            }

            $otherColor = self::EQUIPMENT_CHART_OTHER_COLOR;
            $otherData = array_values($otherMonthly);
            $series[] = [
                'key' => 'outros',
                'label' => 'Outros',
                'data' => $otherData,
                'backgroundColor' => $otherColor,
                'color' => $otherColor,
                'total' => $otherTotal,
            ];
            $items[] = [
                'tipo_nome' => 'Outros',
                'total' => $otherTotal,
                'cor' => $otherColor,
                'data' => $otherData,
            ];
        }

        return [
            'type' => 'stacked_monthly',
            'period' => [
                'ano' => $year,
                'periodo_label' => (string) $year,
                'years' => $this->availableOrderYears($user),
            ],
            'labels' => array_values(self::MONTH_LABELS),
            'totals_by_month' => array_values($totalsByMonth),
            'series' => $series,
            'items' => $items,
        ];
    }

    /**
     * @param array{mes:int, ano:int, years:array<int,int>} $equipmentPeriod
     * @return array<string, mixed>
     */
    private function emptyEquipmentTypesChart(array $equipmentPeriod): array
    {
        return [
            'type' => 'stacked_monthly',
            'period' => [
                'ano' => $equipmentPeriod['ano'],
                'periodo_label' => (string) $equipmentPeriod['ano'],
                'years' => $equipmentPeriod['years'],
            ],
            'labels' => array_values(self::MONTH_LABELS),
            'totals_by_month' => array_fill(0, 12, 0),
            'series' => [],
            'items' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFinancialSummary(User $user, array $access): array
    {
        $currentMonth = (int) now()->month;
        $currentYear = (int) now()->year;
        $previousMonthDate = now()->copy()->subMonthNoOverflow();
        $previousMonth = (int) $previousMonthDate->month;
        $previousYear = (int) $previousMonthDate->year;

        [$currentPeriodStart, $currentPeriodEnd] = $this->periodBounds($currentYear, $currentMonth);
        [$previousPeriodStart, $previousPeriodEnd] = $this->periodBounds($previousYear, $previousMonth);

        $currentMonthRow = $this->revenueDeliveredOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_final), 0) as total, COUNT(*) as cnt')
            ->whereRaw(
                self::REPAIRED_DELIVERY_DATE_SQL . ' >= ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' < ?',
                [$currentPeriodStart, $currentPeriodEnd]
            )
            ->first();

        $previousMonthRow = $this->revenueDeliveredOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_final), 0) as total')
            ->whereRaw(
                self::REPAIRED_DELIVERY_DATE_SQL . ' >= ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' < ?',
                [$previousPeriodStart, $previousPeriodEnd]
            )
            ->first();

        // Contagem OPERACIONAL de entregas do mês (inclui sem custo/garantia,
        // R$0) — para o card, separada da contagem de receita acima.
        $deliveredOperationalCurrentMonth = $this->deliveredOperationalOrdersQuery($user)
            ->whereRaw(
                self::REPAIRED_DELIVERY_DATE_SQL . ' >= ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' < ?',
                [$currentPeriodStart, $currentPeriodEnd]
            )
            ->count();

        $despesasRow = $this->openOperationalOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_mao_obra + os.valor_pecas), 0) as total')
            ->whereRaw(self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?', [$currentPeriodStart, $currentPeriodEnd])
            ->first();

        // Pendentes = quanto ainda falta SAIR do caixa em contas a pagar com
        // vencimento até o fim do mês atual — mês corrente + atrasadas de
        // meses anteriores. Nunca inclui vencimento em mês futuro (ex.:
        // parcelas geradas por repetição), mesmo que já estejam pendentes.
        //
        // Soma o saldo em aberto, não o valor bruto: num título "parcial" a
        // parte já baixada saiu do caixa e contá-la de novo aqui inflaria a
        // dívida. A janela é mais larga que a das "Saídas previstas" do Fluxo
        // de Caixa de propósito — lá é só o mês, aqui as atrasadas entram, que
        // é o que o card promete e o relatório não mostra.
        $pendentesTitulos = Financeiro::query()
            ->where('tipo', Financeiro::TIPO_PAGAR)
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL])
            ->where('impacta_fluxo_caixa', true)
            ->where('data_vencimento', '<', $currentPeriodEnd)
            ->get(['id', 'valor']);

        $pendentes = $this->openAmountSum($pendentesTitulos);

        // Faturamento = tudo que a assistencia gerou no mes, nao so OS: soma a
        // receita das OS entregues com as vendas de balcao e os servicos
        // avulsos, que vivem em `financeiro` sem os_id. Antes o card lia so a
        // tabela `os` e uma venda de balcao simplesmente nao existia para ele.
        $receitas = (float) ($currentMonthRow->total ?? 0)
            + $this->faturamentoNaoOs($currentPeriodStart, $currentPeriodEnd);
        $receitasAnterior = (float) ($previousMonthRow->total ?? 0)
            + $this->faturamentoNaoOs($previousPeriodStart, $previousPeriodEnd);
        $despesas = (float) ($despesasRow->total ?? 0);

        // Pago de fato (regime de caixa), diferente de `despesas` acima: soma
        // os movimentos de baixa (financeiro_movimentos) do mês, e não o custo
        // estimado das OS abertas. Cobre baixa total e parcial — um título
        // "parcial" já tirou dinheiro do caixa na parte paga.
        $despesasPagasAtual = $this->paidPagarTotal($currentPeriodStart, $currentPeriodEnd);
        $despesasPagasAnterior = $this->paidPagarTotal($previousPeriodStart, $previousPeriodEnd);

        // Recebido != faturado, e a diferenca e informacao, nao divergencia:
        // faturamento e o que foi vendido no mes (competencia), recebido e o
        // dinheiro que entrou na conta (caixa). Uma cobranca faturada em
        // agosto e paga em setembro aparece em meses diferentes nos dois — por
        // isso o card mostra os dois numeros lado a lado.
        $recebidoAtual = $this->paidReceberTotal($currentPeriodStart, $currentPeriodEnd);
        $recebidoAnterior = $this->paidReceberTotal($previousPeriodStart, $previousPeriodEnd);

        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'despesas_pagas' => $despesasPagasAtual,
            'despesas_pagas_mes_anterior' => $despesasPagasAnterior,
            'recebido_mes' => $recebidoAtual,
            'recebido_mes_anterior' => $recebidoAnterior,
            'resultado_caixa' => round($recebidoAtual - $despesasPagasAtual, 2),
            'pendentes' => $pendentes,
            'month' => $currentMonth,
            'year' => $currentYear,
            'previous_month_revenue' => $receitasAnterior,
            'delivered_current_month_count' => (int) ($currentMonthRow->cnt ?? 0),
            'delivered_operational_current_month_count' => $deliveredOperationalCurrentMonth,
            'has_access' => (bool) $access['has_financial_access'],
        ];
    }

    /**
     * Soma dos movimentos de saída (regime de caixa) de contas a pagar num
     * período — mesma fonte e mesmo filtro (`impacta_fluxo_caixa`) que
     * FinanceiroReportService usa no Fluxo de Caixa, para os dois nunca
     * divergirem sobre "quanto saiu do caixa" no mesmo período.
     */
    private function paidPagarTotal(string $start, string $end): float
    {
        return (float) FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', Financeiro::TIPO_PAGAR)
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw('financeiro_movimentos.data_movimento >= ? AND financeiro_movimentos.data_movimento < ?', [$start, $end])
            ->sum('financeiro_movimentos.valor_movimento');
    }

    /**
     * Espelho de paidPagarTotal() do lado da receita: quanto entrou no caixa
     * no periodo. Mesma fonte e mesmo filtro que o Fluxo de Caixa usa em
     * FinanceiroReportService::netMovimentos(), entao o valor bate com
     * "Entradas realizadas" por construcao, e nao por coincidencia.
     */
    private function paidReceberTotal(string $start, string $end): float
    {
        return (float) FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', Financeiro::TIPO_RECEBER)
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw('financeiro_movimentos.data_movimento >= ? AND financeiro_movimentos.data_movimento < ?', [$start, $end])
            ->sum('financeiro_movimentos.valor_movimento');
    }

    /**
     * Faturamento do periodo que NAO vem de OS — na pratica, a venda de balcao.
     *
     * Dois filtros carregam a regra, e nenhum e opcional:
     *
     *  - `whereNull('os_id')` impede contar duas vezes: a receita de OS ja entra
     *    pela tabela `os` (revenueDeliveredOrdersQuery), e todo titulo de
     *    cobranca de OS carrega o os_id.
     *  - `grupo_dre = Receita Operacional` mantem fora o que nao e faturamento:
     *    receita avulsa (nao recorrente) e lancamento incompleto, sem grupo.
     *    Sem ele, uma despesa digitada por engano como "a receber" viraria
     *    faturamento da assistencia.
     *
     * Mesma definicao que o DRE por competencia usa em
     * FinanceiroReportService::dreReport(), para o card e o relatorio nunca
     * anunciarem faturamentos diferentes para o mesmo mes.
     *
     * Por competencia, nao por caixa: uma venda entra no faturamento do mes em
     * que foi feita, mesmo que o dinheiro so caia depois.
     */
    private function faturamentoNaoOs(string $start, string $end): float
    {
        // periodBounds() devolve [inicio, fim) com fim no dia 1 do mes
        // seguinte; o scope de competencia trabalha com intervalo fechado.
        $inicioDia = Carbon::parse($start)->toDateString();
        $fimDia = Carbon::parse($end)->subDay()->toDateString();

        return (float) Financeiro::query()
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->where('impacta_dre', true)
            ->where('grupo_dre', Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL)
            // Venda vinculada a OS carrega os_id no titulo; sem o orWhere ela
            // sumiria do faturamento, porque a receita de OS vem de
            // os.valor_final e nao do titulo. Mesmo predicado do DRE.
            ->where(static function ($q): void {
                $q->whereNull('os_id')->orWhereNotNull('venda_id');
            })
            ->competenciaEntre($inicioDia, $fimDia)
            ->sum('valor');
    }

    /**
     * Soma o saldo EM ABERTO de uma colecao de titulos (valor menos o que ja
     * foi baixado), nunca negativo. Mesma conta de
     * FinanceiroReportService::openAmountsByTitle(), para o painel e o Fluxo
     * de Caixa concordarem sobre quanto ainda falta pagar.
     *
     * Feito em PHP, e nao com GREATEST() no SQL, porque os testes rodam em
     * SQLite, que nao tem essa funcao.
     *
     * @param \Illuminate\Support\Collection<int, Financeiro> $titulos
     */
    private function openAmountSum($titulos): float
    {
        $ids = $titulos->pluck('id')->all();

        if ($ids === []) {
            return 0.0;
        }

        $movimentado = FinanceiroMovimento::query()
            ->whereIn('financeiro_id', $ids)
            ->selectRaw('financeiro_id, COALESCE(SUM(valor_movimento), 0) as total')
            ->groupBy('financeiro_id')
            ->pluck('total', 'financeiro_id');

        $total = 0.0;

        foreach ($titulos as $titulo) {
            $total += max(0, round((float) $titulo->valor - (float) ($movimentado[$titulo->id] ?? 0), 2));
        }

        return round($total, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFinancialSummary(array $access): array
    {
        return [
            'receitas' => 0.0,
            'despesas' => 0.0,
            'despesas_pagas' => 0.0,
            'despesas_pagas_mes_anterior' => 0.0,
            'recebido_mes' => 0.0,
            'recebido_mes_anterior' => 0.0,
            'resultado_caixa' => 0.0,
            'pendentes' => 0.0,
            'month' => (int) now()->month,
            'year' => (int) now()->year,
            'previous_month_revenue' => 0.0,
            'delivered_current_month_count' => 0,
            'delivered_operational_current_month_count' => 0,
            'has_access' => (bool) $access['has_financial_access'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTechnicianSummary(User $user, array $access): array
    {
        $currentMonth = (int) now()->month;
        $currentYear = (int) now()->year;

        $rows = $this->openOperationalOrdersQuery($user)
            ->leftJoin('usuarios', 'usuarios.id', '=', 'os.tecnico_id')
            ->selectRaw("os.tecnico_id as tecnico_id, usuarios.nome as tecnico_nome, COUNT(*) as total")
            ->groupBy('os.tecnico_id', 'usuarios.nome')
            ->orderByDesc('total')
            ->get();

        $list = [];
        foreach ($rows as $row) {
            $technicianId = (int) ($row->tecnico_id ?? 0);
            $name = trim((string) ($row->tecnico_nome ?? ''));

            $list[] = [
                'tecnico_id' => $technicianId,
                'tecnico_nome' => $technicianId > 0 && $name !== '' ? $name : 'Sem técnico',
                'total' => (int) $row->total,
            ];
        }

        $highlight = $list[0] ?? [
            'tecnico_id' => 0,
            'tecnico_nome' => 'Nenhum técnico',
            'total' => 0,
        ];

        $commissionTotal = 0.0;
        if ($access['is_technician']) {
            [$periodStart, $periodEnd] = $this->periodBounds($currentYear, $currentMonth);

            $commissionRow = $this->revenueDeliveredOrdersQuery($user)
                ->selectRaw('COALESCE(SUM(os.valor_final * 0.1), 0) as total')
                ->whereRaw(
                    'os.tecnico_id = ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' >= ? AND ' . self::REPAIRED_DELIVERY_DATE_SQL . ' < ?',
                    [(int) $user->id, $periodStart, $periodEnd]
                )
                ->first();

            $commissionTotal = (float) ($commissionRow->total ?? 0);
        }

        return [
            'labels' => array_map(static fn (array $item): string => $item['tecnico_nome'], array_slice($list, 0, 6)),
            'values' => array_map(static fn (array $item): int => $item['total'], array_slice($list, 0, 6)),
            'highlight_id' => (int) ($highlight['tecnico_id'] ?? 0),
            'highlight_name' => (string) ($highlight['tecnico_nome'] ?? 'Nenhum técnico'),
            'highlight_total' => (int) ($highlight['total'] ?? 0),
            'commission_total' => $commissionTotal,
            'month' => $currentMonth,
            'year' => $currentYear,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyTechnicianSummary(): array
    {
        return [
            'labels' => [],
            'values' => [],
            'highlight_id' => 0,
            'highlight_name' => 'Nenhum técnico',
            'highlight_total' => 0,
            'commission_total' => 0.0,
            'month' => (int) now()->month,
            'year' => (int) now()->year,
        ];
    }

    /**
     * @return array{os_paradas:int,orcamentos_pendentes:int,prontos_retirada:int}
     */
    private function buildAlerts(User $user): array
    {
        $staleThreshold = now()->copy()->subDays(self::STALE_ORDER_DAYS);

        $row = $this->openOperationalOrdersQuery($user)
            ->selectRaw(
                'SUM(CASE WHEN ' . self::STALE_REFERENCE_SQL . ' < ? THEN 1 ELSE 0 END) as os_paradas,'
                . ' SUM(CASE WHEN ' . OrderWorkflowService::PENDING_BUDGET_SQL . ' THEN 1 ELSE 0 END) as orcamentos_pendentes,'
                . ' SUM(CASE WHEN ' . OrderWorkflowService::READY_PICKUP_SQL . ' THEN 1 ELSE 0 END) as prontos_retirada',
                [$staleThreshold]
            )
            ->first();

        return [
            'os_paradas' => (int) ($row->os_paradas ?? 0),
            'orcamentos_pendentes' => (int) ($row->orcamentos_pendentes ?? 0),
            'prontos_retirada' => (int) ($row->prontos_retirada ?? 0),
        ];
    }

    /**
     * Itens abaixo do estoque minimo. Ate esta versao o painel devolvia uma
     * lista fixa vazia, entao o card "Alerta de estoque baixo" era incapaz de
     * mostrar qualquer coisa — nao por falta de itens criticos, mas por falta
     * de consulta. A regra e a MESMA de Peca::estoqueBaixo() e de
     * EstoqueController::lowStock(); duplicar o criterio aqui faria o alerta
     * divergir da lista que ele abre.
     *
     * @param array<string, mixed> $access
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function buildLowStock(array $access): array
    {
        if (! ($access['can_view_stock'] ?? false) || ! Schema::hasTable('pecas')) {
            return ['items' => [], 'total' => 0];
        }

        $query = Peca::query()
            ->where('ativo', 1)
            ->whereColumn('quantidade_atual', '<=', 'estoque_minimo');

        $total = (clone $query)->count();

        $items = $query
            ->orderBy('quantidade_atual')
            ->orderBy('nome')
            ->limit(self::LOW_STOCK_PREVIEW_LIMIT)
            ->get()
            ->map(static fn (Peca $peca): array => [
                'id' => (int) $peca->id,
                'codigo' => (string) ($peca->codigo ?? ''),
                'nome' => (string) ($peca->nome ?? ''),
                // float nos dois: com (int), o card dizia "0,5 m em estoque ·
                // minimo 0" — um alerta que se contradiz sozinho.
                'quantidade_atual' => (float) ($peca->quantidade_atual ?? 0),
                'estoque_minimo' => (float) ($peca->estoque_minimo ?? 0),
                'unidade' => (string) ($peca->unidade ?? 'UN'),
            ])
            ->values()
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentOrders(User $user, array $access): array
    {
        if (! $access['can_view_orders']) {
            return [];
        }

        $paginator = $this->orderWorkflowService->paginateForUser($user, [
            'per_page' => 5,
        ]);

        return array_map(function (array $order): array {
            $date = $this->parseOrderDate($order['data_abertura'] ?? $order['created_at'] ?? null)
                ?? $this->parseOrderDate($order['status_atualizado_em'] ?? null);

            // Duas idades diferentes, de proposito: 'dias_em_aberto' conta
            // desde a abertura (quanto o cliente espera) e 'dias_sem_movimento'
            // conta desde a ultima troca de status (se a OS travou). A segunda
            // usa a mesma referencia do alerta os_paradas — ver
            // STALE_REFERENCE_SQL — para que o marcador vermelho da linha e a
            // contagem do painel de atencao nunca se contradigam.
            $movedAt = $this->parseOrderDate($order['status_atualizado_em'] ?? null)
                ?? $this->parseOrderDate($order['updated_at'] ?? null)
                ?? $date;

            return array_merge($order, [
                'dias_em_aberto' => $this->calculateOrderAgeDays($date),
                'dias_sem_movimento' => $this->calculateOrderAgeDays($movedAt),
                'data_label' => $date?->format('d/m/Y') ?? 'Sem data',
            ]);
        }, $paginator->items());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentClients(User $user): array
    {
        if (! $this->rbacAuthorizationService->allows($user, 'clientes', 'visualizar')) {
            return [];
        }

        return Client::query()
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static function (Client $client): array {
                return [
                    'id' => (int) $client->id,
                    'nome_razao' => (string) ($client->nome_razao ?? ''),
                    'cpf_cnpj' => (string) ($client->cpf_cnpj ?? ''),
                    'email' => (string) ($client->email ?? ''),
                    'telefone1' => (string) ($client->telefone1 ?? ''),
                    'cidade' => (string) ($client->cidade ?? ''),
                    'uf' => (string) ($client->uf ?? ''),
                    'status_cadastro' => (string) ($client->status_cadastro ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRecentEquipments(User $user): array
    {
        if (! $this->rbacAuthorizationService->allows($user, 'equipamentos', 'visualizar')) {
            return [];
        }

        return Equipment::query()
            ->with('client')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static function (Equipment $equipment): array {
                return [
                    'id' => (int) $equipment->id,
                    'cliente_id' => (int) ($equipment->cliente_id ?? 0),
                    'cliente_nome' => (string) ($equipment->client?->nome_razao ?? ''),
                    'resumo_tecnico' => (string) ($equipment->resumo_tecnico ?? ''),
                    'numero_serie' => (string) ($equipment->numero_serie ?? ''),
                    'imei' => (string) ($equipment->imei ?? ''),
                    'desktop_modalidade' => (string) ($equipment->desktop_modalidade ?? ''),
                    'status_operacional' => (string) ($equipment->status_operacional ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function isTechnician(User $user): bool
    {
        return mb_strtolower(trim((string) ($user->perfil ?? ''))) === 'tecnico';
    }

    /**
     * Retorna uma cor única para a posição do gráfico. Quando a quantidade de
     * categorias ultrapassa a paleta fixa, usa saltos no círculo HSL para
     * reduzir colisões visuais sem depender de bibliotecas externas.
     *
     * @param array<int, string> $palette
     */
    private function chartColor(array $palette, int $index): string
    {
        if (isset($palette[$index])) {
            return $palette[$index];
        }

        $hue = (17 + ($index * 137)) % 360;

        return sprintf('hsl(%d, 72%%, 48%%)', $hue);
    }

    private function normalizeEquipmentLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(['-', '_'], ' ', $value);

        return ucwords(mb_strtolower($value));
    }

    private function parseOrderDate(mixed $value): ?Carbon
    {
        return $this->parseCarbonCandidate($value);
    }

    private function parseCarbonCandidate(mixed $candidate): ?Carbon
    {
        if ($candidate instanceof Carbon) {
            return $candidate;
        }

        if (is_string($candidate) && trim($candidate) !== '') {
            try {
                return Carbon::parse($candidate);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function calculateOrderAgeDays(?Carbon $date): int
    {
        if (! $date instanceof Carbon) {
            return 0;
        }

        return max(0, (int) $date->diffInDays(now()));
    }
}
