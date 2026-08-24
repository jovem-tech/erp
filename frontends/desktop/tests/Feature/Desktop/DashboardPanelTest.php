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

        $this->assertSame('27', $byKey['agenda_atrasados']['value']);
        $this->assertSame(route('agenda.index', ['view' => 'lista']), $byKey['agenda_atrasados']['url']);

        // Os links levam ao mesmo conjunto que o backend contou.
        $this->assertSame(
            route('orders.index', ['status_scope' => 'open', 'sem_movimento_dias' => 15]),
            $byKey['os_paradas']['url']
        );
        $this->assertSame(
            route('orders.index', ['status_scope' => 'open', 'pronto_retirada' => 1]),
            $byKey['prontos_retirada']['url']
        );
        $this->assertSame(route('estoque.index', ['estoque_baixo' => 1]), $byKey['estoque_baixo']['url']);

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

    /**
     * @param array<string, mixed> $overrides
     */
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
