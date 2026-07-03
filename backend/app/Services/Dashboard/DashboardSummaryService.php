<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\Equipment;
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
     * Coluna gerada/indexada equivalente a COALESCE(os.data_entrega,
     * os.data_conclusao, os.status_atualizado_em, os.updated_at, os.created_at).
     */
    private const DELIVERY_DATE_SQL = 'os.data_entrega_efetiva';

    /**
     * Expressão SQL para a data de referência usada no alerta de "OS parada".
     */
    private const STALE_REFERENCE_SQL = 'COALESCE(os.status_atualizado_em, os.updated_at, os.created_at)';

    /**
     * Expressão SQL equivalente à regra de negócio isDelivered(): true quando o
     * status catalogado é final, ou o código de status contém "entregue", ou é
     * um dos códigos legados concluido/finalizado/encerrado.
     */
    private const DELIVERED_SQL = "(os_status.status_final = 1 OR os.status LIKE '%entregue%' OR os.status IN ('concluido', 'finalizado', 'encerrado'))";

    /**
     * TTL curto: o painel tolera ate 1 minuto de atraso em troca de evitar
     * ~15 queries de agregacao a cada carregamento/refresh do dashboard.
     */
    private const CACHE_TTL_SECONDS = 60;

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
            ? $this->buildEquipmentTypesChart($user, $equipmentPeriod['mes'], $equipmentPeriod['ano'])
            : $this->emptyEquipmentTypesChart($equipmentPeriod);
        $financialSummary = $canViewOrders
            ? $this->buildFinancialSummary($user, $access)
            : $this->emptyFinancialSummary($access);
        $technicianSummary = $canViewOrders
            ? $this->buildTechnicianSummary($user, $access)
            : $this->emptyTechnicianSummary();

        return [
            'access' => $access,
            'stats' => $this->buildStats($user, $access, $financialSummary, $technicianSummary),
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
            'low_stock' => [],
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
        array $technicianSummary
    ): array {
        $clientCount = $access['can_view_clients'] ? Client::query()->count() : 0;
        $equipmentCount = $access['can_view_equipments'] ? Equipment::query()->count() : 0;
        $userCount = $access['can_view_users'] ? User::query()->count() : 0;
        $groupCount = $access['can_view_groups'] ? DB::table('grupos')->count() : 0;

        $totalOrders = 0;
        $deliveredOrders = 0;

        if ($access['can_view_orders']) {
            $row = $this->baseOrdersQuery($user)
                ->selectRaw('COUNT(*) as total, SUM(CASE WHEN ' . self::DELIVERED_SQL . ' THEN 1 ELSE 0 END) as delivered')
                ->first();

            $totalOrders = (int) ($row->total ?? 0);
            $deliveredOrders = (int) ($row->delivered ?? 0);
        }

        $openOrders = $totalOrders - $deliveredOrders;

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
            'equipamento_entregue_mes_atual' => $financialSummary['delivered_current_month_count'] ?? 0,
            'faturamento_mes' => $financialSummary['receitas'] ?? 0.0,
            'faturamento_mes_anterior' => $financialSummary['previous_month_revenue'] ?? 0.0,
            'comissao_acumulada' => $technicianSummary['commission_total'] ?? 0.0,
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
        $deliveryMonthExpression = $this->datePartExpression(self::DELIVERY_DATE_SQL, 'month');

        $opened = $this->baseOrdersQuery($user)
            ->selectRaw($monthExpression . ' as mes, COUNT(*) as total')
            ->whereRaw(self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?', [$yearStart, $yearEnd])
            ->groupByRaw($monthExpression)
            ->pluck('total', 'mes');

        $delivered = $this->baseOrdersQuery($user)
            ->selectRaw($deliveryMonthExpression . ' as mes, COUNT(*) as total')
            ->whereRaw(
                self::DELIVERY_DATE_SQL . ' >= ? AND ' . self::DELIVERY_DATE_SQL . ' < ? AND ' . self::DELIVERED_SQL,
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
        $rows = $this->baseOrdersQuery($user)
            ->selectRaw("
                CASE WHEN os.status IS NULL OR TRIM(os.status) = '' THEN 'sem_status' ELSE os.status END as status_code,
                COALESCE(NULLIF(TRIM(os_status.nome), ''), CASE WHEN os.status IS NULL OR TRIM(os.status) = '' THEN 'Sem status' ELSE os.status END) as nome,
                os_status.cor as cor,
                COALESCE(NULLIF(TRIM(os_status.grupo_macro), ''), 'outros') as grupo_macro,
                COUNT(*) as total
            ")
            ->whereRaw('NOT ' . self::DELIVERED_SQL)
            ->groupByRaw('os.status, os_status.nome, os_status.cor, os_status.grupo_macro')
            ->orderByDesc('total')
            ->orderByRaw('MAX(os.id) DESC')
            ->get();

        $items = [];
        $colors = [];
        $totalOpen = 0;

        foreach ($rows as $row) {
            $total = (int) $row->total;
            $totalOpen += $total;
            $color = $this->statusColor($row->cor);

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
    private function buildEquipmentTypesChart(User $user, int $month, int $year): array
    {
        $hasTiposTable = Schema::hasTable('equipamentos_tipos');

        $query = $this->baseOrdersQuery($user)
            ->leftJoin('equipamentos', 'equipamentos.id', '=', 'os.equipamento_id');

        $labelExpr = "COALESCE(equipamentos.desktop_modalidade, '')";
        $groupByRaw = 'equipamentos.desktop_modalidade';
        if ($hasTiposTable) {
            $query->leftJoin('equipamentos_tipos', 'equipamentos_tipos.id', '=', 'equipamentos.tipo_id');
            $labelExpr = "COALESCE(NULLIF(TRIM(equipamentos_tipos.nome), ''), equipamentos.desktop_modalidade, '')";
            $groupByRaw = 'equipamentos_tipos.nome, equipamentos.desktop_modalidade';
        }

        [$periodStart, $periodEnd] = $this->periodBounds($year, $month);

        $rows = $query
            ->selectRaw("{$labelExpr} as raw_label, COUNT(*) as total, COUNT(DISTINCT os.equipamento_id) as equip_unicos")
            ->whereRaw(self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?', [$periodStart, $periodEnd])
            ->groupByRaw($groupByRaw)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $label = $this->normalizeEquipmentLabel((string) $row->raw_label);
            if ($label === '') {
                $label = 'Não informado';
            }

            if (! isset($items[$label])) {
                $items[$label] = ['tipo_nome' => $label, 'total' => 0, 'equipamentos_unicos' => 0];
            }

            $items[$label]['total'] += (int) $row->total;
            $items[$label]['equipamentos_unicos'] += (int) $row->equip_unicos;
        }

        $list = array_values($items);
        usort($list, static fn (array $left, array $right): int => $right['total'] <=> $left['total']);
        $list = array_slice($list, 0, 6);

        return [
            'period' => [
                'mes' => $month,
                'ano' => $year,
                'mes_label' => self::MONTH_NAMES[$month] ?? sprintf('%02d', $month),
                'periodo_label' => ($this->monthName($month) ?? sprintf('%02d', $month)) . '/' . $year,
                'years' => $this->availableOrderYears($user),
            ],
            'labels' => array_map(static fn (array $item): string => (string) $item['tipo_nome'], $list),
            'series' => [
                [
                    'key' => 'equipamentos',
                    'label' => 'OS por tipo',
                    'data' => array_map(static fn (array $item): int => (int) $item['total'], $list),
                    'backgroundColor' => ['#3b82f6', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#64748b'],
                ],
            ],
            'items' => $list,
        ];
    }

    /**
     * @param array{mes:int, ano:int, years:array<int,int>} $equipmentPeriod
     * @return array<string, mixed>
     */
    private function emptyEquipmentTypesChart(array $equipmentPeriod): array
    {
        return [
            'period' => [
                'mes' => $equipmentPeriod['mes'],
                'ano' => $equipmentPeriod['ano'],
                'mes_label' => self::MONTH_NAMES[$equipmentPeriod['mes']] ?? sprintf('%02d', $equipmentPeriod['mes']),
                'periodo_label' => ($this->monthName($equipmentPeriod['mes']) ?? sprintf('%02d', $equipmentPeriod['mes'])) . '/' . $equipmentPeriod['ano'],
                'years' => $equipmentPeriod['years'],
            ],
            'labels' => [],
            'series' => [
                [
                    'key' => 'equipamentos',
                    'label' => 'OS por tipo',
                    'data' => [],
                    'backgroundColor' => ['#3b82f6', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#64748b'],
                ],
            ],
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

        $currentMonthRow = $this->baseOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_final), 0) as total, COUNT(*) as cnt')
            ->whereRaw(
                self::DELIVERED_SQL . ' AND ' . self::DELIVERY_DATE_SQL . ' >= ? AND ' . self::DELIVERY_DATE_SQL . ' < ?',
                [$currentPeriodStart, $currentPeriodEnd]
            )
            ->first();

        $previousMonthRow = $this->baseOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_final), 0) as total')
            ->whereRaw(
                self::DELIVERED_SQL . ' AND ' . self::DELIVERY_DATE_SQL . ' >= ? AND ' . self::DELIVERY_DATE_SQL . ' < ?',
                [$previousPeriodStart, $previousPeriodEnd]
            )
            ->first();

        $despesasRow = $this->baseOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_mao_obra + os.valor_pecas), 0) as total')
            ->whereRaw(
                'NOT ' . self::DELIVERED_SQL . ' AND ' . self::OPEN_DATE_SQL . ' >= ? AND ' . self::OPEN_DATE_SQL . ' < ?',
                [$currentPeriodStart, $currentPeriodEnd]
            )
            ->first();

        $pendentesRow = $this->baseOrdersQuery($user)
            ->selectRaw('COALESCE(SUM(os.valor_final), 0) as total')
            ->whereRaw('NOT ' . self::DELIVERED_SQL)
            ->first();

        $receitas = (float) ($currentMonthRow->total ?? 0);
        $despesas = (float) ($despesasRow->total ?? 0);

        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'resultado_caixa' => $receitas - $despesas,
            'pendentes' => (float) ($pendentesRow->total ?? 0),
            'month' => $currentMonth,
            'year' => $currentYear,
            'previous_month_revenue' => (float) ($previousMonthRow->total ?? 0),
            'delivered_current_month_count' => (int) ($currentMonthRow->cnt ?? 0),
            'has_access' => (bool) $access['has_financial_access'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyFinancialSummary(array $access): array
    {
        return [
            'receitas' => 0.0,
            'despesas' => 0.0,
            'resultado_caixa' => 0.0,
            'pendentes' => 0.0,
            'month' => (int) now()->month,
            'year' => (int) now()->year,
            'previous_month_revenue' => 0.0,
            'delivered_current_month_count' => 0,
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

        $rows = $this->baseOrdersQuery($user)
            ->leftJoin('usuarios', 'usuarios.id', '=', 'os.tecnico_id')
            ->selectRaw("os.tecnico_id as tecnico_id, usuarios.nome as tecnico_nome, COUNT(*) as total")
            ->whereRaw('NOT ' . self::DELIVERED_SQL)
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

            $commissionRow = $this->baseOrdersQuery($user)
                ->selectRaw('COALESCE(SUM(os.valor_final * 0.1), 0) as total')
                ->whereRaw(
                    self::DELIVERED_SQL . ' AND os.tecnico_id = ? AND ' . self::DELIVERY_DATE_SQL . ' >= ? AND ' . self::DELIVERY_DATE_SQL . ' < ?',
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
        $staleThreshold = now()->copy()->subDays(15);

        $row = $this->baseOrdersQuery($user)
            ->selectRaw("
                SUM(CASE WHEN NOT " . self::DELIVERED_SQL . " AND " . self::STALE_REFERENCE_SQL . " < ? THEN 1 ELSE 0 END) as os_paradas,
                SUM(CASE WHEN NOT " . self::DELIVERED_SQL . " AND (os.orcamento_aprovado IS NULL OR os.orcamento_aprovado = 0) AND os.valor_total > 0 THEN 1 ELSE 0 END) as orcamentos_pendentes,
                SUM(CASE
                    WHEN LOWER(TRIM(os.estado_fluxo)) = 'pronto' THEN 1
                    WHEN (os.estado_fluxo IS NULL OR TRIM(os.estado_fluxo) = '') AND LOWER(TRIM(os.status)) IN ('pronto', 'concluido', 'aguardando_retirada') THEN 1
                    ELSE 0
                END) as prontos_retirada
            ", [$staleThreshold])
            ->first();

        return [
            'os_paradas' => (int) ($row->os_paradas ?? 0),
            'orcamentos_pendentes' => (int) ($row->orcamentos_pendentes ?? 0),
            'prontos_retirada' => (int) ($row->prontos_retirada ?? 0),
        ];
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

            return array_merge($order, [
                'dias_em_aberto' => $this->calculateOrderAgeDays($date),
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

    private function statusColor(?string $color): string
    {
        $color = trim((string) $color);
        if ($color === '') {
            return '#6b7280';
        }

        $normalized = mb_strtolower($color);

        return match ($normalized) {
            'primary', 'indigo', 'purple' => '#6f5afc',
            'secondary' => '#6b7280',
            'success' => '#22c55e',
            'warning', 'orange' => '#f59e0b',
            'danger' => '#ef4444',
            'info' => '#0ea5e9',
            'dark' => '#111827',
            'light' => '#e5e7eb',
            default => $this->isValidColorValue($color) ? $color : '#6b7280',
        };
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

    private function monthName(int $month): ?string
    {
        return self::MONTH_NAMES[$month] ?? null;
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

    private function isValidColorValue(string $color): bool
    {
        $color = trim($color);

        if ($color === '') {
            return false;
        }

        if (preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $color) === 1) {
            return true;
        }

        if (preg_match('/^(rgb|rgba|hsl|hsla)\([^)]+\)$/i', $color) === 1) {
            return true;
        }

        return in_array(mb_strtolower($color), [
            'black',
            'white',
            'gray',
            'grey',
            'red',
            'green',
            'blue',
            'yellow',
            'orange',
            'purple',
            'pink',
            'teal',
            'cyan',
            'lime',
            'indigo',
        ], true);
    }

    private function calculateOrderAgeDays(?Carbon $date): int
    {
        if (! $date instanceof Carbon) {
            return 0;
        }

        return max(0, (int) $date->diffInDays(now()));
    }
}
