<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Cobre a camada de apresentação do dashboard: o painel de atenção, o
 * agrupamento visual do donut e o marcador de prioridade da tabela de OS.
 *
 * Tudo isso vive no DashboardService, e não na view, porque a mesma estrutura
 * alimenta o primeiro render (Blade) e o refresh por filtro (/dashboard/dados).
 */
class DashboardPanelTest extends TestCase
{
    public function test_attention_items_carry_real_routes_and_skip_zeroed_alerts(): void
    {
        $this->fakeBackend([
            'alerts' => [
                'os_paradas' => 4,
                'orcamentos_pendentes' => 0,
                'prontos_retirada' => 2,
            ],
            'stats' => [
                'orders' => 71,
                'low_stock_total' => 3,
            ],
            'charts' => [
                'financial' => ['pendentes' => 678.92],
            ],
        ]);

        $payload = $this->dashboardData(['agenda' => ['atrasados' => 27]]);
        $items = collect($payload['attention']);
        $byKey = $items->keyBy('key');

        // Zerado não vira card: um alerta que aparece sempre ensina o usuário a
        // ignorar aquela região da tela.
        $this->assertFalse($byKey->has('orcamentos_pendentes'));

        // Fora do painel de propósito, mesmo com dado real disponível: cada um
        // já aparece em outro lugar da tela (card "OS abertas", lista de
        // estoque baixo, grupo "Concluído" do gráfico de status) — repetir o
        // número aqui era ruído, não uma segunda prioridade.
        $this->assertFalse($byKey->has('os_abertas'));
        $this->assertFalse($byKey->has('estoque_baixo'));
        $this->assertFalse($byKey->has('prontos_retirada'));

        $this->assertSame('27', $byKey['agenda_atrasados']['value']);
        $this->assertSame(route('agenda.index', ['view' => 'lista']), $byKey['agenda_atrasados']['url']);

        // Os links levam ao mesmo conjunto que o backend contou.
        $this->assertSame(
            route('orders.index', ['status_scope' => 'open', 'sem_movimento_dias' => 15]),
            $byKey['os_paradas']['url']
        );

        // "Pendentes" é contas a pagar — o rótulo não pode prometer recebimento.
        $this->assertSame('R$ 678,92', $byKey['financeiro_pendente']['value']);
        $this->assertSame('pendentes a pagar', $byKey['financeiro_pendente']['label']);
        $this->assertSame(
            route('financeiro.index', ['tipo' => 'pagar', 'status' => 'pendente']),
            $byKey['financeiro_pendente']['url']
        );
    }

    public function test_attention_is_empty_when_nothing_needs_action(): void
    {
        $this->fakeBackend([
            'alerts' => ['os_paradas' => 0, 'orcamentos_pendentes' => 0, 'prontos_retirada' => 0],
            'stats' => ['orders' => 0, 'low_stock_total' => 0],
            'charts' => ['financial' => ['pendentes' => 0]],
        ]);

        $this->assertSame([], $this->dashboardData(['agenda' => ['atrasados' => 0]])['attention']);
    }

    public function test_status_chart_groups_statuses_by_macro_phase_without_touching_the_real_ones(): void
    {
        $this->fakeBackend([
            'charts' => [
                'status' => [
                    'total' => 9,
                    'items' => [
                        ['codigo' => 'diagnostico_tecnico', 'nome' => 'Diagnóstico Técnico', 'cor' => '#111', 'grupo_macro' => 'diagnostico', 'total' => 2],
                        ['codigo' => 'triagem', 'nome' => 'Triagem', 'cor' => '#222', 'grupo_macro' => 'recepcao', 'total' => 1],
                        ['codigo' => 'aguardando_peca', 'nome' => 'Aguardando Peça', 'cor' => '#333', 'grupo_macro' => 'interrupcao', 'total' => 5],
                        ['codigo' => 'verificacao', 'nome' => 'Verificação', 'cor' => '#444', 'grupo_macro' => 'diagnostico', 'total' => 1],
                    ],
                ],
            ],
        ]);

        $status = $this->dashboardData()['charts']['status'];

        // Os status reais continuam intactos no payload — o agrupamento é só
        // uma leitura adicional.
        $this->assertCount(4, $status['items']);

        $groups = collect($status['groups']);
        $this->assertSame(['Interrupção', 'Diagnóstico', 'Recepção'], $groups->pluck('nome')->all());
        $this->assertSame([5, 3, 1], $groups->pluck('total')->all());

        $diagnostico = $groups->firstWhere('slug', 'diagnostico');
        $this->assertSame(route('orders.index', ['grupo_macro' => 'diagnostico']), $diagnostico['url']);
        $this->assertSame(
            route('orders.index', ['status' => 'diagnostico_tecnico']),
            $diagnostico['itens'][0]['url']
        );
    }

    public function test_recent_orders_get_priority_markers_from_real_fields_only(): void
    {
        $this->fakeBackend([
            'recent_orders' => [
                ['id' => 1, 'numero_os' => 'OS1', 'dias_sem_movimento' => 40, 'status_grupo_macro' => 'execucao'],
                ['id' => 2, 'numero_os' => 'OS2', 'dias_sem_movimento' => 2, 'status_grupo_macro' => 'concluido'],
                ['id' => 3, 'numero_os' => 'OS3', 'dias_sem_movimento' => 2, 'status_grupo_macro' => 'orcamento'],
                ['id' => 4, 'numero_os' => 'OS4', 'dias_sem_movimento' => 2, 'status_grupo_macro' => 'execucao'],
            ],
        ]);

        $orders = collect($this->dashboardData()['recentOrders'])->keyBy('numero_os');

        $this->assertSame('danger', $orders['OS1']['priority']['tone']);
        $this->assertSame('Sem movimento há 40 dias', $orders['OS1']['priority']['label']);
        $this->assertSame('success', $orders['OS2']['priority']['tone']);
        $this->assertSame('warning', $orders['OS3']['priority']['tone']);
        // Sem nada de especial não recebe marcador: pintar todas as linhas
        // equivale a não pintar nenhuma.
        $this->assertNull($orders['OS4']['priority']);
    }

    public function test_revenue_trend_compares_against_the_previous_month(): void
    {
        $this->fakeBackend([
            'stats' => ['faturamento_mes' => 2150.0, 'faturamento_mes_anterior' => 2000.0],
        ]);

        $trend = $this->dashboardData()['revenueTrend'];

        $this->assertSame('up', $trend['direction']);
        $this->assertSame('↑ 7,5% vs. mês anterior', $trend['label']);
    }

    public function test_revenue_trend_is_absent_without_a_previous_month_to_compare_against(): void
    {
        $this->fakeBackend([
            'stats' => ['faturamento_mes' => 2150.0, 'faturamento_mes_anterior' => 0.0],
        ]);

        // Sem base de comparação não se inventa percentual — qualquer valor
        // sobre zero renderiza um "+∞%" sem significado.
        $this->assertNull($this->dashboardData()['revenueTrend']);
    }

    public function test_secondary_card_shows_paid_expenses_with_inverted_trend_color_for_financial_users(): void
    {
        $this->fakeBackend([
            'stats' => [
                'despesas_pagas_mes' => 2200.0,
                'despesas_pagas_mes_anterior' => 2000.0,
                'despesas_pendentes' => 340.50,
            ],
        ]);

        $card = $this->dashboardData()['secondaryCard'];

        $this->assertSame('financial', $card['type']);
        $this->assertSame('Despesas pagas', $card['label']);
        $this->assertSame(2200.0, $card['value']);
        $this->assertSame('R$ 340,50 em contas pendentes (mês atual e anteriores).', $card['meta']);

        // Gastar mais é notícia ruim: ao contrário da tendência de
        // faturamento, aqui a seta "para cima" (subiu 10%) precisa pintar de
        // vermelho — o campo `good` carrega esse julgamento invertido.
        $this->assertSame('up', $card['trend']['direction']);
        $this->assertFalse($card['trend']['good']);
        $this->assertSame('↑ 10,0% vs. mês anterior', $card['trend']['label']);
    }

    public function test_secondary_card_shows_a_falling_expense_trend_as_good(): void
    {
        $this->fakeBackend([
            'stats' => [
                'despesas_pagas_mes' => 800.0,
                'despesas_pagas_mes_anterior' => 1000.0,
                'despesas_pendentes' => 0,
            ],
        ]);

        $card = $this->dashboardData()['secondaryCard'];

        $this->assertSame('down', $card['trend']['direction']);
        $this->assertTrue($card['trend']['good']);
        $this->assertSame('Nenhuma conta a pagar pendente.', $card['meta']);
    }

    public function test_secondary_card_falls_back_to_delivered_equipment_without_financial_access(): void
    {
        $this->fakeBackend([
            'access' => ['has_financial_access' => false],
            'stats' => [
                'equipamento_entregue_total' => 2160,
                // Presentes no payload mas não podem vazar para quem não tem
                // acesso financeiro.
                'despesas_pagas_mes' => 2200.0,
                'despesas_pagas_mes_anterior' => 2000.0,
            ],
        ]);

        $card = $this->dashboardData()['secondaryCard'];

        $this->assertSame('operational', $card['type']);
        $this->assertSame('Equipamento entregue', $card['label']);
        $this->assertSame(2160, $card['value']);
        $this->assertNull($card['trend']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    /**
     * A partial do grafico financeiro tem de chegar na pagina com o canvas, a
     * legenda e o alternador — o backend pode mandar o payload perfeito e o
     * grafico nao aparecer se o include cair fora.
     */
    public function test_dashboard_page_renders_financial_chart_panel(): void
    {
        // Cache em memória: renderizar a página inteira aciona o
        // CompanyProfileService, que grava no cache de arquivo — diretório do
        // www-data, onde o usuário que roda a suíte não escreve.
        config(['cache.default' => 'array']);

        $permissions = ['dashboard' => ['visualizar'], 'financeiro' => ['visualizar']];

        $response = $this
            ->withSession([
                'desktop_auth' => [
                    'token' => 'desktop-session-token',
                    'synced_at' => time(),
                    'user' => [
                        'id' => 99,
                        'nome' => 'Gerente',
                        'email' => 'gerente@example.com',
                        'perfil' => 'gerente',
                        'ativo' => true,
                        'modules' => array_keys($permissions),
                        'permissions' => $permissions,
                    ],
                ],
            ])
            ->get('/dashboard');

        $response->assertOk()
            ->assertSee('Evolução financeira')
            ->assertSee('data-dashboard-financial-legend', false)
            // UM canvas: as cinco séries são um combo só, no mesmo eixo.
            ->assertSee('dashboardFinancialChart', false)
            ->assertDontSee('dashboardFinancialRevenueChart', false)
            ->assertDontSee('dashboardFinancialExpenseChart', false)
            ->assertDontSee('dashboardFinancialProfitChart', false)
            // O alternador de regime, com os dois estados.
            ->assertSee('data-dashboard-financial-regime="competencia"', false)
            ->assertSee('data-dashboard-financial-regime="caixa"', false)
            // E o de granularidade.
            ->assertSee('data-dashboard-financial-granularidade="mensal"', false)
            ->assertSee('data-dashboard-financial-granularidade="trimestral"', false);
    }

    /**
     * O bloco do grafico financeiro precisa atravessar o DashboardService
     * intacto: e o front que escolhe o regime, entao os dois vem juntos.
     */
    public function test_financial_monthly_chart_atravessa_o_servico_com_os_dois_regimes(): void
    {
        $this->fakeBackend([
            'charts' => [
                'financial_monthly' => [
                    'year' => 2026,
                    'labels' => ['Jan', 'Fev'],
                    'mes_atual' => 9,
                    'ano_corrente' => 2026,
                    'regimes' => [
                        'competencia' => ['lucro' => [100.0, -50.0]],
                        'caixa' => ['lucro' => [80.0, -20.0]],
                    ],
                    'legend' => [['key' => 'lucro', 'label' => 'Lucro líquido', 'color' => '#16a34a']],
                ],
            ],
        ]);

        $chart = $this->dashboardData()['charts']['financialMonthly'];

        $this->assertSame(2026, $chart['year']);
        // assertEquals, não assertSame: o payload cruza uma serialização JSON
        // no caminho e 100.0 volta como int 100. O que importa é o valor
        // chegar íntegro, não o tipo numérico sobreviver ao round-trip.
        $this->assertEquals([100.0, -50.0], $chart['regimes']['competencia']['lucro']);
        $this->assertEquals([80.0, -20.0], $chart['regimes']['caixa']['lucro']);
        $this->assertSame('Lucro líquido', $chart['legend'][0]['label']);
    }

    /**
     * Sem o bloco no payload (usuario sem acesso financeiro), o servico devolve
     * array vazio e a partial se esconde sozinha — nunca um grafico zerado.
     */
    public function test_financial_monthly_chart_fica_vazio_quando_o_backend_nao_manda(): void
    {
        $this->fakeBackend();

        $this->assertSame([], $this->dashboardData()['charts']['financialMonthly']);
    }

    private function fakeBackend(array $overrides = []): void
    {
        // O ApiClient lê o token da sessão do desktop; sem ele a chamada morre
        // em ApiAuthenticationException antes de bater no Http::fake.
        session([
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 1,
                    'nome' => 'Gerente',
                    'email' => 'gerente@example.com',
                    'perfil' => 'gerente',
                    'ativo' => true,
                ],
            ],
        ]);

        $data = array_replace_recursive([
            'access' => [
                'can_view_orders' => true,
                'can_view_stock' => true,
                'has_financial_access' => true,
            ],
            'stats' => [],
            'alerts' => [],
            'charts' => ['financial' => [], 'status' => ['items' => []]],
            'recent_orders' => [],
            'low_stock' => [],
        ], $overrides);

        Http::fake([
            'http://127.0.0.1:8000/api/v1/dashboard/summary*' => Http::response([
                'status' => 'success',
                'data' => $data,
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/agenda/resumo' => Http::response([
                'status' => 'success',
                'data' => ['atrasados' => 0, 'hoje' => 0, 'proximos' => []],
                'error' => null,
                'meta' => [],
            ]),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function dashboardData(array $context = []): array
    {
        /** @var \App\Services\DashboardService $service */
        $service = app(\App\Services\DashboardService::class);

        return $service->summary([], $context['agenda'] ?? []);
    }
}
