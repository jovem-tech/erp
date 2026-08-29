<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\Equipment;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\OrderStatus;
use App\Models\Peca;
use App\Models\User;
use App\Services\Auth\RbacAuthorizationService;
use App\Services\Orders\OrderWorkflowService;
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
    private const REPAIRED_DELIVERY_DATE_SQL = 'COALESCE(os.data_entrega, os.data_conclusao)';

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

        return [
            'access' => $access,
            'stats' => $this->buildStats($user, $access, $financialSummary, $technicianSummary, $lowStock['total']),
            'hero_card' => $this->buildHeroCard($user, $access, $financialSummary, $technicianSummary),
            'context_card' => $this->buildContextCard($access, $financialSummary, $technicianSummary),
            'charts' => [
                'monthly' => $monthlyChart,
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
                'meta' => 'Baseado na movimentação operacional do mês.',
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
     * @return array<string, mixed>
     */
    private function buildContextCard(array $access, array $financialSummary, array $technicianSummary): array
    {
        if ($access['has_financial_access']) {
            return [
                'type' => 'financial',
                'title' => 'Resumo financeiro',
                'subtitle' => 'Comparativo operacional do mês corrente.',
                'chart' => [
                    'labels' => ['Receitas', 'Despesas', 'Resultado caixa', 'Pendentes'],
                    'values' => [
                        (float) ($financialSummary['receitas'] ?? 0),
                        (float) ($financialSummary['despesas'] ?? 0),
                        (float) ($financialSummary['resultado_caixa'] ?? 0),
                        (float) ($financialSummary['pendentes'] ?? 0),
                    ],
                ],
                'legend' => [
                    ['label' => 'Receitas', 'color' => '#16a34a'],
                    ['label' => 'Despesas', 'color' => '#ef4444'],
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

        // Pendentes = despesas (contas a pagar) ainda pendentes/parciais com
        // vencimento até o fim do mês atual — mês corrente + atrasadas de
        // meses anteriores. Nunca inclui vencimento em mês futuro (ex.:
        // parcelas geradas por repetição), mesmo que já estejam pendentes.
        $pendentesRow = Financeiro::query()
            ->where('tipo', Financeiro::TIPO_PAGAR)
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL])
            ->where('data_vencimento', '<', $currentPeriodEnd)
            ->selectRaw('COALESCE(SUM(valor), 0) as total')
            ->first();

        $receitas = (float) ($currentMonthRow->total ?? 0);
        $despesas = (float) ($despesasRow->total ?? 0);

        // Pago de fato (regime de caixa), diferente de `despesas` acima: soma
        // os movimentos de baixa (financeiro_movimentos) do mês, e não o custo
        // estimado das OS abertas. Cobre baixa total e parcial — um título
        // "parcial" já tirou dinheiro do caixa na parte paga.
        $despesasPagasAtual = $this->paidPagarTotal($currentPeriodStart, $currentPeriodEnd);
        $despesasPagasAnterior = $this->paidPagarTotal($previousPeriodStart, $previousPeriodEnd);

        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'despesas_pagas' => $despesasPagasAtual,
            'despesas_pagas_mes_anterior' => $despesasPagasAnterior,
            'resultado_caixa' => $receitas - $despesas,
            'pendentes' => (float) ($pendentesRow->total ?? 0),
            'month' => $currentMonth,
            'year' => $currentYear,
            'previous_month_revenue' => (float) ($previousMonthRow->total ?? 0),
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
     * @return array<string, mixed>
     */
    private function emptyFinancialSummary(array $access): array
    {
        return [
            'receitas' => 0.0,
            'despesas' => 0.0,
            'despesas_pagas' => 0.0,
            'despesas_pagas_mes_anterior' => 0.0,
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
