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
            ->assertSee('Internet');
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeDrePayload(string $modo): array
    {
        return [
            'periodo_label' => '06/2026',
            'modo' => $modo,
            'receita' => ['receita_bruta' => 500, 'descontos' => 50, 'receita_liquida' => 450, 'total_os' => 1],
            'custos_diretos' => ['total' => 0, 'por_subgrupo' => []],
            'outras_receitas' => ['total' => 0, 'por_subgrupo' => []],
            'despesas_operacionais' => ['total' => 100, 'por_subgrupo' => ['Aluguel' => 100]],
            'lucro_bruto' => 450,
            'resultado_liquido' => 350,
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
