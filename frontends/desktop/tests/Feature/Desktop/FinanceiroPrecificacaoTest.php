<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroPrecificacaoTest extends TestCase
{
    public function test_index_page_renders_precificacao_shell_from_backend_dataset(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/precificacao' => Http::response([
                'status' => 'success',
                'data' => [
                    'precificacao' => $this->precificacaoDataset(),
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'precificacao' => ['visualizar', 'editar'],
            ]))
            ->get('/financeiro/precificacao?tab=configuracao');

        $response
            ->assertOk()
            ->assertSee('Precificação')
            ->assertSee('Regras de peça')
            ->assertSee('Regras de serviço')
            ->assertSee('Simulador')
            ->assertSee('Salvar precificação')
            ->assertSee('Categorias de serviço');
    }

    public function test_simulator_routes_return_the_backend_calculation_payload(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/precificacao/simular-peca' => Http::response([
                'status' => 'success',
                'data' => [
                    'simulation' => [
                        'categoria_override' => [
                            'categoria_nome' => 'Insumos',
                        ],
                        'valor_recomendado' => 50.0,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/precificacao/simular-servico' => Http::response([
                'status' => 'success',
                'data' => [
                    'simulation' => [
                        'categoria_override' => [
                            'categoria_nome' => 'Software',
                        ],
                        'modo_precificacao' => 'servico_auto_recomendado',
                        'valor_recomendado' => 207.48,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $this->withSession($this->desktopSession([
            'precificacao' => ['visualizar', 'editar'],
        ]))->postJson('/financeiro/precificacao/simular-peca', [
            'peca_id' => 1,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('simulation.categoria_override.categoria_nome', 'Insumos')
            ->assertJsonPath('simulation.valor_recomendado', 50);

        $this->withSession($this->desktopSession([
            'precificacao' => ['visualizar', 'editar'],
        ]))->postJson('/financeiro/precificacao/simular-servico', [
            'servico_id' => 1,
            'tipo_equipamento' => 'Software',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('simulation.categoria_override.categoria_nome', 'Software')
            ->assertJsonPath('simulation.modo_precificacao', 'servico_auto_recomendado')
            ->assertJsonPath('simulation.valor_recomendado', 207.48);
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
     * @return array<string, mixed>
     */
    private function notificationsPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'items' => [],
                'unread_count' => 0,
            ],
            'error' => null,
            'meta' => [
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => 6,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => 0,
                    'to' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function precificacaoDataset(): array
    {
        return [
            'settings' => [
                'precificacao_peca_base' => 'custo',
                'precificacao_peca_encargos_percentual' => '12',
                'precificacao_peca_margem_percentual' => '45',
                'precificacao_peca_respeitar_preco_venda' => '1',
                'precificacao_peca_usa_componentes' => '1',
                'precificacao_servico_custo_hora_produtiva' => '40',
                'precificacao_servico_margem_percentual' => '25',
                'precificacao_servico_taxa_recebimento_percentual' => '3.5',
                'precificacao_servico_imposto_percentual' => '0',
                'precificacao_servico_tempo_padrao_horas' => '1',
                'precificacao_servico_usa_componentes' => '1',
                'precificacao_servico_aplicar_catalogo' => '1',
                'precificacao_servico_aplicar_piso' => '0',
            ],
            'summary' => [
                'componentes_peca_total' => 3,
                'componentes_servico_custo_total' => 1,
                'componentes_servico_risco_total' => 1,
                'categorias_peca_total' => 1,
                'categorias_servico_total' => 1,
                'servico_overrides_total' => 0,
            ],
            'rules_peca' => [
                'base' => 'custo',
                'encargos_percentual' => 12,
                'margem_percentual' => 45,
                'respeitar_preco_venda' => true,
                'usar_componentes' => true,
            ],
            'rules_servico' => [
                'custo_hora_produtiva' => 40,
                'margem_percentual' => 25,
                'taxa_recebimento_percentual' => 3.5,
                'imposto_percentual' => 0,
                'tempo_padrao_horas' => 1,
                'usar_componentes' => true,
                'aplicar_catalogo' => true,
                'aplicar_piso' => false,
            ],
            'componentes' => [
                'peca' => [],
                'servico_custo' => [],
                'servico_risco' => [],
            ],
            'categorias' => [
                'peca' => [
                    ['categoria_nome' => 'Insumos', 'encargos_percentual' => 5, 'margem_percentual' => 20],
                ],
                'servico' => [
                    ['categoria_nome' => 'Software', 'encargos_percentual' => 10, 'margem_percentual' => 35],
                ],
                'produto' => [],
            ],
            'categoria_encargos' => [],
            'servico_overrides' => [],
            'pecas' => [
                ['id' => 1, 'nome' => 'Placa de vídeo', 'preco_custo' => 25, 'preco_venda' => 50],
            ],
            'servicos' => [
                ['id' => 1, 'nome' => 'Reparo de software', 'valor' => 180],
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
