<?php

namespace App\Services;

use App\Support\OrderStatusMacroGroups;

class DashboardService
{
    /**
     * Espelha DashboardSummaryService::STALE_ORDER_DAYS do backend. O desktop
     * não importa classes da API, então o número é repetido aqui — mas é o
     * único ponto de repetição, e o teste de painel cobre a divergência.
     */
    private const STALE_ORDER_DAYS = 15;

    /**
     * @var array<int, string>
     */
    private const MONTH_LABELS = [
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

    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function bootstrapFilters(array $filters = []): array
    {
        $currentYear = (int) now()->year;
        $currentMonth = (int) now()->month;
        $year = $this->bootstrapYear($filters['ano'] ?? null, $currentYear);
        $equipmentYear = $this->bootstrapYear($filters['equip_ano'] ?? null, $currentYear);
        $equipmentMonth = $this->bootstrapMonth($filters['equip_mes'] ?? null, $currentMonth);

        return [
            'year' => $year,
            'years' => $this->bootstrapYears($year, $currentYear),
            'equipmentMonth' => $equipmentMonth,
            'equipmentYear' => $equipmentYear,
            'equipmentYears' => $this->bootstrapYears($equipmentYear, $currentYear),
            'months' => self::MONTH_LABELS,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $agenda resumo da agenda ja carregado pelo
     *        controller (vazio quando o usuario nao tem a permissao)
     * @return array<string, mixed>
     */
    public function summary(array $filters = [], array $agenda = []): array
    {
        $query = array_filter($filters, static fn ($value): bool => $value !== null && $value !== '');
        $response = $this->apiClient->get('/dashboard/summary', $query);

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $heroCard = $this->normalizeHeroCard($data['hero_card'] ?? []);
        $access = $this->arrayValue($data['access'] ?? []);
        $stats = $this->arrayValue($data['stats'] ?? []);
        $alerts = $this->arrayValue($data['alerts'] ?? []);
        $financial = $this->arrayValue($data['charts']['financial'] ?? []);

        return [
            'access' => $access,
            'stats' => $stats,
            'alerts' => $alerts,
            'attention' => $this->buildAttentionItems($access, $stats, $alerts, $financial, $agenda),
            'revenueTrend' => $this->buildRevenueTrend($stats),
            'heroCard' => $heroCard,
            'secondaryCard' => $this->buildSecondaryCard($access, $stats),
            'contextCard' => $this->arrayValue($data['context_card'] ?? []),
            'charts' => [
                'monthly' => $this->arrayValue($data['charts']['monthly'] ?? []),
                'status' => $this->groupStatusChart($this->arrayValue($data['charts']['status'] ?? []), $access),
                'equipmentTypes' => $this->arrayValue($data['charts']['equipment_types'] ?? []),
                'financial' => $financial,
                'technician' => $this->arrayValue($data['charts']['technician'] ?? []),
            ],
            'filters' => [
                'year' => (int) ($data['filters']['year'] ?? date('Y')),
                'years' => $this->intList($data['filters']['years'] ?? []),
                'equipmentMonth' => (int) ($data['filters']['equipment_month'] ?? date('n')),
                'equipmentYear' => (int) ($data['filters']['equipment_year'] ?? date('Y')),
                'equipmentYears' => $this->intList($data['filters']['equipment_years'] ?? []),
                'months' => $this->arrayValue($data['filters']['months'] ?? []),
            ],
            'recentOrders' => $this->decorateRecentOrders($this->listValue($data['recent_orders'] ?? [])),
            'recentClients' => $this->listValue($data['recent_clients'] ?? []),
            'recentEquipments' => $this->listValue($data['recent_equipments'] ?? []),
            'lowStock' => $this->listValue($data['low_stock'] ?? []),
            'lowStockTotal' => (int) ($stats['low_stock_total'] ?? 0),
            'raw' => $data,
        ];
    }

    /**
     * Prioridades do dia, na ordem em que merecem atenção.
     *
     * Regra central: item zerado não vira card. Um "0 compromissos atrasados"
     * exibido todo dia ensina o usuário a ignorar aquela região da tela — e
     * quando a lista inteira sai vazia isso é uma boa notícia, não um vazio a
     * preencher (quem trata disso é o estado "Tudo certo por aqui" na view).
     *
     * Toda URL aqui aponta para um filtro que a tela de destino realmente
     * aplica; os três alertas operacionais usam os mesmos predicados que o
     * backend contou (OrderWorkflowService::{STALE_REFERENCE,PENDING_BUDGET,
     * READY_PICKUP}_SQL), então o número do chip se reproduz na listagem.
     *
     * @param array<string, mixed> $access
     * @param array<string, mixed> $stats
     * @param array<string, mixed> $alerts
     * @param array<string, mixed> $financial
     * @param array<string, mixed> $agenda
     * @return array<int, array<string, mixed>>
     */
    private function buildAttentionItems(
        array $access,
        array $stats,
        array $alerts,
        array $financial,
        array $agenda
    ): array {
        $canViewOrders = (bool) ($access['can_view_orders'] ?? false);
        $items = [];

        $atrasados = (int) ($agenda['atrasados'] ?? 0);
        if ($atrasados > 0) {
            $items[] = [
                'key' => 'agenda_atrasados',
                'tone' => 'danger',
                'value' => $this->formatCount($atrasados),
                'raw' => $atrasados,
                'label' => $atrasados === 1 ? 'compromisso atrasado' : 'compromissos atrasados',
                'action_label' => 'Ver agenda',
                'url' => route('agenda.index', ['view' => 'lista']),
            ];
        }

        $paradas = (int) ($alerts['os_paradas'] ?? 0);
        if ($canViewOrders && $paradas > 0) {
            $items[] = [
                'key' => 'os_paradas',
                'tone' => 'danger',
                'value' => $this->formatCount($paradas),
                'raw' => $paradas,
                'label' => 'OS sem movimento há +' . self::STALE_ORDER_DAYS . ' dias',
                'action_label' => 'Ver OS paradas',
                'url' => route('orders.index', [
                    'status_scope' => 'open',
                    'sem_movimento_dias' => self::STALE_ORDER_DAYS,
                ]),
            ];
        }

        $orcamentos = (int) ($alerts['orcamentos_pendentes'] ?? 0);
        if ($canViewOrders && $orcamentos > 0) {
            $items[] = [
                'key' => 'orcamentos_pendentes',
                'tone' => 'warning',
                'value' => $this->formatCount($orcamentos),
                'raw' => $orcamentos,
                'label' => $orcamentos === 1 ? 'orçamento sem aprovação' : 'orçamentos sem aprovação',
                'action_label' => 'Ver orçamentos',
                'url' => route('orders.index', [
                    'status_scope' => 'open',
                    'orcamento_pendente' => 1,
                ]),
            ];
        }

        $abertas = (int) ($stats['orders'] ?? 0);
        if ($canViewOrders && $abertas > 0) {
            $items[] = [
                'key' => 'os_abertas',
                'tone' => 'attention',
                'value' => $this->formatCount($abertas),
                'raw' => $abertas,
                'label' => 'OS em andamento',
                'action_label' => 'Ver OS',
                'url' => route('orders.index', ['status_scope' => 'open']),
            ];
        }

        $lowStock = (int) ($stats['low_stock_total'] ?? 0);
        if (($access['can_view_stock'] ?? false) && $lowStock > 0) {
            $items[] = [
                'key' => 'estoque_baixo',
                'tone' => 'attention',
                'value' => $this->formatCount($lowStock),
                'raw' => $lowStock,
                'label' => $lowStock === 1 ? 'item abaixo do estoque mínimo' : 'itens abaixo do estoque mínimo',
                'action_label' => 'Ver estoque',
                'url' => route('estoque.index', ['estoque_baixo' => 1]),
            ];
        }

        // "Pendentes" do resumo financeiro é contas a PAGAR pendentes/parciais
        // com vencimento até o fim do mês corrente — não valor a receber. O
        // rótulo diz isso explicitamente para o chip não prometer entrada de
        // caixa onde há saída.
        $pendentes = (float) ($financial['pendentes'] ?? 0);
        if (($access['has_financial_access'] ?? false) && $pendentes > 0) {
            $items[] = [
                'key' => 'financeiro_pendente',
                'tone' => 'info',
                'value' => $this->formatMoney($pendentes),
                'raw' => $pendentes,
                'label' => 'pendentes a pagar',
                'action_label' => 'Ver financeiro',
                'url' => route('financeiro.index', ['tipo' => 'pagar', 'status' => 'pendente']),
            ];
        }

        $prontos = (int) ($alerts['prontos_retirada'] ?? 0);
        if ($canViewOrders && $prontos > 0) {
            $items[] = [
                'key' => 'prontos_retirada',
                'tone' => 'success',
                'value' => $this->formatCount($prontos),
                'raw' => $prontos,
                'label' => $prontos === 1 ? 'OS pronta para retirada' : 'OS prontas para retirada',
                'action_label' => 'Ver prontas',
                'url' => route('orders.index', [
                    'status_scope' => 'open',
                    'pronto_retirada' => 1,
                ]),
            ];
        }

        return $items;
    }

    /**
     * Variação percentual do faturamento contra o mês anterior.
     *
     * É a única tendência do painel porque é a única com base real: o backend
     * expõe faturamento_mes_anterior, e não há equivalente para OS abertas nem
     * para resultado de caixa. Devolve null quando o mês anterior foi zero —
     * qualquer valor sobre zero renderiza um "+∞%" sem significado.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>|null
     */
    private function buildRevenueTrend(array $stats): ?array
    {
        $current = (float) ($stats['faturamento_mes'] ?? 0);
        $previous = (float) ($stats['faturamento_mes_anterior'] ?? 0);

        if ($previous <= 0.0) {
            return null;
        }

        $variation = (($current - $previous) / $previous) * 100;

        return [
            'direction' => $variation >= 0 ? 'up' : 'down',
            'percent' => abs(round($variation, 1)),
            'label' => sprintf(
                '%s%s%% vs. mês anterior',
                $variation >= 0 ? '↑ ' : '↓ ',
                number_format(abs(round($variation, 1)), 1, ',', '.')
            ),
        ];
    }

    /**
     * Card "Despesas pagas" x "Equipamento entregue": mesmo critério de
     * has_financial_access do heroCard/contextCard — quem não enxerga
     * faturamento também não vê quanto a assistência pagou nem o que está
     * pendente, e continua vendo a métrica operacional original.
     *
     * @param array<string, mixed> $access
     * @param array<string, mixed> $stats
     * @return array<string, mixed>
     */
    private function buildSecondaryCard(array $access, array $stats): array
    {
        if (! ($access['has_financial_access'] ?? false)) {
            return [
                'type' => 'operational',
                'label' => 'Equipamento entregue',
                'value' => (int) ($stats['equipamento_entregue_total'] ?? 0),
                'value_type' => 'count',
                'trend' => null,
                'meta' => 'Ordens concluídas e baixadas com entrega técnica registrada.',
                'icon' => 'bi-box2-heart-fill',
                'accent' => '#f59e0b',
            ];
        }

        $pendentes = (float) ($stats['despesas_pendentes'] ?? 0);

        return [
            'type' => 'financial',
            'label' => 'Despesas pagas',
            'value' => (float) ($stats['despesas_pagas_mes'] ?? 0),
            'value_type' => 'money',
            'trend' => $this->buildExpenseTrend($stats),
            'meta' => $pendentes > 0
                ? $this->formatMoney($pendentes) . ' em contas pendentes (mês atual e anteriores).'
                : 'Nenhuma conta a pagar pendente.',
            'icon' => 'bi-cash-coin',
            'accent' => '#ef4444',
        ];
    }

    /**
     * Variação das despesas pagas contra o mês anterior. Igual ao
     * buildRevenueTrend em forma, mas invertido em significado: para
     * faturamento, subir é bom; para despesa, subir é ruim. `good` carrega
     * esse julgamento separado de `direction` (que só descreve o número) para
     * a view pintar a cor certa sem repetir a inversão.
     *
     * @param array<string, mixed> $stats
     * @return array<string, mixed>|null
     */
    private function buildExpenseTrend(array $stats): ?array
    {
        $current = (float) ($stats['despesas_pagas_mes'] ?? 0);
        $previous = (float) ($stats['despesas_pagas_mes_anterior'] ?? 0);

        if ($previous <= 0.0) {
            return null;
        }

        $variation = (($current - $previous) / $previous) * 100;
        $increased = $variation >= 0;

        return [
            'direction' => $increased ? 'up' : 'down',
            'good' => ! $increased,
            'percent' => abs(round($variation, 1)),
            'label' => sprintf(
                '%s%s%% vs. mês anterior',
                $increased ? '↑ ' : '↓ ',
                number_format(abs(round($variation, 1)), 1, ',', '.')
            ),
        ];
    }

    /**
     * Agrupa os status do donut pelas macrofases do catálogo.
     *
     * Puramente visual: `items` continua intacto com os status reais, e o
     * agrupamento reusa `grupo_macro`, que o backend já devolve por status.
     * Nada no banco ou na regra de negócio muda — a tela só para de exibir
     * catorze fatias que ninguém consegue ler de relance.
     *
     * @param array<string, mixed> $statusChart
     * @param array<string, mixed> $access
     * @return array<string, mixed>
     */
    private function groupStatusChart(array $statusChart, array $access): array
    {
        $items = $this->listValue($statusChart['items'] ?? []);
        if ($items === []) {
            $statusChart['groups'] = [];

            return $statusChart;
        }

        $canViewOrders = (bool) ($access['can_view_orders'] ?? false);
        $groups = [];

        foreach ($items as $item) {
            $slug = mb_strtolower(trim((string) ($item['grupo_macro'] ?? '')));
            if ($slug === '') {
                $slug = 'outros';
            }

            $total = (int) ($item['total'] ?? 0);

            if (! array_key_exists($slug, $groups)) {
                $groups[$slug] = [
                    'slug' => $slug,
                    'nome' => OrderStatusMacroGroups::label($slug),
                    'cor' => OrderStatusMacroGroups::accent($slug),
                    'total' => 0,
                    'url' => $canViewOrders
                        ? route('orders.index', ['grupo_macro' => $slug])
                        : null,
                    'itens' => [],
                ];
            }

            $groups[$slug]['total'] += $total;
            $groups[$slug]['itens'][] = [
                'codigo' => (string) ($item['codigo'] ?? ''),
                'nome' => (string) ($item['nome'] ?? 'Sem status'),
                'total' => $total,
                'cor' => (string) ($item['cor'] ?? '#6f5afc'),
                'url' => $canViewOrders && trim((string) ($item['codigo'] ?? '')) !== ''
                    ? route('orders.index', ['status' => (string) $item['codigo']])
                    : null,
            ];
        }

        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $statusChart['groups'] = $groups;

        return $statusChart;
    }

    /**
     * Marcador de prioridade por OS da tabela.
     *
     * Sem regra inventada: 🔴 usa o mesmo limiar de dias sem movimento do
     * alerta os_paradas, e 🟡/🟢 leem `status_grupo_macro`, que já vem em cada
     * OS. Uma OS sem nada de especial não recebe marcador — pintar todas as
     * linhas é o mesmo que não pintar nenhuma.
     *
     * @param array<int, array<string, mixed>> $orders
     * @return array<int, array<string, mixed>>
     */
    private function decorateRecentOrders(array $orders): array
    {
        return array_map(function (array $order): array {
            $stale = (int) ($order['dias_sem_movimento'] ?? 0);
            $macro = mb_strtolower(trim((string) ($order['status_grupo_macro'] ?? '')));

            $priority = null;

            if ($stale > self::STALE_ORDER_DAYS) {
                $priority = [
                    'tone' => 'danger',
                    'label' => sprintf('Sem movimento há %d dias', $stale),
                ];
            } elseif ($macro === 'concluido') {
                $priority = [
                    'tone' => 'success',
                    'label' => 'Pronta para entrega',
                ];
            } elseif (in_array($macro, ['orcamento', 'interrupcao'], true)) {
                $priority = [
                    'tone' => 'warning',
                    'label' => $macro === 'orcamento' ? 'Aguardando cliente' : 'Fluxo interrompido',
                ];
            }

            $order['priority'] = $priority;

            return $order;
        }, $orders);
    }

    private function formatCount(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    private function formatMoney(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    /**
     * Normaliza CTAs recebidos da API para rotas válidas do frontend desktop.
     *
     * O backend central pode expor links de API como fallback de contrato, mas o
     * dashboard do desktop precisa apontar para rotas da interface, nao para a
     * origem da API.
     *
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeHeroCard(mixed $value): array
    {
        $heroCard = $this->arrayValue($value);
        $actionUrl = trim((string) ($heroCard['action_url'] ?? ''));

        if ($actionUrl === '') {
            $heroCard['action_url'] = null;

            return $heroCard;
        }

        $path = parse_url($actionUrl, PHP_URL_PATH);
        $normalizedPath = '/' . ltrim(is_string($path) && $path !== '' ? $path : $actionUrl, '/');

        if ($normalizedPath === '/api/v1/orders' || str_starts_with($normalizedPath, '/api/v1/orders/')) {
            $heroCard['action_url'] = route('orders.index');

            return $heroCard;
        }

        if (str_starts_with($normalizedPath, '/api/v1/')) {
            $heroCard['action_url'] = null;

            return $heroCard;
        }

        $heroCard['action_url'] = $actionUrl;

        return $heroCard;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function listValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => is_array($item)));
    }

    /**
     * @param mixed $value
     * @return array<int, int>
     */
    private function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_int($item) || is_numeric($item)) {
                $items[] = (int) $item;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int, int>
     */
    private function bootstrapYears(int $selectedYear, int $currentYear): array
    {
        $years = range($currentYear, $currentYear - 4);

        if (! in_array($selectedYear, $years, true)) {
            $years[] = $selectedYear;
        }

        $years = array_values(array_unique(array_map(static fn ($year): int => (int) $year, $years)));
        rsort($years, SORT_NUMERIC);

        return $years;
    }

    private function bootstrapYear(mixed $value, int $fallback): int
    {
        if ((is_int($value) || is_numeric($value)) && (int) $value > 0) {
            return (int) $value;
        }

        return $fallback;
    }

    private function bootstrapMonth(mixed $value, int $fallback): int
    {
        if ((is_int($value) || is_numeric($value))) {
            $month = (int) $value;

            if ($month >= 1 && $month <= 12) {
                return $month;
            }
        }

        return $fallback;
    }
}
