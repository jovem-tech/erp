<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroReportTest extends TestCase
{
    public function test_dre_page_renders_competencia_values(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/dre*' => Http::response([
                'status' => 'success',
                'data' => ['dre' => $this->fakeDrePayload('competencia')],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/dre?mes=2026-06');

        $response->assertOk()
            ->assertSee('DRE por competência')
            ->assertSee('R$ 450,00');
    }

    public function test_dre_caixa_page_renders_caixa_values(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/dre-caixa*' => Http::response([
                'status' => 'success',
                'data' => ['dre' => $this->fakeDrePayload('caixa')],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/dre-caixa?mes=2026-06');

        $response->assertOk()->assertSee('DRE de caixa');
    }

    /**
     * A leitura gerencial e a que responde "quanto sobra de cada real vendido
     * para pagar o fixo". Precisa aparecer com a linha de margem de
     * contribuicao e a decomposicao dos custos variaveis — inclusive o CMV,
     * que a demonstracao contabil nao enxerga.
     */
    public function test_dre_page_renders_margem_de_contribuicao_e_analise_cvp(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/dre*' => Http::response([
                'status' => 'success',
                'data' => ['dre' => $this->fakeDrePayload('competencia')],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/dre?mes=2026-06');

        $response->assertOk()
            ->assertSee('Resultado gerencial (custeio variável)')
            ->assertSee('(=) Margem de contribuição')
            ->assertSee('Peças aplicadas (custo de estoque)')
            // CMV de R$ 150 — invisível na demonstração contábil.
            ->assertSee('R$ 150,00')
            ->assertSee('Análise custo-volume-lucro')
            ->assertSee('Ponto de equilíbrio')
            ->assertSee('Margem de segurança')
            ->assertSee('Alavancagem operacional');
    }

    /**
     * No regime de caixa a tela nao pode exibir uma MC: o custo da peca
     * pertence ao mes da entrega, nao ao mes do recebimento. Mostra o motivo
     * e o caminho para o relatorio correto.
     */
    public function test_dre_caixa_explica_ausencia_da_margem_de_contribuicao(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/dre-caixa*' => Http::response([
                'status' => 'success',
                'data' => ['dre' => $this->fakeDrePayload('caixa')],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/dre-caixa?mes=2026-06');

        $response->assertOk()
            ->assertSee('Margem de contribuição não se apura em regime de caixa')
            ->assertSee('Ver o DRE por competência')
            ->assertDontSee('Análise custo-volume-lucro');
    }

    public function test_fluxo_caixa_page_renders_linhas_diarias(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/fluxo-caixa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'fluxo' => [
                        'mes' => '2026-06',
                        'periodo_label' => '06/2026',
                        'saldo_inicial' => 0,
                        'entradas_realizadas' => 300,
                        'saidas_realizadas' => 0,
                        'saldo_final' => 300,
                        'entradas_previstas' => 0,
                        'saidas_previstas' => 90,
                        'saldo_projetado' => 210,
                        'realizados_por_categoria' => ['Serviços e peças de OS' => 300],
                        'previstos_por_categoria' => ['Internet' => 90],
                        'linhas_diarias' => [
                            ['data' => '2026-06-01', 'entradas_realizadas' => 300, 'saidas_realizadas' => 0, 'saldo_realizado' => 300],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/fluxo-caixa?mes=2026-06');

        $response->assertOk()
            ->assertSee('Fluxo de caixa')
            ->assertSee('R$ 300,00')
            ->assertSee('Internet')
            ->assertSee('cashflow-list-amount is-positive', false)
            ->assertSee('cashflow-list-amount is-negative', false)
            ->assertSee('cashflow-list-amount is-summary', false);
    }

    public function test_fluxo_caixa_calendar_view_renders_month_grid(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/relatorios/fluxo-caixa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'fluxo' => [
                        'mes' => '2026-06',
                        'periodo_label' => '06/2026',
                        'saldo_inicial' => 125,
                        'entradas_realizadas' => 300,
                        'saidas_realizadas' => 90,
                        'saldo_final' => 335,
                        'entradas_previstas' => 45,
                        'saidas_previstas' => 15,
                        'saldo_projetado' => 365,
                        'realizados_por_categoria' => ['Serviços e peças de OS' => 210],
                        'previstos_por_categoria' => ['Internet' => 15],
                        'linhas_diarias' => [
                            ['data' => '2026-06-01', 'entradas_realizadas' => 300, 'saidas_realizadas' => 0, 'saldo_realizado' => 300],
                            ['data' => '2026-06-02', 'entradas_realizadas' => 0, 'saidas_realizadas' => 90, 'saldo_realizado' => 210],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/fluxo-caixa?mes=2026-06&view=calendar');

        $response->assertOk()
            ->assertSee('Calendário de lançamentos')
            ->assertSee('Junho de 2026')
            ->assertSee('Sem lançamentos')
            ->assertSee('data-cashflow-day="2026-06-01"', false)
            ->assertSee('data-cashflow-day="2026-06-02"', false)
            ->assertDontSee('R$ 0,00')
            ->assertDontSee('Saldo positivo no dia');
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDrePayload(string $modo): array
    {
        // Margem de contribuição é conceito de competência: o backend devolve
        // o bloco indisponível (com motivo) no regime de caixa, e a tela troca
        // a demonstração gerencial por um aviso.
        $gerencial = $modo === 'caixa'
            ? [
                'disponivel' => false,
                'motivo' => 'A margem de contribuição é apurada por competência.',
                'custos_fixos' => 100,
                'despesas_variaveis' => 0,
            ]
            : [
                'disponivel' => true,
                'receita_liquida' => 450,
                'custos_variaveis' => [
                    'cmv_pecas' => 150,
                    'comissoes' => 30,
                    'despesas_variaveis' => 0,
                    'custos_diretos_os' => 0,
                    'total' => 180,
                ],
                'margem_contribuicao' => 270,
                'indice_contribuicao_percentual' => 60,
                'custos_fixos' => 100,
                'outras_receitas' => 0,
                'resultado_operacional' => 170,
                'analise_cvp' => [
                    'ponto_equilibrio_receita' => 166.67,
                    'ponto_equilibrio_atingido' => true,
                    'percentual_do_equilibrio' => 270.0,
                    'margem_seguranca_valor' => 283.33,
                    'margem_seguranca_percentual' => 62.96,
                    'grau_alavancagem_operacional' => 1.59,
                ],
                'reconciliacao' => [
                    'margem_soma_os' => 270,
                    'imposto_estimado_os' => 0,
                    'taxa_recebimento_os' => 0,
                ],
            ];

        return [
            'periodo_label' => '06/2026',
            'modo' => $modo,
            'receita' => ['receita_bruta' => 500, 'descontos' => 50, 'receita_liquida' => 450, 'total_os' => 1],
            'custos_diretos' => ['total' => 0, 'por_subgrupo' => []],
            'outras_receitas' => ['total' => 0, 'por_subgrupo' => []],
            'despesas_operacionais' => ['total' => 100, 'por_subgrupo' => ['Aluguel' => 100]],
            'lucro_bruto' => 450,
            'resultado_liquido' => 350,
            'gerencial' => $gerencial,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeNotificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => ['items' => [], 'unread_count' => 0],
            'error' => null,
            'meta' => [
                'pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0],
            ],
        ];
    }

    /**
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => $this->fakeUser([
                    'permissions' => $permissions,
                    'modules' => array_keys($permissions),
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function fakeUser(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 99,
            'nome' => 'Usuário de Teste',
            'email' => 'usuario@teste.local',
            'perfil' => 'admin',
            'group' => [
                'id' => 1,
                'nome' => 'Administrador',
                'descricao' => 'Grupo completo',
                'sistema' => true,
            ],
            'modules' => [],
            'permissions' => [],
            'foto' => '',
            'ativo' => true,
        ], $overrides);
    }
}
