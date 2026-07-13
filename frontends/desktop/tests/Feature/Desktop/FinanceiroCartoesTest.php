<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroCartoesTest extends TestCase
{
    public function test_index_page_renders_tabs_and_catalogs_from_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes' => Http::response([
                'status' => 'success',
                'data' => [
                    'cartoes' => [
                        'summary' => [
                            'operadoras_total' => 1,
                            'operadoras_ativas' => 1,
                            'bandeiras_total' => 1,
                            'bandeiras_ativas' => 1,
                            'taxas_total' => 1,
                            'taxas_ativas' => 1,
                        ],
                        'operadoras' => [
                            [
                                'id' => 1,
                                'nome' => 'Mercado Pago',
                                'descricao' => 'Operadora padrão',
                                'ordem_exibicao' => 0,
                                'prazo_padrao_dias' => 30,
                                'ativo' => true,
                                'taxas_count' => 1,
                            ],
                        ],
                        'bandeiras' => [
                            [
                                'id' => 1,
                                'nome' => 'Visa',
                                'ordem_exibicao' => 0,
                                'ativo' => true,
                            ],
                        ],
                        'taxas' => [
                            [
                                'id' => 1,
                                'operadora_id' => 1,
                                'operadora_nome' => 'Mercado Pago',
                                'bandeira_id' => 1,
                                'bandeira_nome' => 'Visa',
                                'modalidade' => 'credito',
                                'parcelas_inicial' => 1,
                                'parcelas_final' => 1,
                                'taxa_percentual' => 3.19,
                                'taxa_fixa' => 0,
                                'prazo_recebimento_dias' => 30,
                                'observacoes' => 'Base inicial',
                                'ativo' => true,
                            ],
                        ],
                        'simulador_catalogo' => [
                            'operadoras' => [
                                ['id' => 1, 'nome' => 'Mercado Pago', 'prazo_padrao_dias' => 30],
                            ],
                            'bandeiras' => [
                                ['id' => 1, 'nome' => 'Visa'],
                            ],
                            'taxas' => [],
                        ],
                    ],
                    'gateway' => [
                        'gateway_catalog' => [
                            'asaas' => [
                                'label' => 'Asaas',
                                'modes' => [
                                    ['code' => 'pix', 'label' => 'PIX'],
                                    ['code' => 'credit_card', 'label' => 'Cartão de crédito'],
                                ],
                            ],
                        ],
                        'gateway_taxas' => [],
                        'gateway_summary' => [
                            'ativas' => 1,
                            'total' => 1,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar', 'editar', 'excluir']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/financeiro/cartoes?tab=gateway');

        $response
            ->assertOk()
            ->assertSee('Cartões e Taxas')
            ->assertSee('Operadora de maquininha')
            ->assertSee('Taxa por parcela')
            ->assertSee('Taxas online')
            ->assertSee('data-select2-placeholder="Selecione a operadora..."', false)
            ->assertSee('data-select2-placeholder="Selecione o gateway..."', false)
            ->assertSee('id="cartaoTaxaModal"', false)
            ->assertSee('data-cartoes-new="taxa"', false)
            ->assertSee('id="cartaoGatewayModal"', false)
            ->assertSee('data-cartoes-new="gateway"', false)
            ->assertSee('Taxas cadastradas')
            ->assertSee('Taxas online cadastradas');

        $html = $response->getContent();
        $taxasPanelStart = strpos($html, 'data-cartoes-panel="taxas"');
        $simuladorPanelStart = strpos($html, 'data-cartoes-panel="simulador"');
        $taxasPanelHtml = substr($html, $taxasPanelStart, $simuladorPanelStart - $taxasPanelStart);

        // Os forms de taxa e taxa online foram movidos para dentro dos
        // modais (@push('modals'), renderizados no fim do body) — não devem
        // mais existir inline dentro dos painéis das abas "taxas"/"gateway".
        $this->assertStringNotContainsString('data-cartoes-form="taxa"', $taxasPanelHtml);

        $gatewayPanelStart = strpos($html, 'data-cartoes-panel="gateway"');
        $gatewayModalStart = strpos($html, 'id="cartaoGatewayModal"');
        $gatewayPanelHtml = substr($html, $gatewayPanelStart, $gatewayModalStart - $gatewayPanelStart);

        $this->assertStringNotContainsString('data-cartoes-form="gateway"', $gatewayPanelHtml);
    }

    public function test_help_page_renders_guidance_and_back_link(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes' => Http::response([
                'status' => 'success',
                'data' => [
                    'cartoes' => [
                        'summary' => [],
                        'operadoras' => [],
                        'bandeiras' => [],
                        'taxas' => [],
                        'simulador_catalogo' => [],
                    ],
                    'gateway' => [
                        'gateway_catalog' => [],
                        'gateway_taxas' => [],
                        'gateway_summary' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/financeiro/cartoes/ajuda');

        $response
            ->assertOk()
            ->assertSee('Cartões e Taxas')
            ->assertSee('Referência operacional rápida')
            ->assertSee(route('financeiro.cartoes.index'), false);
    }

    public function test_simulator_route_returns_backend_contract(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/cartoes/simular' => Http::response([
                'status' => 'success',
                'data' => [
                    'simulation' => [
                        'ok' => true,
                        'valor_bruto' => 130.0,
                        'valor_taxa' => 5.2,
                        'valor_liquido' => 124.8,
                        'taxa_percentual' => 3.0,
                        'taxa_fixa' => 1.3,
                        'parcelas' => 1,
                        'modalidade' => 'credito',
                        'modalidade_label' => 'Cartão de crédito',
                        'prazo_recebimento_dias' => 30,
                        'data_prevista_recebimento' => now()->addDays(30)->toDateString(),
                        'data_prevista_repasse' => now()->addDays(30)->toDateString(),
                        'operadora_nome' => 'Mercado Pago',
                        'bandeira_nome' => 'Visa',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['financeiro' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->postJson('/financeiro/cartoes/simular', [
                'valor_bruto' => 130.0,
                'operadora_id' => 1,
                'bandeira_id' => 1,
                'modalidade' => 'credito',
                'parcelas' => 1,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('simulation.operadora_nome', 'Mercado Pago')
            ->assertJsonPath('simulation.modalidade_label', 'Cartão de crédito');
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
