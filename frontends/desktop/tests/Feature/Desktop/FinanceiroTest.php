<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_page_renders_avulso_control(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => []],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar']]))
            ->get('/financeiro/novo');

        $response->assertOk()
            ->assertSee('Lançamento avulso')
            ->assertSee('financeiroAvulso', false);
    }

    public function test_index_page_renders_lancamentos_from_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [
                        [
                            'id' => 1,
                            'tipo' => 'receber',
                            'categoria' => 'Serviço',
                            'valor' => 150.0,
                            'status' => 'pendente',
                            'data_vencimento' => now()->addDays(5)->toDateString(),
                            'grupo_dre' => 'Receita Operacional',
                            'subgrupo_dre' => 'Serviços e peças de OS',
                        ],
                    ],
                    'status_options' => [
                        ['value' => 'pendente', 'label' => 'Pendente'],
                        ['value' => 'pago', 'label' => 'Pago'],
                    ],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee('Financeiro')
            ->assertSee('Serviço')
            ->assertSee('Novo lançamento')
            ->assertSee('Relatórios')
            ->assertSee(route('financeiro.relatorios.fluxo-caixa'), false)
            ->assertSee(route('financeiro.relatorios.dre'), false)
            ->assertSee(route('financeiro.relatorios.dre-caixa'), false)
            ->assertSee(route('financeiro.relatorios.margem'), false)
            ->assertSee('Mais ações')
            ->assertSee(route('financeiro.cartoes.index'), false)
            ->assertSee(route('financeiro.configuracoes'), false)
            // Sessão sem permissão de "precificacao" — item some do dropdown.
            ->assertDontSee(route('financeiro.precificacao.index'), false);
    }

    public function test_index_page_shows_precificacao_in_mais_acoes_when_permitted(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [],
                    'status_options' => [],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 0, 'last_page' => 1, 'from' => 0, 'to' => 0]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar'],
                'precificacao' => ['visualizar'],
            ]))
            ->get('/financeiro');

        $response->assertOk()
            ->assertSee(route('financeiro.precificacao.index'), false);
    }

    public function test_show_page_groups_actions_in_mais_acoes_dropdown(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo' => Http::response([
                'status' => 'success',
                'data' => ['categorias' => [], 'cartao' => ['operadoras' => [], 'bandeiras' => [], 'taxas' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro/63' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 63,
                        'tipo' => 'receber',
                        'categoria' => 'Serviço',
                        'descricao' => 'Cobrança da OS OS26070014',
                        'valor' => 80.0,
                        'status' => 'pendente',
                        'data_vencimento' => '2026-07-14',
                        'avulso' => false,
                    ],
                    'resumo' => ['valor_movimentado' => 0, 'valor_aberto' => 80.0, 'percentual_quitado' => 0, 'total_movimentos' => 0],
                    'detalhes' => [
                        'contraparte' => ['tipo' => 'cliente', 'id' => 396, 'titulo' => 'Quem pagou', 'nome' => 'Deborah Evelyn Rosa'],
                        'origem' => ['titulo' => 'Ordem de serviço', 'descricao' => 'Lançamento vinculado ao fluxo financeiro de uma OS.'],
                        'os' => [
                            'id' => 3626,
                            'numero_os' => 'OS26070014',
                            'status' => 'entregue_reparado',
                            'status_nome' => 'Equipamento Entregue',
                            'datas' => [],
                            'valores' => [],
                            'cliente' => ['id' => 396, 'nome' => 'Deborah Evelyn Rosa'],
                            'equipamento' => [],
                            'defeito' => [],
                            'orcamento' => ['id' => 8, 'numero' => 'ORC-2607-000008', 'status' => 'aprovado'],
                        ],
                        'movimentos' => [],
                        'impactos' => [],
                        'auditoria' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
                'os' => ['visualizar'],
                'orcamentos' => ['visualizar'],
                'clientes' => ['visualizar'],
            ]))
            ->get('/financeiro/63');

        $response->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Ver lançamentos')
            ->assertSee(route('financeiro.index'), false)
            ->assertSee('Novo lançamento')
            ->assertSee(route('financeiro.create'), false)
            ->assertSee('Editar lançamento')
            ->assertSee('Registrar baixa')
            ->assertSee('Ver OS vinculada')
            ->assertSee(route('orders.show', 3626), false)
            ->assertSee('Ver orçamento vinculado')
            ->assertSee(route('orcamentos.show', 8), false)
            ->assertSee('Ver cliente')
            ->assertSee(route('clients.show', 396), false)
            ->assertSee('Cancelar lançamento')
            ->assertSee('Excluir lançamento')
            ->assertSee('payModal63', false)
            ->assertSee('voltar_para', false);
    }

    public function test_show_page_hides_linked_actions_without_permissions(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/64' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamento' => [
                        'id' => 64,
                        'tipo' => 'receber',
                        'categoria' => 'Serviço',
                        'descricao' => 'Lançamento pago',
                        'valor' => 50.0,
                        'status' => 'pago',
                        'avulso' => true,
                    ],
                    'resumo' => ['valor_movimentado' => 50.0, 'valor_aberto' => 0, 'percentual_quitado' => 100, 'total_movimentos' => 1],
                    'detalhes' => [
                        'contraparte' => ['tipo' => 'cliente', 'id' => 396, 'nome' => 'Deborah'],
                        'origem' => [],
                        'os' => null,
                        'movimentos' => [],
                        'impactos' => [],
                        'auditoria' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/64');

        $response->assertOk()
            // "Mais ações" sempre aparece (com "Ver lançamentos", que só exige
            // financeiro,visualizar). Sem permissão de criar/editar/excluir e
            // sem OS/orçamento/cliente vinculado, nenhuma outra ação aparece.
            ->assertSee('Mais ações')
            ->assertSee('Ver lançamentos')
            ->assertDontSee('Novo lançamento')
            ->assertDontSee('Editar lançamento')
            ->assertDontSee('Registrar baixa')
            ->assertDontSee('Ver OS vinculada')
            ->assertDontSee('Ver orçamento vinculado')
            ->assertDontSee('Ver cliente')
            ->assertDontSee('Cancelar lançamento')
            ->assertDontSee('Excluir lançamento');
    }

    public function test_store_redirects_to_index_on_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro' => Http::response([
                'status' => 'success',
                'data' => ['lancamento' => ['id' => 5, 'tipo' => 'receber', 'status' => 'pendente']],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar', 'criar', 'editar', 'excluir']]))
            ->post('/financeiro', [
                'tipo' => 'receber',
                'categoria' => 'Serviço',
                'descricao' => 'Serviço de teste',
                'cliente_id' => 1,
                'avulso' => '1',
                'valor' => 150.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro'
                && $request->method() === 'POST'
                && $request['avulso'] === true;
        });
    }

    public function test_client_detail_shows_financeiro_history_with_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/396' => Http::response([
                'status' => 'success',
                'data' => ['client' => ['id' => 396, 'nome_razao' => 'Cliente Financeiro']],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/financeiro*' => Http::response([
                'status' => 'success',
                'data' => [
                    'lancamentos' => [[
                        'id' => 81,
                        'tipo' => 'receber',
                        'categoria' => 'Receita avulsa',
                        'descricao' => 'Configuração simples por WhatsApp',
                        'cliente_id' => 396,
                        'avulso' => true,
                        'valor' => 80,
                        'status' => 'pago',
                        'data_vencimento' => '2026-07-05',
                    ]],
                    'status_options' => [],
                ],
                'error' => null,
                'meta' => ['pagination' => ['total' => 1]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
                'financeiro' => ['visualizar'],
            ]))
            ->get('/clientes/396');

        $response->assertOk()
            ->assertSee('Financeiro do cliente')
            ->assertSee('Configuração simples por WhatsApp')
            ->assertSee(route('financeiro.index', ['cliente_id' => 396, 'tipo' => 'receber']));

        Http::assertSent(static function ($request): bool {
            if (! str_starts_with($request->url(), 'http://127.0.0.1:8000/api/v1/financeiro?')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['cliente_id'] ?? null) === '396'
                && ($query['tipo'] ?? null) === 'receber';
        });
    }

    public function test_client_detail_hides_financeiro_history_without_permission(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/396' => Http::response([
                'status' => 'success',
                'data' => ['client' => ['id' => 396, 'nome_razao' => 'Cliente sem acesso financeiro']],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['clientes' => ['visualizar']]))
            ->get('/clientes/396');

        $response->assertOk()
            ->assertDontSee('Financeiro do cliente');

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), '/api/v1/financeiro'));
    }

    public function test_user_without_module_permission_is_redirected(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/financeiro');

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
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
