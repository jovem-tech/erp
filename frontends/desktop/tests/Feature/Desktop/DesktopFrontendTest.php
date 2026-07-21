<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class DesktopFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_stores_backend_token_in_server_session(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/login' => Http::response([
                'status' => 'success',
                'data' => [
                    'access_token' => 'token-123',
                    'user' => $this->fakeUser([
                        'permissions' => [
                            'dashboard' => ['visualizar'],
                        ],
                    ]),
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this->post('/login', [
            'email' => 'ana@empresa.com',
            'password' => 'Senha@123',
        ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('desktop_auth.token', 'token-123')
            ->assertSessionHas('desktop_auth.user.nome', 'Usuário de Teste');
    }

    public function test_login_rate_limit_message_is_forwarded_back_to_the_form(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/login' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'AUTH_LOGIN_RATE_LIMITED',
                    'message' => 'Muitas tentativas de login. Aguarde um pouco e tente novamente.',
                    'details' => [
                        'retry_after' => 60,
                    ],
                ],
                'meta' => [],
            ], 429),
        ]);

        $response = $this
            ->from('/login')
            ->post('/login', [
                'email' => 'ana@empresa.com',
                'password' => 'Senha@123',
            ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Muitas tentativas de login. Aguarde um pouco e tente novamente.')
            ->assertSessionHasInput('email', 'ana@empresa.com');
    }

    public function test_login_page_exposes_password_reset_link(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSee('Esqueci minha senha')
            ->assertSee(route('password.request'), false);
    }

    public function test_password_reset_request_redirects_back_to_login_with_success_message(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/password/forgot' => Http::response([
                'status' => 'success',
                'data' => [
                    'reset_link_sent' => true,
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this->post('/esqueci-minha-senha', [
            'email' => 'suporte@empresa.com',
        ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');
    }

    public function test_password_reset_request_redirects_back_with_error_when_mail_channel_is_unavailable(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/password/forgot' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'AUTH_PASSWORD_RESET_CHANNEL_UNAVAILABLE',
                    'message' => 'A recuperacao de senha por e-mail esta temporariamente indisponivel. Contate o administrador.',
                ],
                'meta' => [],
            ], 503),
        ]);

        $response = $this
            ->from('/esqueci-minha-senha')
            ->post('/esqueci-minha-senha', [
                'email' => 'suporte@empresa.com',
            ]);

        $response
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('error', 'A recuperacao de senha por e-mail esta temporariamente indisponivel. Contate o administrador.')
            ->assertSessionHasInput('email', 'suporte@empresa.com');
    }

    public function test_password_reset_page_renders_from_email_link(): void
    {
        $response = $this->get('/redefinir-senha/token-abc?email=suporte@empresa.com');

        $response
            ->assertOk()
            ->assertSee('Nova senha')
            ->assertSee('suporte@empresa.com');
    }

    public function test_password_reset_submission_redirects_to_login(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/password/reset' => Http::response([
                'status' => 'success',
                'data' => [
                    'password_reset' => true,
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this->post('/redefinir-senha', [
            'token' => 'token-abc',
            'email' => 'suporte@empresa.com',
            'password' => 'NovaSenha@123',
            'password_confirmation' => 'NovaSenha@123',
        ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('success');
    }

    public function test_dashboard_redirects_to_login_when_session_has_no_token(): void
    {
        $response = $this->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('desktop_auth');
    }

    public function test_dashboard_recovers_permissions_from_backend_when_session_snapshot_is_incomplete(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                    ],
                    'modules' => ['dashboard'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession([
                'desktop_auth' => [
                    'token' => 'desktop-session-token',
                    'synced_at' => time(),
                    'user' => $this->fakeUser(),
                ],
            ])
            ->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('window.__DESKTOP_DASHBOARD = {"dataUrl":"', false)
            ->assertSessionHas('desktop_auth.user.permissions.dashboard.0', 'visualizar');

        Http::assertSentCount(1);
    }

    public function test_dashboard_index_renders_shell_before_summary_hydration(): void
    {
        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/dashboard?ano=2026&equip_mes=1&equip_ano=2026');

        $response
            ->assertOk()
            ->assertSee('window.__DESKTOP_DASHBOARD = {"dataUrl":"', false)
            ->assertSee(route('dashboard.data'), false)
            ->assertSee('Janeiro')
            ->assertSee('dashboardYear')
            ->assertSee('dashboardEquipmentMonth')
            ->assertSee('dashboardEquipmentYear');

        Http::assertNothingSent();
    }

    public function test_sidebar_groups_registries_and_moves_team_to_administration(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
            'http://127.0.0.1:8000/api/v1/suppliers*' => Http::response([
                'status' => 'success',
                'data' => [
                    'suppliers' => [],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 20,
                        'total' => 0,
                        'last_page' => 1,
                        'from' => 0,
                        'to' => 0,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
                'fornecedores' => ['visualizar'],
                'funcionarios' => ['visualizar'],
                'financeiro' => ['visualizar'],
                'contas_saldos' => ['visualizar'],
                'precificacao' => ['visualizar'],
                'conhecimento' => ['visualizar'],
                'usuarios' => ['visualizar'],
                'grupos' => ['visualizar'],
                'configuracoes' => ['visualizar'],
            ]))
            ->get('/fornecedores');

        // Clientes/Fornecedores agora vivem em "Cadastros" e a equipe interna
        // saiu do antigo grupo "Comercial" para "Administração".
        $response
            ->assertOk()
            ->assertSee('Cadastros')
            ->assertSee('Clientes')
            ->assertSee('Fornecedores')
            ->assertSee('Administração')
            ->assertSee('Equipe da Assistência')
            // Novas seções e grupos de atalho (submenu para sub-páginas mais usadas).
            ->assertSee('Processos e Modelos')
            ->assertSee('Relatórios')
            ->assertSee('Fluxo de Caixa')
            ->assertSee('Ferramentas')
            ->assertSee('Precificação')
            ->assertSee('Acesso e Integrações')
            ->assertSee('Grupos e Permissões')
            ->assertSee('Integrações');
    }

    public function test_permission_middleware_redirects_to_first_allowed_route(): void
    {
        $response = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
            ]))
            ->get('/usuarios');

        $response
            ->assertRedirect(route('clients.index'))
            ->assertSessionHas('error');
    }

    public function test_unauthorized_backend_response_clears_session_and_redirects_to_login(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/me')) {
                return Http::response([
                    'status' => 'error',
                    'data' => null,
                    'error' => [
                        'message' => 'Token expirado.',
                    ],
                    'meta' => [],
                ], 401);
            }

            if (str_contains($request->url(), '/auth/refresh')) {
                return Http::response([
                    'status' => 'error',
                    'data' => null,
                    'error' => [
                        'message' => 'Sessão inválida.',
                    ],
                    'meta' => [],
                ], 403);
            }

            throw new \RuntimeException('Unexpected HTTP request: '.$request->url());
        });

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ], syncedAt: 0))
            ->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('desktop_auth')
            ->assertSessionMissing('warning');
    }

    public function test_profile_sync_server_error_uses_existing_authorization_snapshot_without_login_loop(): void
    {
        Log::spy();
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'error',
                'error' => ['message' => 'Servico temporariamente indisponivel.'],
            ], 500),
        ]);

        $response = $this
            ->from('/login')
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ], syncedAt: 0))
            ->get('/dashboard');

        $response
            ->assertOk()
            ->assertSessionHas('desktop_auth.token', 'desktop-session-token')
            ->assertSessionHas('desktop_auth.user.permissions.dashboard.0', 'visualizar');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => $message === 'desktop_profile_sync_unavailable'
                && $context['status_code'] === 500
                && $context['has_authorization_snapshot'] === true);
    }

    public function test_profile_sync_server_error_without_snapshot_clears_session_and_returns_login(): void
    {
        Log::spy();
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'error',
                'error' => ['message' => 'Servico temporariamente indisponivel.'],
            ], 500),
        ]);

        $response = $this
            ->withSession([
                'desktop_auth' => [
                    'token' => 'desktop-session-token',
                    'synced_at' => 0,
                    'user' => $this->fakeUser(),
                ],
            ])
            ->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('desktop_auth')
            ->assertSessionHas('error', 'Nao foi possivel validar sua sessao. Tente entrar novamente em instantes.');
    }

    public function test_dashboard_data_redirects_to_login_when_session_is_stale(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/auth/me')) {
                return Http::response([
                    'status' => 'error',
                    'data' => null,
                    'error' => [
                        'message' => 'Token expirado.',
                    ],
                    'meta' => [],
                ], 401);
            }

            if (str_contains($request->url(), '/auth/refresh')) {
                return Http::response([
                    'status' => 'error',
                    'data' => null,
                    'error' => [
                        'message' => 'Sessão inválida.',
                    ],
                    'meta' => [],
                ], 403);
            }

            throw new \RuntimeException('Unexpected HTTP request: '.$request->url());
        });

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ], syncedAt: 0))
            ->get('/dashboard/dados?ano=2026&equip_mes=1&equip_ano=2026');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('desktop_auth');

        Http::assertSentCount(2);
    }

    public function test_forbidden_backend_response_returns_to_dashboard_with_message(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'message' => 'Acesso negado à listagem de OS.',
                ],
                'meta' => [],
            ], 403),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
            ]))
            ->get('/os');

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'Acesso negado à listagem de OS.');
    }

    public function test_orders_index_starts_with_hidden_sidebar_for_workspace_wide_table(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/status-catalog' => Http::response([
                'status' => 'success',
                'data' => [
                    'statuses' => [
                        [
                            'codigo' => 'triagem',
                            'nome' => 'Triagem',
                            'grupo_macro' => 'recepcao',
                            'cor' => '#64748b',
                            'icone' => '',
                            'ordem_fluxo' => 10,
                            'status_final' => false,
                            'status_pausa' => false,
                            'estado_fluxo_padrao' => 'em_atendimento',
                        ],
                        [
                            'codigo' => 'aguardando_reparo',
                            'nome' => 'Aguardando Reparo',
                            'grupo_macro' => 'execucao',
                            'cor' => '#16a34a',
                            'icone' => 'bi-tools',
                            'ordem_fluxo' => 40,
                            'status_final' => false,
                            'status_pausa' => false,
                            'estado_fluxo_padrao' => 'em_execucao',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 3578,
                            'numero_os' => 'OS26060006',
                            'numero_os_legado' => '',
                            'cliente_nome' => 'Cliente Exemplo',
                            'cliente_telefone' => '11999998888',
                            'equipamento_id' => 204,
                            'equipamento_resumo_tecnico' => 'Notebook Dell Inspiron 15 com specs longas',
                            'equipamento_resumo_curto' => 'Notebook Dell Inspiron 15',
                            'equipamento_numero_serie' => 'ABC123',
                            'equipamento_foto_id' => 5,
                            'equipamento_foto_url' => '/api/v1/equipments/204/photos/5',
                            'status' => 'triagem',
                            'status_nome' => 'Triagem',
                            'status_cor' => '#64748b',
                            'prioridade' => 'normal',
                            'data_abertura' => '2026-06-20T08:00:00-03:00',
                            'data_entrada' => '2026-06-20T08:00:00-03:00',
                            'data_previsao' => '2026-06-18',
                            'data_conclusao' => null,
                            'data_entrega' => null,
                            'prazo' => ['estado' => 'atrasado', 'label' => 'Atrasada', 'dias' => 2],
                            'orcamento' => null,
                            'proximas_etapas' => [
                                [
                                    'codigo' => 'aguardando_reparo',
                                    'nome' => 'Aguardando Reparo',
                                    'grupo_macro' => 'execucao',
                                    'cor' => '#16a34a',
                                    'icone' => 'bi-tools',
                                    'ordem_fluxo' => 40,
                                    'status_final' => false,
                                    'status_pausa' => false,
                                    'estado_fluxo_padrao' => 'em_execucao',
                                    'ativo' => true,
                                ],
                            ],
                            'valor_final' => '150.00',
                            'valor_recebido' => null,
                            'saldo' => null,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1, 'total' => 1]],
            ]),
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => ['users' => []],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/knowledge/os-flow*' => Http::response([
                'status' => 'success',
                'data' => ['statuses' => [], 'transitions' => []],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'editar'],
                'orcamentos' => ['visualizar', 'criar'],
            ]))
            ->get('/os');

        $response
            ->assertOk()
            ->assertSee('Filtros avançados')
            ->assertSee('desktop-sidebar is-hidden', false)
            ->assertSee('desktop-main is-full', false)
            ->assertSee('OS26060006')
            ->assertSee('Notebook Dell Inspiron 15')
            ->assertSee('Gerar orçamento')
            ->assertSee('Alterar status')
            ->assertSee('Documentos da OS')
            ->assertSee('Mapa da OS')
            ->assertSee('Aguardando Reparo')
            ->assertSee('<select id="status" name="status"', false)
            ->assertSee('<option value="triagem"', false)
            ->assertSee('<select id="grupo_macro" name="grupo_macro"', false)
            ->assertSee('<option value="recepcao"', false)
            ->assertSeeInOrder([
                '<label for="status">Status</label>',
                '<label for="grupo_macro">Macrofase</label>',
                '<label for="per_page">Itens por página</label>',
                '<button type="button" class="btn btn-sm btn-outline-light" data-bs-toggle="collapse" data-bs-target="#osAdvancedFilters"',
                '<label for="technician_id">Técnico</label>',
            ], false)
            ->assertSee(route('orcamentos.create', ['os_id' => 3578]), false)
            ->assertSee(route('orders.documents.center', 3578), false)
            ->assertSee(route('orders.map', 3578), false)
            ->assertSee(route('orders.status.update', 3578), false);

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/v1/orders')
                && str_contains($request->url(), 'status_scope=open');
        });
    }

    public function test_orders_index_shows_financeiro_link_in_row_actions_when_linked_and_permitted(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), $this->ordersIndexFixture(financeiroTituloId: 63), [
            'http://127.0.0.1:8000/api/v1/users*' => Http::response(['status' => 'success', 'data' => ['users' => []], 'error' => null, 'meta' => []]),
            'http://127.0.0.1:8000/api/v1/knowledge/os-flow*' => Http::response(['status' => 'success', 'data' => ['statuses' => [], 'transitions' => []], 'error' => null, 'meta' => []]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'editar'],
                    'financeiro' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os');

        $response
            ->assertOk()
            ->assertSee('Auditoria completa')
            ->assertSee(route('orders.audit', 501), false)
            ->assertSee('Ver lançamento financeiro')
            ->assertSee(route('financeiro.show', 63), false);
    }

    public function test_orders_index_hides_financeiro_link_in_row_actions_without_permission(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), $this->ordersIndexFixture(financeiroTituloId: 63), [
            'http://127.0.0.1:8000/api/v1/users*' => Http::response(['status' => 'success', 'data' => ['users' => []], 'error' => null, 'meta' => []]),
            'http://127.0.0.1:8000/api/v1/knowledge/os-flow*' => Http::response(['status' => 'success', 'data' => ['statuses' => [], 'transitions' => []], 'error' => null, 'meta' => []]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os');

        $response
            ->assertOk()
            ->assertDontSee('Ver lançamento financeiro');
    }

    /**
     * @return array<string, mixed>
     */
    private function ordersIndexFixture(?int $financeiroTituloId): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/orders/status-catalog' => Http::response([
                'status' => 'success',
                'data' => ['statuses' => []],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 3614,
                            'numero_os' => 'OS26070002',
                            'numero_os_legado' => '',
                            'cliente_nome' => 'Heliandro Alves Rodrigues',
                            'cliente_telefone' => '21979027772',
                            'equipamento_id' => 204,
                            'equipamento_resumo_tecnico' => 'Tablet Generico Generico',
                            'equipamento_resumo_curto' => 'Tablet Generico Generico',
                            'equipamento_numero_serie' => '',
                            'equipamento_foto_id' => 0,
                            'equipamento_foto_url' => null,
                            'status' => 'aguardando_reparo',
                            'status_nome' => 'Aguardando Reparo',
                            'status_cor' => '#16a34a',
                            'prioridade' => 'normal',
                            'data_abertura' => '2026-07-03T08:00:00-03:00',
                            'data_entrada' => '2026-07-03T08:00:00-03:00',
                            'data_previsao' => '2026-07-10',
                            'data_conclusao' => null,
                            'data_entrega' => null,
                            'prazo' => ['estado' => 'concluido_no_prazo', 'label' => 'Concluída no prazo', 'dias' => 0],
                            'orcamento' => ['id' => 27, 'numero' => 'ORC-2607-000008', 'status' => 'aprovado', 'status_label' => 'Aprovado', 'status_color' => '#16a34a'],
                            'proximas_etapas' => [],
                            'valor_final' => '200.00',
                            'valor_recebido' => '80.00',
                            'saldo' => '120.00',
                            'financeiro_titulo_id' => $financeiroTituloId,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1, 'total' => 1]],
            ]),
        ];
    }

    public function test_order_documents_center_page_renders_generation_sending_and_secure_share_actions(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/documents' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'cliente_nome' => 'Cliente Alpha',
                        'equipamento_resumo_curto' => 'Acer / Nitro 5',
                    ],
                    'catalog' => [
                        [
                            'type' => 'abertura',
                            'label' => 'Comprovante de abertura',
                            'can_generate' => true,
                            'automatic_triggers' => ['criacao_os'],
                            'latest_document' => [
                                'id' => 9001,
                                'version' => 1,
                                'archived_at' => null,
                                'files' => [
                                    'a4' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/a4'],
                                    '80mm' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/80mm'],
                                ],
                            ],
                            'versions' => [
                                [
                                    'id' => 9001,
                                    'version' => 1,
                                    'created_at' => '2026-07-11T15:00:00-03:00',
                                    'archived_at' => null,
                                    'files' => [
                                        'a4' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/a4'],
                                        '80mm' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/80mm'],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'laudo',
                            'label' => 'Laudo tÃ©cnico',
                            'can_generate' => false,
                            'blocked_reason' => 'DiagnÃ³stico tÃ©cnico pendente.',
                            'automatic_triggers' => ['status_tecnico'],
                            'latest_document' => null,
                            'versions' => [],
                        ],
                    ],
                    'documents' => [
                        [
                            'id' => 9001,
                            'type' => 'abertura',
                            'label' => 'Comprovante de abertura',
                            'version' => 1,
                            'created_at' => '2026-07-11T15:00:00-03:00',
                            'generated_by' => [
                                'name' => 'Administrador',
                            ],
                            'archived_at' => null,
                            'files' => [
                                'a4' => [
                                    'available' => true,
                                    'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/a4',
                                ],
                                '80mm' => [
                                    'available' => true,
                                    'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/80mm',
                                ],
                            ],
                        ],
                    ],
                    'send_history' => [
                        [
                            'channel' => 'whatsapp',
                            'destination_masked' => '(21) *****-4100',
                            'status' => 'enviado',
                            'template_code' => 'os_aberta',
                            'sender' => [
                                'name' => 'Administrador',
                            ],
                        ],
                    ],
                    'share_links' => [
                        [
                            'id' => 44,
                            'format' => 'a4',
                            'expires_at' => '2026-07-18T15:00:00-03:00',
                            'revoked_at' => null,
                            'access_count' => 2,
                        ],
                    ],
                    'limits' => [
                        'max_attachments' => 10,
                        'max_total_bytes' => 20971520,
                        'share_expirations' => ['24h', '7d', '30d'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar', 'editar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/documentos');

        $response
            ->assertOk()
            ->assertSee('Central documental da OS')
            ->assertSee('Comprovante de abertura')
            ->assertSee('Laudo tÃ©cnico')
            ->assertSee('Gerar link público')
            ->assertSee('Enviar para cliente')
            // O acervo/versões vive só no dropdown por linha do catálogo agora
            // — não há mais uma tabela/barra de ações em lote separada.
            ->assertDontSee('doc-action-bar', false)
            ->assertSee('data-doc-version-select', false)
            ->assertSee('docSendModal', false)
            ->assertSee('docShareModal', false)
            ->assertSee('window.__ORDER_DOCUMENTS_CENTER', false)
            ->assertSee('assets/js/orders-documents-center.js', false)
            // As rotas dentro de window.__ORDER_DOCUMENTS_CENTER passam por
            // Illuminate\Support\Js::from() (escapa "/" como "\/") e depois
            // pelo JSON.parse('...') externo (escapa cada "\" de novo) — o
            // HTML cru contem 3 barras invertidas antes de cada "/" original.
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.state', 501)), false)
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.generate', 501)), false)
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.send', 501)), false)
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.share', 501)), false)
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.print', 501)), false)
            ->assertSee(str_replace('/', str_repeat('\\', 3).'/', route('orders.documents.download', 501)), false);

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'http://127.0.0.1:8000/api/v1/orders/501/documents';
        });
    }

    public function test_order_documents_center_catalog_row_gains_per_document_actions_when_generated(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/documents' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'cliente_nome' => 'Cliente Alpha',
                        'equipamento_resumo_curto' => 'Acer / Nitro 5',
                    ],
                    'catalog' => [
                        [
                            'type' => 'abertura',
                            'label' => 'Comprovante de abertura',
                            'can_generate' => true,
                            'automatic_triggers' => ['criacao_os'],
                            'latest_document' => [
                                'id' => 9001,
                                'version' => 1,
                                'archived_at' => null,
                                'files' => [
                                    'a4' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/a4'],
                                    '80mm' => ['available' => false, 'url' => null],
                                ],
                            ],
                            'versions' => [
                                [
                                    'id' => 9001,
                                    'version' => 1,
                                    'created_at' => '2026-07-11T15:00:00-03:00',
                                    'archived_at' => null,
                                    'files' => [
                                        'a4' => ['available' => true, 'url' => 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/files/a4'],
                                        '80mm' => ['available' => false, 'url' => null],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'laudo',
                            'label' => 'Laudo técnico',
                            'can_generate' => false,
                            'blocked_reason' => 'Diagnóstico técnico pendente.',
                            'automatic_triggers' => ['status_tecnico'],
                            'latest_document' => null,
                            'versions' => [],
                        ],
                    ],
                    'documents' => [],
                    'send_history' => [],
                    'share_links' => [],
                    'limits' => [
                        'max_attachments' => 10,
                        'max_total_bytes' => 20971520,
                        'share_expirations' => ['24h', '7d', '30d'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar', 'editar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/documentos');

        $response
            ->assertOk()
            // Documento já gerado: ganha o dropdown de ações por linha.
            ->assertSee('Gerar nova versão')
            ->assertSee('Visualizar A4')
            ->assertSee('>Foto</th>', false)
            // 80mm indisponível nesta versão: o item continua no DOM (a
            // seleção de versão pode trocar para uma que tenha 80mm), mas
            // escondido via d-none — não mais omitido do HTML.
            ->assertSee('class="dropdown-item d-none"', false)
            ->assertSee(route('orders.documents.files.show', ['order' => 501, 'document' => 9001, 'format' => 'a4']), false)
            ->assertSee(route('orders.documents.thumbnail', ['order' => 501, 'document' => 9001]), false)
            ->assertSee('data-doc-thumbnail-image', false)
            ->assertSee('data-thumbnail-url=', false)
            ->assertSee('data-file-preview-trigger', false)
            ->assertSee('data-preview-kind="pdf"', false)
            ->assertSee('data-preview-url="' . route('orders.documents.files.show', ['order' => 501, 'document' => 9001, 'format' => 'a4']) . '"', false)
            ->assertSee('data-preview-file-name="Comprovante de abertura - v1.pdf"', false)
            ->assertSee('data-file-preview-modal', false)
            ->assertSee('assets/css/file-preview-modal.css', false)
            ->assertSee('assets/js/file-preview-modal.js', false)
            ->assertSee('data-doc-row-zip="9001"', false)
            ->assertSee('data-doc-row-print="9001"', false)
            ->assertSee('data-doc-row-share="9001"', false)
            ->assertSee('data-doc-row-send="9001"', false)
            ->assertSee('data-doc-archive-toggle="9001"', false)
            // A versão vira dropdown na própria linha do catálogo — não há
            // mais uma tabela de acervo separada.
            ->assertSee('data-doc-version-select', false)
            ->assertSee('v1 — 11/07/2026 15:00', false)
            ->assertDontSee('Todas as versões geradas')
            // Tipo ainda não gerado: só o botão de gerar, sem dropdown de ações.
            ->assertSee('Laudo técnico')
            ->assertDontSee('data-doc-row-zip=""', false);
    }

    public function test_order_document_thumbnail_route_proxies_the_private_backend_image(): void
    {
        $thumbnail = UploadedFile::fake()->image('pagina-1.png', 8, 10)->getContent();
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/thumbnail' => Http::response(
                $thumbnail,
                200,
                [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'private, max-age=86400',
                    'ETag' => '"pdf-p1-test"',
                ]
            ),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/os/501/documentos/9001/miniatura');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertContent($thumbnail);

        Http::assertSent(static fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/thumbnail');
    }

    public function test_order_documents_center_state_endpoint_returns_rendered_fragments_and_pending_sends(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/documents' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => ['id' => 501, 'numero_os' => 'OS26070009', 'cliente_nome' => 'Cliente Alpha'],
                    'catalog' => [
                        ['type' => 'abertura', 'label' => 'Comprovante de abertura', 'can_generate' => true, 'latest_document' => null],
                    ],
                    'documents' => [],
                    'send_history' => [
                        ['id' => 1, 'channel' => 'whatsapp', 'destination_masked' => '(21) *****-4100', 'status' => 'na_fila', 'sender' => ['name' => 'Administrador']],
                        ['id' => 2, 'channel' => 'email', 'destination_masked' => 'a***@x.com', 'status' => 'enviado', 'sender' => ['name' => 'Administrador']],
                    ],
                    'share_links' => [],
                    'limits' => ['max_attachments' => 10, 'max_total_bytes' => 20971520, 'share_expirations' => ['24h', '7d', '30d']],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar']]), ['desktop_theme' => 'default']))
            ->getJson('/os/501/documentos/estado');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.pending_sends', 1)
            ->assertJsonPath('meta.documents_count', 0)
            ->assertJsonStructure(['success', 'fragments' => ['catalog', 'sends', 'links'], 'meta' => ['pending_sends', 'documents_count']]);

        $this->assertStringContainsString('Comprovante de abertura', $response->json('fragments.catalog'));
        $this->assertStringContainsString('Na fila', $response->json('fragments.sends'));
        $this->assertStringContainsString('Nenhum link público gerado', $response->json('fragments.links'));
    }

    public function test_order_documents_center_generate_json_validates_missing_tipos_with_friendly_message(): void
    {
        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/gerar', []);

        $response->assertStatus(422);
        $this->assertStringContainsString('tipos documentais', $response->json('errors.tipos.0'));
    }

    public function test_order_documents_center_generate_json_returns_success_results(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/generate' => Http::response([
                'status' => 'success',
                'data' => [
                    'documents' => [
                        ['type' => 'abertura', 'ok' => true, 'message' => 'PDF de abertura gerado com sucesso.'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/gerar', ['tipos' => ['abertura']]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('results.0.type', 'abertura')
            ->assertJsonPath('results.0.ok', true);
    }

    public function test_order_documents_center_send_json_validates_missing_document_ids_with_friendly_message(): void
    {
        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/enviar', ['channel' => 'email']);

        $response->assertStatus(422);
        $this->assertSame(
            'Selecione ao menos um documento do acervo antes de enfileirar o envio.',
            $response->json('errors.document_ids.0')
        );
    }

    public function test_order_documents_center_send_json_returns_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/send' => Http::response([
                'status' => 'success',
                'data' => [
                    'send' => ['id' => 55, 'status' => 'na_fila'],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/enviar', [
                'document_ids' => [9001],
                'channel' => 'email',
                'format' => 'a4',
                'destino' => 'cliente@example.com',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Envio documental enfileirado.')
            ->assertJsonPath('send.id', 55);
    }

    public function test_order_documents_center_share_json_returns_link_url(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/share-links' => Http::response([
                'status' => 'success',
                'data' => [
                    'link' => ['url' => 'https://erp.example.com/documentos/compartilhados/abc123', 'format' => 'a4', 'expires_at' => '2026-07-19T12:00:00-03:00'],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/links', [
                'document_ids' => [9001],
                'format' => 'a4',
                'expiracao' => '24h',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('link.url', 'https://erp.example.com/documentos/compartilhados/abc123');
    }

    public function test_order_documents_center_archive_json_returns_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/9001/archive' => Http::response([
                'status' => 'success',
                'data' => [],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->postJson('/os/501/documentos/9001/arquivar', []);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Documento arquivado com sucesso.');
    }

    public function test_order_documents_center_generate_classic_post_fallback_still_redirects(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501/documents/generate' => Http::response([
                'status' => 'success',
                'data' => [
                    'documents' => [
                        ['type' => 'abertura', 'ok' => true, 'message' => 'PDF de abertura gerado com sucesso.'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession(['os' => ['visualizar', 'editar']]), ['desktop_theme' => 'default']))
            ->post('/os/501/documentos/gerar', ['tipos' => ['abertura']]);

        $response
            ->assertRedirect(route('orders.documents.center', 501))
            ->assertSessionHas('success');
    }

    public function test_orcamentos_create_page_renders_dynamic_item_reference_select_without_select2_exclusion(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                    'logo' => [
                        'exists' => false,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [
                            [
                                'id' => 201,
                                'nome_razao' => 'Cliente Alpha',
                                'cpf_cnpj' => '11.111.111/0001-11',
                                'telefone1' => '(21) 98888-1111',
                            ],
                        ],
                        'equipments' => [
                            [
                                'id' => 301,
                                'tipo_nome' => 'Notebook',
                                'marca_nome' => 'Dell',
                                'modelo_nome' => 'Inspiron 15',
                                'numero_serie' => 'SN-12345',
                                'cliente_nome' => 'Cliente Alpha',
                                'resumo_tecnico' => 'Notebook Dell Inspiron 15',
                            ],
                        ],
                        'orders' => [
                            [
                                'id' => 401,
                                'numero_os' => 'OS401',
                                'cliente_nome' => 'Cliente Alpha',
                            ],
                        ],
                        'services' => [
                            [
                                'id' => 11,
                                'label' => 'Formatação completa',
                                'description' => 'Formatação completa',
                                'price' => 150.0,
                            ],
                        ],
                        'parts' => [
                            [
                                'id' => 21,
                                'label' => 'SSD 480GB',
                                'description' => 'SSD 480GB',
                                'price' => 300.0,
                            ],
                        ],
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                            ['value' => 'pendente_envio', 'label' => 'Pendente de envio'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'orcamentos' => ['visualizar', 'criar'],
                    'clientes' => ['visualizar'],
                    'equipamentos' => ['visualizar'],
                    'os' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            ->assertSee('assets/js/orcamentos-form.js', false)
            ->assertSee('data-budget-item-reference', false)
            ->assertSee('desktop-grid-four', false)
            ->assertSee('budget-summary-card', false)
            ->assertSee('budget-summary-result-pill', false)
            ->assertSee('Fechamento')
            ->assertSee('budget-item-layout', false)
            ->assertSee('R$ 0,00', false)
            ->assertSee('data-budget-subtotal data-budget-money', false)
            ->assertSee('data-budget-global-discount-display', false)
            ->assertSee('data-budget-global-discount-type', false)
            ->assertSee('data-budget-global-discount-percent', false)
            ->assertSee('data-budget-global-addition-display', false)
            ->assertSee('data-budget-global-addition-type', false)
            ->assertSee('data-budget-global-addition-percent', false)
            ->assertSee('data-budget-item-discount-display', false)
            ->assertSee('data-budget-item-discount-type', false)
            ->assertSee('data-budget-item-discount-preview', false)
            ->assertSee('data-budget-item-addition-display', false)
            ->assertSee('data-budget-item-addition-type', false)
            ->assertSee('data-budget-item-addition-preview', false)
            ->assertSee('data-budget-global-discount-preview', false)
            ->assertSee('data-budget-global-addition-preview', false)
            ->assertSee('data-budget-total data-budget-money', false)
            ->assertDontSee('data-select2="false"', false);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_create_page_renders_review_modal_for_save_decision(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                    'logo' => [
                        'exists' => false,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [],
                        'equipments' => [],
                        'orders' => [],
                        'services' => [],
                        'parts' => [],
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                        ],
                        'type_options' => [
                            ['value' => 'previo', 'label' => 'Orcamento previo'],
                        ],
                        'origin_options' => [
                            ['value' => 'manual', 'label' => 'Manual'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'orcamentos' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            ->assertSee('id="orcamentoReviewModal"', false)
            ->assertSee('data-budget-submission-mode', false)
            ->assertSee('data-budget-review-pendencies', false)
            ->assertSee('data-budget-review-items', false)
            ->assertSee('data-budget-review-submit="save_only"', false)
            ->assertSee('data-budget-review-submit="send_for_approval"', false)
            ->assertSee('Salvar sem enviar', false)
            ->assertSee('Salvar e enviar para aprovacao', false);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_create_page_moves_locked_order_context_to_header_actions(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                    'logo' => [
                        'exists' => false,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [
                            [
                                'id' => 201,
                                'nome_razao' => 'Cliente Alpha',
                                'cpf_cnpj' => '11.111.111/0001-11',
                                'telefone1' => '(21) 98888-1111',
                            ],
                        ],
                        'equipments' => [],
                        'orders' => [
                            [
                                'id' => 401,
                                'numero_os' => 'OS401',
                                'cliente_nome' => 'Cliente Alpha',
                            ],
                        ],
                        'services' => [],
                        'parts' => [],
                        'selected_client_id' => 201,
                        'selected_order_id' => 401,
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'orcamentos' => ['visualizar', 'criar'],
                    'clientes' => ['visualizar'],
                    'equipamentos' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/novo?os_id=401');

        $html = $response->getContent();
        $helpButtonPosition = strpos($html, route('orcamentos.help').'" class="dropdown-item"');
        $newBudgetButtonPosition = strpos($html, route('orcamentos.create').'" class="dropdown-item"');
        $viewOrderPosition = strpos($html, route('orders.show', 401).'" class="dropdown-item"');
        $documentsCenterPosition = strpos($html, route('orders.documents.center', 401).'" class="dropdown-item"');
        $backButtonPosition = strpos($html, route('orcamentos.index').'" class="dropdown-item"');

        $response
            ->assertOk()
            ->assertSee('OS401', false)
            ->assertSee('Cliente Alpha', false)
            ->assertSee('Mais ações', false)
            ->assertSee('Novo orçamento', false)
            ->assertDontSee('Cliente definido pela OS', false);

        $this->assertNotFalse($helpButtonPosition);
        $this->assertNotFalse($newBudgetButtonPosition);
        $this->assertNotFalse($viewOrderPosition);
        $this->assertNotFalse($documentsCenterPosition);
        $this->assertNotFalse($backButtonPosition);
        $this->assertTrue($helpButtonPosition < $newBudgetButtonPosition);
        $this->assertTrue($newBudgetButtonPosition < $viewOrderPosition);
        $this->assertTrue($viewOrderPosition < $documentsCenterPosition);
        $this->assertTrue($documentsCenterPosition < $backButtonPosition);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_create_page_renders_quick_item_modal_and_action_button_when_catalog_permissions_exist(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                    'logo' => [
                        'exists' => false,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [],
                        'equipments' => [],
                        'orders' => [],
                        'services' => [],
                        'parts' => [],
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'orcamentos' => ['visualizar', 'criar'],
                    'servicos' => ['visualizar', 'criar'],
                    'estoque' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            ->assertSee('data-budget-item-quick-create', false)
            ->assertSee('Novo serviço', false)
            ->assertSee('R$ 0,00', false)
            ->assertSee('id="orcamentoQuickItemModal"', false)
            ->assertSee('id="orcamentoQuickItemForm"', false)
            ->assertSee(route('servicos.quick.store'), false)
            ->assertSee(route('estoque.quick.store'), false);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_create_page_renders_quick_item_button_label_for_piece_rows(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/configuracoes/empresa*' => Http::response([
                'status' => 'success',
                'data' => [
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                    'logo' => [
                        'exists' => false,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [],
                        'equipments' => [],
                        'orders' => [],
                        'services' => [],
                        'parts' => [],
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'orcamentos' => ['visualizar', 'criar'],
                    'servicos' => ['visualizar', 'criar'],
                    'estoque' => ['visualizar', 'criar'],
                ]),
                [
                    'desktop_theme' => 'default',
                    '_old_input' => [
                        'itens' => [
                            [
                                'tipo_item' => 'peca',
                            ],
                        ],
                    ],
                ]
            ))
            ->get('/orcamentos/novo');

        $response
            ->assertOk()
            ->assertSee('Nova peça', false)
            ->assertSee('R$ 0,00', false)
            ->assertSee('data-budget-item-quick-create-label', false);

        Http::allowStrayRequests();
    }

    public function test_quick_service_store_creates_service_and_returns_json(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/servicos' => Http::response([
                'status' => 'success',
                'data' => [
                    'servico' => [
                        'id' => 901,
                        'nome' => 'Limpeza interna',
                        'descricao' => 'Serviço rápido de limpeza',
                        'valor' => 120.0,
                        'tempo_padrao_horas' => 1.5,
                        'custo_direto_padrao' => 20.0,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar'],
                    'servicos' => ['criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->postJson('/servicos/rapido', [
                'nome' => 'Limpeza interna',
                'descricao' => 'Serviço rápido de limpeza',
                'tipo_equipamento' => 'Notebook',
                'valor' => 'R$ 120,00',
                'tempo_padrao_horas' => '1,5',
                'custo_direto_padrao' => 'R$ 20,00',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service.id', 901)
            ->assertJsonPath('service.nome', 'Limpeza interna')
            ->assertJsonPath('service.valor', 120);

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/servicos'
                && ($request['nome'] ?? null) === 'Limpeza interna'
                && round((float) ($request['valor'] ?? 0), 2) === 120.00
                && round((float) ($request['tempo_padrao_horas'] ?? 0), 2) === 1.50
                && round((float) ($request['custo_direto_padrao'] ?? 0), 2) === 20.00
                && ($request['tipo_equipamento'] ?? null) === 'Notebook';
        });
    }

    public function test_quick_part_store_creates_part_and_returns_json(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/estoque' => Http::response([
                'status' => 'success',
                'data' => [
                    'peca' => [
                        'id' => 902,
                        'codigo' => 'SSD-480',
                        'nome' => 'SSD 480GB',
                        'preco_venda' => 300.0,
                        'preco_custo' => 220.0,
                        'quantidade_atual' => 4,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar'],
                    'estoque' => ['criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->postJson('/estoque/rapido', [
                'codigo' => 'SSD-480',
                'nome' => 'SSD 480GB',
                'tipo_equipamento' => 'Notebook',
                'categoria' => 'Armazenamento',
                'preco_venda' => 'R$ 300,00',
                'preco_custo' => 'R$ 220,00',
                'quantidade_atual' => 4,
                'estoque_minimo' => 1,
                'observacoes' => 'Peça de teste',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('part.id', 902)
            ->assertJsonPath('part.codigo', 'SSD-480')
            ->assertJsonPath('part.preco_venda', 300);

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/estoque'
                && ($request['codigo'] ?? null) === 'SSD-480'
                && ($request['nome'] ?? null) === 'SSD 480GB'
                && round((float) ($request['preco_venda'] ?? 0), 2) === 300.00
                && round((float) ($request['preco_custo'] ?? 0), 2) === 220.00
                && ($request['categoria'] ?? null) === 'Armazenamento';
        });
    }

    public function test_orcamentos_store_normalizes_brazilian_currency_values_before_forwarding_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 951,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente moeda BRL',
                'titulo' => 'Orçamento moeda BRL',
                'validade_dias' => 10,
                'subtotal' => 'R$ 330,00',
                'desconto' => 'R$ 20,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 310,00',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Troca de tela',
                        'quantidade' => 1,
                        'valor_unitario' => 'R$ 330,00',
                        'desconto' => 'R$ 20,00',
                        'acrescimo' => 'R$ 0,00',
                        'observacoes' => 'Item com moeda formatada',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('orcamentos.show', 951))
            ->assertSessionHas('success', 'Orçamento criado com sucesso.');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && ($request['subtotal'] ?? null) === '330.00'
                && ($request['desconto'] ?? null) === '20.00'
                && ($request['acrescimo'] ?? null) === '0.00'
                && ($request['total'] ?? null) === '310.00'
                && ($request['itens'][0]['valor_unitario'] ?? null) === '330.00'
                && ($request['itens'][0]['desconto'] ?? null) === '20.00'
                && ($request['itens'][0]['acrescimo'] ?? null) === '0.00';
        });
    }

    public function test_orcamentos_store_ignores_default_placeholder_item_before_forwarding_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 953,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente sem itens',
                'titulo' => 'Orcamento em rascunho',
                'validade_dias' => 10,
                'subtotal' => 'R$ 0,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 0,00',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'referencia_id' => '',
                        'descricao' => '',
                        'quantidade' => 1,
                        'valor_unitario' => 'R$ 0,00',
                        'desconto' => 'R$ 0,00',
                        'desconto_tipo' => 'valor',
                        'desconto_percentual' => '0,00',
                        'acrescimo' => 'R$ 0,00',
                        'acrescimo_tipo' => 'valor',
                        'acrescimo_percentual' => '0,00',
                        'observacoes' => '',
                        'modo_precificacao' => 'manual',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('orcamentos.show', 953))
            ->assertSessionHas('success', 'Orçamento criado com sucesso.');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && isset($request['itens'])
                && $request['itens'] === [];
        });
    }

    public function test_orcamentos_store_with_send_for_approval_dispatches_second_backend_request(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 990,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/orcamentos/990/send-approval' => Http::response([
                'status' => 'success',
                'data' => [
                    'dispatch' => [
                        'canal' => 'whatsapp',
                        'status' => 'enviado',
                        'destino' => '5511999999999',
                        'public_url' => 'http://127.0.0.1:8000/orcamento/token-990',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos', [
                'submission_mode' => 'send_for_approval',
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente aprovacao',
                'telefone_contato' => '(11) 99999-9999',
                'titulo' => 'Orcamento com envio',
                'validade_dias' => 10,
                'subtotal' => 'R$ 330,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 330,00',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Troca de tela',
                        'quantidade' => 1,
                        'valor_unitario' => 'R$ 330,00',
                        'desconto' => 'R$ 0,00',
                        'acrescimo' => 'R$ 0,00',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('orcamentos.show', 990))
            ->assertSessionHas('success', 'Orçamento criado e enviado para aprovação do cliente.');

        Http::assertSentCount(2);
        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && ($request['submission_mode'] ?? null) === null
                && ($request['total'] ?? null) === '330.00';
        });
        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos/990/send-approval'
                && $request->method() === 'POST';
        });
    }

    public function test_orcamentos_send_approval_route_dispatches_backend_request(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos/990/send-approval' => Http::response([
                'status' => 'success',
                'data' => [
                    'dispatch' => [
                        'canal' => 'whatsapp',
                        'status' => 'enviado',
                        'destino' => '5511999999999',
                        'public_url' => 'http://127.0.0.1:8000/orcamento/token-990',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos/990/enviar-aprovacao');

        $response
            ->assertRedirect(route('orcamentos.show', 990))
            ->assertSessionHas('success', 'Orçamento enviado para aprovação do cliente.');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos/990/send-approval'
                && $request->method() === 'POST';
        });
    }

    public function test_orcamentos_show_page_renders_copy_link_and_send_approval_actions(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 990,
                        'numero' => 'ORC-2607-000001',
                        'versao' => 1,
                        'tipo_orcamento' => 'assistencia',
                        'tipo_label' => 'Orçamento com equipamento na assistência',
                        'status' => 'pendente_envio',
                        'status_label' => 'Pendente de envio',
                        'status_color' => '#f59e0b',
                        'origem' => 'os',
                        'origem_label' => 'Ordem de serviço',
                        'titulo' => '',
                        'cliente_nome_avulso' => '',
                        'telefone_contato' => '22992741003',
                        'email_contato' => 'cliente@example.com',
                        'validade_dias' => 10,
                        'validade_data' => '13/07/2026',
                        'numero_os' => 'OS26070014',
                        'prazo_execucao' => '',
                        'observacoes' => '',
                        'condicoes' => '',
                        'subtotal' => 230.0,
                        'total' => 230.0,
                        'total_formatado' => '230,00',
                        'cliente' => ['id' => 5, 'nome_razao' => 'teste cliente 2', 'cpf_cnpj' => ''],
                        'equipamento' => null,
                        'os' => null,
                        'responsavel' => ['id' => 1, 'nome' => 'Assistência Técnica'],
                        'itens' => [],
                        'historico' => [],
                        'envios' => [],
                        'aprovacoes' => [],
                        'can_edit' => true,
                        'can_delete' => false,
                        'can_send_approval' => true,
                        'link_publico' => 'http://127.0.0.1:8000/orcamento/token-abc',
                        'created_at' => '03/07/2026 16:13',
                        'updated_at' => '03/07/2026 16:13',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/990');

        $response
            ->assertOk()
            ->assertSee('data-copy-link="http://127.0.0.1:8000/orcamento/token-abc"', false)
            ->assertSee('Copiar link')
            ->assertSee('Enviar para aprovação')
            ->assertSee(route('orcamentos.send_approval', 990), false);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_edit_page_converts_brazilian_validity_date_for_date_input(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orcamentos/form-data*' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [],
                        'equipments' => [],
                        'orders' => [],
                        'services' => [],
                        'parts' => [],
                        'status_options' => [
                            ['value' => 'rascunho', 'label' => 'Rascunho'],
                            ['value' => 'pendente_envio', 'label' => 'Pendente de envio'],
                        ],
                        'default_validity_days' => 10,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orcamentos/990' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 990,
                        'numero' => 'ORC-2607-000001',
                        'versao' => 1,
                        'tipo_orcamento' => 'assistencia',
                        'tipo_label' => 'Orçamento com equipamento na assistência',
                        'status' => 'pendente_envio',
                        'status_label' => 'Pendente de envio',
                        'status_color' => '#f59e0b',
                        'origem' => 'os',
                        'origem_label' => 'Ordem de serviço',
                        'titulo' => '',
                        'cliente_nome_avulso' => '',
                        'telefone_contato' => '22992741003',
                        'email_contato' => 'cliente@example.com',
                        'validade_dias' => 10,
                        'validade_data' => '13/07/2026',
                        'numero_os' => 'OS26070014',
                        'prazo_execucao' => '',
                        'observacoes' => '',
                        'condicoes' => '',
                        'subtotal' => 230.0,
                        'desconto' => 0.0,
                        'desconto_tipo' => 'valor',
                        'desconto_percentual' => null,
                        'acrescimo' => 0.0,
                        'acrescimo_tipo' => 'valor',
                        'acrescimo_percentual' => null,
                        'total' => 230.0,
                        'total_formatado' => '230,00',
                        'cliente' => ['id' => 5, 'nome_razao' => 'teste cliente 2', 'cpf_cnpj' => ''],
                        'equipamento' => null,
                        'os' => null,
                        'responsavel' => ['id' => 1, 'nome' => 'Assistência Técnica'],
                        'itens' => [],
                        'historico' => [],
                        'envios' => [],
                        'aprovacoes' => [],
                        'can_edit' => true,
                        'can_delete' => false,
                        'can_send_approval' => true,
                        'link_publico' => 'http://127.0.0.1:8000/orcamento/token-abc',
                        'created_at' => '03/07/2026 16:13',
                        'updated_at' => '03/07/2026 16:13',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/orcamentos/990/editar');

        $response
            ->assertOk()
            ->assertSee('id="orcamentoValidadeData"', false)
            ->assertSee('value="2026-07-13"', false);

        Http::allowStrayRequests();
    }

    public function test_orcamentos_send_approval_route_reports_backend_pendencies(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos/991/send-approval' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'BUDGET_APPROVAL_VALIDATION',
                    'message' => 'Existem pendências que impedem o envio para aprovação.',
                    'details' => [
                        'send_for_approval' => [
                            'Informe um telefone de contato com WhatsApp válido para enviar o PDF de aprovação.',
                        ],
                    ],
                ],
                'meta' => [],
            ], 422),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos/991/enviar-aprovacao');

        $response->assertRedirect(route('orcamentos.show', 991));

        $error = (string) session('error');
        $this->assertStringContainsString('O envio para aprovação não foi concluído.', $error);
        $this->assertStringContainsString('telefone de contato com WhatsApp válido', $error);
    }

    public function test_orcamentos_store_keeps_budget_saved_when_send_for_approval_returns_warning(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 991,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
            'http://127.0.0.1:8000/api/v1/orcamentos/991/send-approval' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'code' => 'BUDGET_APPROVAL_VALIDATION',
                    'message' => 'Existem pendências que impedem o envio para aprovação.',
                    'details' => [
                        'send_for_approval' => [
                            'Informe um telefone de contato com WhatsApp válido para enviar o PDF de aprovação.',
                        ],
                    ],
                ],
                'meta' => [],
            ], 422),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos', [
                'submission_mode' => 'send_for_approval',
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente sem whatsapp',
                'telefone_contato' => '',
                'titulo' => 'Orcamento salvo com pendencia',
                'validade_dias' => 10,
                'subtotal' => 'R$ 120,00',
                'desconto' => 'R$ 0,00',
                'acrescimo' => 'R$ 0,00',
                'total' => 'R$ 120,00',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Diagnostico',
                        'quantidade' => 1,
                        'valor_unitario' => 'R$ 120,00',
                        'desconto' => 'R$ 0,00',
                        'acrescimo' => 'R$ 0,00',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('orcamentos.show', 991))
            ->assertSessionHas('success', 'Orçamento criado com sucesso.')
            ->assertSessionHas('warning');

        Http::assertSentCount(2);
        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos/991/send-approval'
                && $request->method() === 'POST';
        });
    }

    public function test_orcamentos_store_forwards_percentual_adjustments_with_normalized_payload(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orcamentos' => Http::response([
                'status' => 'success',
                'data' => [
                    'budget' => [
                        'id' => 952,
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'orcamentos' => ['visualizar', 'criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_nome_avulso' => 'Cliente percentual',
                'titulo' => 'Orçamento com ajuste percentual',
                'validade_dias' => 10,
                'subtotal' => 'R$ 100,00',
                'desconto_tipo' => 'percentual',
                'desconto' => '10.00',
                'desconto_percentual' => '10,50',
                'acrescimo_tipo' => 'valor',
                'acrescimo' => 'R$ 5,00',
                'acrescimo_percentual' => '0,00',
                'total' => 'R$ 94,50',
                'itens' => [
                    [
                        'tipo_item' => 'peca',
                        'descricao' => 'Display iPhone 11',
                        'quantidade' => 1,
                        'valor_unitario' => 'R$ 100,00',
                        'desconto_tipo' => 'percentual',
                        'desconto' => '10.00',
                        'desconto_percentual' => '10,00',
                        'acrescimo_tipo' => 'valor',
                        'acrescimo' => 'R$ 5,00',
                        'acrescimo_percentual' => '0,00',
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('orcamentos.show', 952))
            ->assertSessionHas('success', 'Orçamento criado com sucesso.');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orcamentos'
                && ($request['subtotal'] ?? null) === '100.00'
                && ($request['desconto_tipo'] ?? null) === 'percentual'
                && ($request['desconto'] ?? null) === '10.00'
                && ($request['desconto_percentual'] ?? null) === '10.5000'
                && ($request['acrescimo_tipo'] ?? null) === 'valor'
                && ($request['acrescimo'] ?? null) === '5.00'
                && ($request['acrescimo_percentual'] ?? null) === '0.0000'
                && ($request['itens'][0]['desconto_tipo'] ?? null) === 'percentual'
                && ($request['itens'][0]['desconto'] ?? null) === '10.00'
                && ($request['itens'][0]['desconto_percentual'] ?? null) === '10.0000'
                && ($request['itens'][0]['acrescimo_tipo'] ?? null) === 'valor'
                && ($request['itens'][0]['acrescimo'] ?? null) === '5.00'
                && ($request['itens'][0]['acrescimo_percentual'] ?? null) === '0.0000';
        });
    }

    public function test_orders_update_status_redirects_back_with_backend_error_message(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/status' => Http::response([
                'status' => 'error',
                'data' => null,
                'error' => [
                    'message' => 'A transicao solicitada nao e permitida.',
                ],
                'meta' => [],
            ], 422),
        ]));

        $response = $this
            ->from('/os')
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'editar'],
            ]))
            ->post('/os/501/status', [
                'status' => 'aguardando_reparo',
            ]);

        $response
            ->assertRedirect('/os')
            ->assertSessionHas('error', 'A transicao solicitada nao e permitida.')
            ->assertSessionHasInput('status', 'aguardando_reparo');
    }

    public function test_knowledge_os_flow_index_renders_visual_workflow_diagram_and_edit_sections(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                        'conhecimento' => ['visualizar', 'editar'],
                    ],
                    'modules' => ['dashboard', 'conhecimento'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/knowledge/os-flow*' => Http::response([
                'status' => 'success',
                'data' => [
                    'statuses' => [
                        [
                            'id' => 1,
                            'codigo' => 'triagem',
                            'nome' => 'Triagem',
                            'grupo_macro' => 'recepcao',
                            'icone' => 'bi-inbox',
                            'cor' => '#0ea5e9',
                            'ordem_fluxo' => 10,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 2,
                            'codigo' => 'diagnostico_tecnico',
                            'nome' => 'Diagnóstico Técnico',
                            'grupo_macro' => 'diagnostico',
                            'icone' => 'bi-search',
                            'cor' => '#6f5afc',
                            'ordem_fluxo' => 20,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 3,
                            'codigo' => 'aguardando_avaliacao',
                            'nome' => 'Aguardando Avaliação',
                            'grupo_macro' => 'diagnostico',
                            'icone' => 'bi-hourglass-split',
                            'cor' => '#8b5cf6',
                            'ordem_fluxo' => 30,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 4,
                            'codigo' => 'aguardando_reparo',
                            'nome' => 'Aguardando Reparo',
                            'grupo_macro' => 'execucao',
                            'icone' => 'bi-tools',
                            'cor' => '#16a34a',
                            'ordem_fluxo' => 40,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_execucao',
                            'ativo' => true,
                        ],
                        [
                            'id' => 5,
                            'codigo' => 'entregue_reparado',
                            'nome' => 'Entregue Reparo',
                            'grupo_macro' => 'encerrado',
                            'icone' => 'bi-check2-circle',
                            'cor' => '#64748b',
                            'ordem_fluxo' => 50,
                            'status_final' => true,
                            'status_pausa' => false,
                            'gera_evento_crm' => false,
                            'estado_fluxo_padrao' => 'encerrado',
                            'ativo' => true,
                        ],
                        [
                            'id' => 6,
                            'codigo' => 'devolvido_sem_reparo',
                            'nome' => 'Devolvido Sem Reparo',
                            'grupo_macro' => 'encerrado',
                            'icone' => 'bi-arrow-return-left',
                            'cor' => '#64748b',
                            'ordem_fluxo' => 60,
                            'status_final' => true,
                            'status_pausa' => false,
                            'gera_evento_crm' => false,
                            'estado_fluxo_padrao' => 'encerrado',
                            'ativo' => true,
                        ],
                        [
                            'id' => 7,
                            'codigo' => 'entregue_pagamento_pendente',
                            'nome' => 'Entregue Pagamento Pendente',
                            'grupo_macro' => 'encerrado',
                            'icone' => 'bi-receipt',
                            'cor' => '#f59e0b',
                            'ordem_fluxo' => 70,
                            'status_final' => true,
                            'status_pausa' => true,
                            'gera_evento_crm' => false,
                            'estado_fluxo_padrao' => 'pausado',
                            'ativo' => true,
                        ],
                    ],
                    'transitions' => [
                        ['status_origem_id' => 1, 'status_destino_id' => 2, 'ativo' => true],
                        ['status_origem_id' => 2, 'status_destino_id' => 3, 'ativo' => true],
                        ['status_origem_id' => 3, 'status_destino_id' => 4, 'ativo' => true],
                        ['status_origem_id' => 3, 'status_destino_id' => 6, 'ativo' => true],
                        ['status_origem_id' => 4, 'status_destino_id' => 5, 'ativo' => true],
                        ['status_origem_id' => 4, 'status_destino_id' => 7, 'ativo' => true],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'conhecimento' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/conhecimento/fluxo-os');

        $response
            ->assertOk()
            ->assertSee('Conhecimento')
            ->assertSee('Processos e Modelos')
            ->assertSee('Mapa visual do andamento')
            ->assertSee('Recepção')
            ->assertSee('Diagnóstico')
            ->assertSee('Execução')
            ->assertSee('Encerramento')
            ->assertSee('Triagem')
            ->assertSee('Aguardando Reparo')
            ->assertSee('Entregue Reparo')
            ->assertSee('Entregue Pagamento Pendente');

        // Matriz operacional agrupada em dois níveis: super-grupo (Início /
        // Execução / Término) + macrofase, refletindo os status da fixture.
        $response
            ->assertSee('Grupo 1 · Início')
            ->assertSee('Grupo 2 · Execução')
            ->assertSee('Grupo 3 · Término');

        // As células continuam id-based (o agrupamento não pode quebrar o
        // name/value nem o estado marcado): transição semeada 1 (Triagem) -> 2
        // (Diagnóstico Técnico) tem que renderizar o checkbox marcado.
        $response
            ->assertSee('name="transitions[1][]"', false)
            ->assertSeeInOrder([
                'name="transitions[1][]"',
                'value="2"',
                'checked',
            ], false);
    }

    public function test_knowledge_assistance_model_index_renders_visual_workflow_and_queue_rules(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                        'conhecimento' => ['visualizar'],
                    ],
                    'modules' => ['dashboard', 'conhecimento'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/knowledge/os-flow*' => Http::response([
                'status' => 'success',
                'data' => [
                    'statuses' => [
                        [
                            'id' => 1,
                            'codigo' => 'triagem',
                            'nome' => 'Triagem',
                            'grupo_macro' => 'recepcao',
                            'icone' => 'bi-inbox',
                            'cor' => 'secondary',
                            'ordem_fluxo' => 10,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 2,
                            'codigo' => 'diagnostico',
                            'nome' => 'Diagnostico Tecnico',
                            'grupo_macro' => 'diagnostico',
                            'icone' => 'bi-search',
                            'cor' => 'primary',
                            'ordem_fluxo' => 20,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 3,
                            'codigo' => 'aguardando_avaliacao',
                            'nome' => 'Aguardando Avaliacao',
                            'grupo_macro' => 'diagnostico',
                            'icone' => 'bi-hourglass-split',
                            'cor' => 'info',
                            'ordem_fluxo' => 30,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 4,
                            'codigo' => 'aguardando_orcamento',
                            'nome' => 'Aguardando Orcamento',
                            'grupo_macro' => 'orcamento',
                            'icone' => 'bi-receipt',
                            'cor' => 'indigo',
                            'ordem_fluxo' => 50,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_atendimento',
                            'ativo' => true,
                        ],
                        [
                            'id' => 5,
                            'codigo' => 'aguardando_autorizacao',
                            'nome' => 'Aguardando Autorizacao',
                            'grupo_macro' => 'orcamento',
                            'icone' => 'bi-check2-square',
                            'cor' => 'purple',
                            'ordem_fluxo' => 60,
                            'status_final' => false,
                            'status_pausa' => true,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'pausado',
                            'ativo' => true,
                        ],
                        [
                            'id' => 6,
                            'codigo' => 'aguardando_reparo',
                            'nome' => 'Aguardando Reparo',
                            'grupo_macro' => 'execucao',
                            'icone' => 'bi-tools',
                            'cor' => 'warning',
                            'ordem_fluxo' => 70,
                            'status_final' => false,
                            'status_pausa' => true,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_execucao',
                            'ativo' => true,
                        ],
                        [
                            'id' => 7,
                            'codigo' => 'reparo_execucao',
                            'nome' => 'Em Execucao do Servico',
                            'grupo_macro' => 'execucao',
                            'icone' => 'bi-hammer',
                            'cor' => 'warning',
                            'ordem_fluxo' => 80,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_execucao',
                            'ativo' => true,
                        ],
                        [
                            'id' => 8,
                            'codigo' => 'testes_operacionais',
                            'nome' => 'Testes Operacionais',
                            'grupo_macro' => 'qualidade',
                            'icone' => 'bi-list-check',
                            'cor' => 'primary',
                            'ordem_fluxo' => 110,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_execucao',
                            'ativo' => true,
                        ],
                        [
                            'id' => 9,
                            'codigo' => 'testes_finais',
                            'nome' => 'Testes Finais',
                            'grupo_macro' => 'qualidade',
                            'icone' => 'bi-check2-all',
                            'cor' => 'primary',
                            'ordem_fluxo' => 150,
                            'status_final' => false,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'em_execucao',
                            'ativo' => true,
                        ],
                        [
                            'id' => 10,
                            'codigo' => 'reparo_concluido',
                            'nome' => 'Reparo Concluido',
                            'grupo_macro' => 'concluido',
                            'icone' => 'bi-check2-circle',
                            'cor' => 'success',
                            'ordem_fluxo' => 160,
                            'status_final' => true,
                            'status_pausa' => false,
                            'gera_evento_crm' => true,
                            'estado_fluxo_padrao' => 'pronto',
                            'ativo' => true,
                        ],
                        [
                            'id' => 11,
                            'codigo' => 'entregue_reparado',
                            'nome' => 'Equipamento Entregue',
                            'grupo_macro' => 'encerrado',
                            'icone' => 'bi-check2-circle',
                            'cor' => 'dark',
                            'ordem_fluxo' => 220,
                            'status_final' => true,
                            'status_pausa' => false,
                            'gera_evento_crm' => false,
                            'estado_fluxo_padrao' => 'encerrado',
                            'ativo' => true,
                        ],
                        [
                            'id' => 12,
                            'codigo' => 'entregue_pagamento_pendente',
                            'nome' => 'Entregue - Pendencia Financeira',
                            'grupo_macro' => 'interrupcao',
                            'icone' => 'bi-receipt',
                            'cor' => 'orange',
                            'ordem_fluxo' => 140,
                            'status_final' => false,
                            'status_pausa' => true,
                            'gera_evento_crm' => false,
                            'estado_fluxo_padrao' => 'pausado',
                            'ativo' => true,
                        ],
                    ],
                    'transitions' => [
                        ['status_origem_id' => 1, 'status_destino_id' => 2, 'ativo' => true],
                        ['status_origem_id' => 2, 'status_destino_id' => 3, 'ativo' => true],
                        ['status_origem_id' => 2, 'status_destino_id' => 4, 'ativo' => true],
                        ['status_origem_id' => 3, 'status_destino_id' => 4, 'ativo' => true],
                        ['status_origem_id' => 4, 'status_destino_id' => 5, 'ativo' => true],
                        ['status_origem_id' => 5, 'status_destino_id' => 6, 'ativo' => true],
                        ['status_origem_id' => 6, 'status_destino_id' => 7, 'ativo' => true],
                        ['status_origem_id' => 7, 'status_destino_id' => 8, 'ativo' => true],
                        ['status_origem_id' => 8, 'status_destino_id' => 9, 'ativo' => true],
                        ['status_origem_id' => 9, 'status_destino_id' => 10, 'ativo' => true],
                        ['status_origem_id' => 10, 'status_destino_id' => 11, 'ativo' => true],
                        ['status_origem_id' => 10, 'status_destino_id' => 12, 'ativo' => true],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'conhecimento' => ['visualizar'],
            ], syncedAt: 0))
            ->get('/conhecimento/modelo-assistencia-tecnica');

        $response
            ->assertOk()
            ->assertSee('Modelo Ideal')
            ->assertSee('Fluxo natural de uma OS reparada e entregue')
            ->assertSee('Cliente entra, passa por or')
            ->assertSee('Status atuais')
            ->assertSee('Uma fila, um dono, uma pr')
            ->assertSee('Triagem')
            ->assertSee('Garantia')
            ->assertSee('Diagnostico Tecnico')
            ->assertSee('Aguardando Avaliacao')
            ->assertSee('Aguardando Orcamento')
            ->assertSee('Aguardando Autorizacao')
            ->assertSee('Aguardando Reparo')
            ->assertSee('Em Execucao do Servico')
            ->assertSee('Testes Operacionais')
            ->assertSee('Testes Finais')
            ->assertSee('Reparo Concluido')
            ->assertSee('Equipamento Entregue')
            ->assertSee('Fila')
            ->assertSee('WIP t')
            ->assertSee('Aguardando pe')
            ->assertSee('Entregue - Pendencia Financeira')
            ->assertSeeInOrder([
                'Aguardando Avaliacao',
                'Aguardando Orcamento',
                'Aguardando Autorizacao',
                'Aguardando Reparo',
                'Em Execucao do Servico',
                'Testes Operacionais',
                'Testes Finais',
                'Reparo Concluido',
                'Equipamento Entregue',
            ])
            ->assertSee(route('knowledge.assistance-model.index'), false);
    }

    public function test_dashboard_navbar_renders_user_menu_notifications_and_nova_os(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                        'os' => ['visualizar', 'criar'],
                    ],
                    'modules' => ['dashboard', 'os'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/dashboard/summary' => Http::response([
                'status' => 'success',
                'data' => [
                    'stats' => [
                        'orders' => 1,
                    ],
                    'recent_orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'cliente_nome' => 'Ana Comércio',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                            'data_previsao' => '2026-06-24',
                        ],
                    ],
                    'recent_clients' => [],
                    'recent_equipments' => [],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => [
                    'items' => [
                        [
                            'id' => 'notif-1',
                            'tipo' => 'os',
                            'titulo' => 'OS pronta para execução',
                            'corpo' => 'A OS 1001 foi liberada para o técnico.',
                            'rota_destino' => '/os/1001',
                            'icone' => 'clipboard-check',
                            'dados' => [],
                            'lida_em' => null,
                            'criada_em' => '2026-06-22T10:00:00-03:00',
                        ],
                    ],
                    'unread_count' => 1,
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 6,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
            ], syncedAt: 0))
            ->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Ajuda do dashboard')
            ->assertSee('OS abertas x entregues reparadas por mês')
            ->assertSee('OS por status')
            ->assertSee('Tipos de Equipamento')
            ->assertSee('Últimas Ordens de Serviço')
            ->assertSee('Meu Perfil')
            ->assertSee('Configurações do perfil')
            ->assertSee('Sair e Esquecer Login')
            ->assertSee('Nova OS')
            ->assertSee('Abrir página cheia')
            ->assertSee(route('notifications.summary'), false)
            ->assertSee('data-desktop-correspondence-root', false)
            ->assertSee('data-desktop-correspondence-badge', false)
            ->assertSee('bi bi-envelope', false)
            ->assertSee('Mensagens e documentos')
            ->assertSee('Resumo carregado sob demanda.')
            ->assertSee('Abra este menu para carregar as notificações mais recentes.')
            ->assertSee('dropdown-menu-start desktop-notification-menu', false)
            ->assertSee('data-bs-boundary="viewport"', false)
            ->assertDontSee('dropdown-menu-end desktop-notification-menu', false)
            ->assertDontSee('Configurações do sistema');

        $this->assertSame(2, substr_count($response->getContent(), 'dropdown-menu-start desktop-notification-menu'));
        $this->assertSame(2, substr_count($response->getContent(), 'data-bs-boundary="viewport"'));
    }

    public function test_dashboard_data_route_returns_expanded_summary_json(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                    ],
                    'modules' => ['dashboard'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/dashboard/summary*' => Http::response($this->dashboardSummaryFixture()),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
            ], syncedAt: 0))
            ->getJson('/dashboard/dados?ano=2026&equip_mes=1&equip_ano=2026');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.heroCard.label', 'Faturamento mês')
            ->assertJsonPath('data.heroCard.action_url', null)
            ->assertJsonPath('data.charts.monthly.year', 2026)
            ->assertJsonPath('data.charts.equipmentTypes.period.mes', 1)
            ->assertJsonPath('data.lowStock', []);
    }

    public function test_dashboard_data_route_normalizes_api_orders_action_url_to_desktop_route(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                        'os' => ['visualizar'],
                    ],
                    'modules' => ['dashboard', 'os'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/dashboard/summary*' => Http::response($this->dashboardSummaryFixture([
                'data' => [
                    'access' => [
                        'profile' => 'tecnico',
                        'is_technician' => true,
                        'has_financial_access' => false,
                    ],
                    'hero_card' => [
                        'type' => 'technician',
                        'label' => 'Comissões acumuladas',
                        'value' => 10.0,
                        'value_type' => 'money',
                        'meta' => 'Comissões estimadas neste mês.',
                        'icon' => 'bi-wallet2',
                        'accent' => '#16a34a',
                        'action_label' => 'Ver minhas OS',
                        'action_url' => '/api/v1/orders',
                    ],
                ],
            ])),
        ]));

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
            ], syncedAt: 0))
            ->getJson('/dashboard/dados?ano=2026&equip_mes=1&equip_ano=2026');

        $response
            ->assertOk()
            ->assertJsonPath('data.heroCard.action_url', route('orders.index'))
            ->assertJsonPath('data.heroCard.action_label', 'Ver minhas OS');
    }

    public function test_search_suggestions_returns_grouped_json_for_allowed_domains(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'cliente_nome' => 'Ana Comércio',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'telefone1' => '(21) 98888-1111',
                        'email' => 'alpha@example.com',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 201,
                            'nome_razao' => 'Ana Comércio LTDA',
                            'telefone1' => '(21) 98888-1111',
                            'email' => 'contato@ana.com',
                            'cidade' => 'Rio de Janeiro',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'resumo_tecnico' => 'Notebook Acer Nitro',
                            'numero_serie' => 'SN-12345',
                            'cliente_nome' => 'Ana Comércio LTDA',
                            'tipo_nome' => 'Notebook',
                            'marca_nome' => 'Acer',
                            'modelo_nome' => 'Nitro 5',
                            'primary_photo_id' => 91,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 401,
                            'nome' => 'Ana Gestora',
                            'email' => 'ana@empresa.com',
                            'telefone' => '(22) 99999-1111',
                            'perfil' => 'gerente',
                            'group' => [
                                'nome' => 'Gerência',
                            ],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/groups' => Http::response([
                'status' => 'success',
                'data' => [
                    'groups' => [
                        [
                            'id' => 501,
                            'nome' => 'Ana Equipe',
                            'descricao' => 'Equipe da Ana',
                            'sistema' => false,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        'resumo_tecnico' => 'Notebook Acer Nitro',
                        'numero_serie' => 'SN-12345',
                        'primary_photo_url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar'],
                    'clientes' => ['visualizar'],
                    'equipamentos' => ['visualizar'],
                    'usuarios' => ['visualizar'],
                    'grupos' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/buscar?q=ana&scope=tudo');

        $response
            ->assertOk()
            ->assertSee('Ordens de Serviço')
            ->assertSee('OS1001')
            ->assertSee('Ana Comércio LTDA')
            ->assertSee('Notebook · Acer · Nitro 5')
            ->assertSee('Tipo:')
            ->assertSee('Marca:')
            ->assertSee('Modelo:')
            ->assertSee('Cliente:')
            ->assertSee(route('equipments.photos.show', ['equipment' => 301, 'photo' => 91]), false)
            ->assertSee('desktop-result-card has-equipment-details', false)
            ->assertSee('Ana Gestora')
            ->assertSee('Ana Equipe');
    }

    public function test_search_suggestions_expose_equipment_photo_identity_and_client(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 3080,
                            'resumo_tecnico' => '',
                            'numero_serie' => '',
                            'cliente_nome' => 'Guilherme Conti Padrão',
                            'tipo_nome' => 'Notebook',
                            'marca_nome' => 'Lenovo',
                            'modelo_nome' => 'IdeaPad 3',
                            'primary_photo_id' => 77,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'equipamentos' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->getJson('/buscar/sugestoes?q=padrao&scope=equipamentos');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.sections.0.key', 'equipamentos')
            ->assertJsonPath('data.sections.0.items.0.label', 'Notebook · Lenovo · IdeaPad 3')
            ->assertJsonPath('data.sections.0.items.0.image_url', route('equipments.photos.show', [
                'equipment' => 3080,
                'photo' => 77,
            ]))
            ->assertJsonPath('data.sections.0.items.0.facts.0.label', 'Tipo')
            ->assertJsonPath('data.sections.0.items.0.facts.0.value', 'Notebook')
            ->assertJsonPath('data.sections.0.items.0.facts.1.value', 'Lenovo')
            ->assertJsonPath('data.sections.0.items.0.facts.2.value', 'IdeaPad 3')
            ->assertJsonPath('data.sections.0.items.0.facts.3.label', 'Cliente')
            ->assertJsonPath('data.sections.0.items.0.facts.3.value', 'Guilherme Conti Padrão')
            ->assertJsonPath('data.sections.0.items.0.subtitle', 'Número de série não informado');
    }

    public function test_search_suggestions_endpoint_returns_json(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'cliente_nome' => 'Ana Comércio',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
            ]))
            ->getJson('/buscar/sugestoes?q=ana&scope=os');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.scope', ['os']);
    }

    public function test_search_accepts_multiple_scopes_selected_via_checkboxes(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'cliente_nome' => 'Ana Comércio',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 201,
                            'nome_razao' => 'Ana Comércio LTDA',
                            'telefone1' => '(21) 98888-1111',
                            'email' => 'contato@ana.com',
                            'cidade' => 'Rio de Janeiro',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $session = $this->desktopSession([
            'dashboard' => ['visualizar'],
            'os' => ['visualizar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        // O dropdown do topbar guarda a seleção multi-escolha num único input hidden,
        // como string separada por vírgula.
        $this->withSession($session)
            ->getJson('/buscar/sugestoes?q=ana&scope=os,clientes')
            ->assertOk()
            ->assertJsonPath('data.scope', ['os', 'clientes'])
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.0.key', 'os')
            ->assertJsonPath('data.sections.1.key', 'clientes');

        // A tela de busca completa (`/buscar`) envia checkboxes reais, que o navegador
        // serializa como array (`scope[]=os&scope[]=clientes`).
        $this->withSession($session)
            ->getJson('/buscar/sugestoes?q=ana&scope[]=os&scope[]=clientes')
            ->assertOk()
            ->assertJsonPath('data.scope', ['os', 'clientes'])
            ->assertJsonCount(2, 'data.sections');
    }

    public function test_profile_update_refreshes_session(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'nome' => 'Usuário Renovado',
                    'permissions' => [
                        'dashboard' => ['visualizar'],
                    ],
                    'modules' => ['dashboard'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->patch('/perfil', [
                'nome' => 'Usuário Renovado',
            ]);

        $response
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('desktop_auth.user.nome', 'Usuário Renovado');
    }

    public function test_profile_password_change_logs_out_user(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/password' => Http::response([
                'status' => 'success',
                'data' => [
                    'requires_relogin' => true,
                    'revoked_tokens' => 1,
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->patch('/perfil/senha', [
                'current_password' => 'Senha@123',
                'password' => 'Senha@456',
                'password_confirmation' => 'Senha@456',
            ]);

        $response
            ->assertRedirect(route('login'))
            ->assertSessionMissing('desktop_auth');
    }

    public function test_notification_open_marks_as_read_and_redirects_to_destination(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications/notif-1/read' => Http::response([
                'status' => 'success',
                'data' => [
                    'notification' => [
                        'id' => 'notif-1',
                        'tipo' => 'os',
                        'titulo' => 'OS pronta para execução',
                        'corpo' => 'A OS 1001 foi liberada para o técnico.',
                        'rota_destino' => '/os/1001',
                        'icone' => 'clipboard-check',
                        'dados' => [],
                        'lida_em' => '2026-06-22T10:05:00-03:00',
                        'criada_em' => '2026-06-22T10:00:00-03:00',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/notificacoes/notif-1/abrir');

        $response->assertRedirect('/os/1001');
    }

    public function test_notifications_index_renders_summary_and_mark_all_action(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => [
                    'items' => [
                        [
                            'id' => 'notif-1',
                            'tipo' => 'os',
                            'titulo' => 'OS pronta para execução',
                            'corpo' => 'A OS 1001 foi liberada para o técnico.',
                            'rota_destino' => '/os/1001',
                            'icone' => 'clipboard-check',
                            'dados' => [],
                            'lida_em' => null,
                            'criada_em' => '2026-06-22T10:00:00-03:00',
                        ],
                    ],
                    'unread_count' => 1,
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 20,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/notificacoes');

        $response
            ->assertOk()
            ->assertSee('OS pronta para execução')
            ->assertSee('Marcar todas como lidas');
    }

    public function test_notifications_summary_endpoint_returns_lazy_loaded_payload(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => [
                    'items' => [
                        [
                            'id' => 'notif-1',
                            'tipo' => 'os',
                            'titulo' => 'OS pronta para execução',
                            'corpo' => 'A OS 1001 foi liberada para o técnico.',
                            'rota_destino' => '/os/1001',
                            'icone' => 'clipboard-check',
                            'dados' => [],
                            'lida_em' => null,
                            'criada_em' => '2026-06-22T10:00:00-03:00',
                        ],
                    ],
                    'unread_count' => 1,
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 6,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/notificacoes/resumo');

        $response
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.id', 'notif-1')
            ->assertJsonPath('data.items.0.url', route('orders.show', 1001));
    }

    public function test_correspondence_summary_and_page_forward_the_isolated_box_filter(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => [
                    'box' => 'correspondence',
                    'items' => [[
                        'id' => 'doc-1',
                        'tipo_evento' => 'document.signature.requested',
                        'caixa' => 'correspondence',
                        'titulo' => 'Documento aguardando sua assinatura',
                        'corpo' => 'Laudo técnico da OS1001.',
                        'rota_destino' => '/os/1001/documentos#assinaturas-pendentes',
                        'payload' => ['icon' => 'envelope-paper'],
                        'lida_em' => null,
                        'created_at' => '2026-07-19T10:00:00-03:00',
                    ]],
                    'unread_count' => 1,
                ],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1,
                    'per_page' => 20,
                    'total' => 1,
                    'last_page' => 1,
                    'from' => 1,
                    'to' => 1,
                ]],
            ]),
        ]);

        $session = $this->desktopSession(['dashboard' => ['visualizar']]);

        $this->withSession($session)
            ->get('/notificacoes/resumo?box=correspondence')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonPath('data.items.0.caixa', 'correspondence');

        $this->withSession($session)
            ->get('/notificacoes?box=correspondence')
            ->assertOk()
            ->assertSee('Mensagens e documentos')
            ->assertSee('Documento aguardando sua assinatura');

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'box=correspondence'));
    }

    public function test_dashboard_render_does_not_hit_notifications_api(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/dashboard');

        $response->assertOk();

        Http::assertNotSent(static fn ($request): bool => str_contains(
            $request->url(),
            '/api/v1/notifications'
        ));
    }

    public function test_desktop_layout_exposes_page_transition_loader(): void
    {
        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
            ]))
            ->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('data-desktop-page-loader', false)
            ->assertSee('Carregando página');
    }

    public function test_nova_os_button_visible_and_create_page_renders_form(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 51,
                            'nome' => 'Tecnico Banco',
                            'email' => 'tecnico@empresa.com',
                            'perfil' => 'tecnico',
                            'ativo' => true,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'telefone1' => '(21) 98888-1111',
                        'email' => 'alpha@example.com',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'telefone1' => '(21) 98888-1111',
                        'email' => 'alpha@example.com',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 11,
                            'nome_razao' => 'Ana Comércio LTDA',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        'resumo_tecnico' => 'Notebook Acer Nitro',
                        'numero_serie' => 'SN-12345',
                        'primary_photo_url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        'resumo_tecnico' => 'Notebook Acer Nitro',
                        'numero_serie' => 'SN-12345',
                        'primary_photo_url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'clientes' => ['visualizar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/os/criar');

        $response
            ->assertOk()
            ->assertSee('Nova OS')
            ->assertSee('Selecione o cliente')
            ->assertSee(route('orders.clients.search'), false)
            ->assertSee('data-native-select="true"', false)
            ->assertDontSee('data-select2="false"', false)
            ->assertSee('Busque por nome, serie ou cliente')
            ->assertSee(route('orders.equipments.search'), false)
            ->assertSee('Acessórios recebidos nesta OS')
            ->assertSee('name="acessorios"', false)
            ->assertSee('Este registro pertence somente a esta ordem de serviço')
            ->assertSee('Relato do cliente')
            ->assertSee('Enviar PDF ao cliente')
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('data-order-create-idempotency-key', false);
    }

    public function test_nova_os_client_search_returns_compact_json_for_select2(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 11,
                            'nome_razao' => 'Ana Comércio LTDA',
                            'telefone1' => '(11) 99999-1111',
                            'email' => 'ana@empresa.com',
                            'nome_contato' => 'Ana',
                            'cidade' => 'São Paulo',
                            'uf' => 'SP',
                        ],
                        [
                            'id' => 12,
                            'nome_razao' => 'Ana Distribuidora',
                            'telefone1' => '(11) 98888-2222',
                            'email' => 'contato@ana-distribuidora.com',
                            'nome_contato' => 'Bruno',
                            'cidade' => 'Campinas',
                            'uf' => 'SP',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 2,
                        'per_page' => 10,
                        'total' => 18,
                        'last_page' => 2,
                        'from' => 11,
                        'to' => 18,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
            ]))
            ->getJson('/os/clientes/buscar?q=Ana&page=2');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('clients.0.id', 11)
            ->assertJsonPath('clients.0.text', 'Ana Comércio LTDA')
            ->assertJsonPath('clients.0.phone', '(11) 99999-1111')
            ->assertJsonPath('clients.0.email', 'ana@empresa.com')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/v1/clients')
                && str_contains($request->url(), 'search=Ana')
                && str_contains($request->url(), 'page=2')
                && str_contains($request->url(), 'per_page=10');
        });
    }

    public function test_nova_os_submission_creates_order_and_redirects_to_detail(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 77,
                        'numero_os' => 'OS0077',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['criar'],
            ]))
            ->post('/os', [
                'idempotency_key' => '4a137b0d-18a8-4576-b6f9-a79381948d6c',
                'cliente_id' => 11,
                'equipamento_id' => 21,
                'relato_cliente' => 'Notebook não liga.',
                'acessorios' => 'Carregador original, Bolsa',
                'prioridade' => 'alta',
                'data_previsao' => '2026-06-24',
                'observacoes_internas' => 'Prioridade do balcão.',
                'enviar_pdf_cliente' => '1',
            ]);

        $response
            ->assertRedirect(route('orders.show', 77))
            ->assertSessionHas('success');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/orders'
                && $request['idempotency_key'] === '4a137b0d-18a8-4576-b6f9-a79381948d6c'
                && $request['acessorios'] === 'Carregador original, Bolsa'
                && $request['enviar_pdf_cliente'] === true;
        });
    }

    public function test_nova_os_submission_with_photos_uses_multipart_without_json_content_type(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 88,
                        'numero_os' => 'OS0088',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'os' => ['criar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->post('/os', [
                'idempotency_key' => 'ee4fe142-41e1-4dc0-8b8e-332b6234f733',
                'cliente_id' => 11,
                'equipamento_id' => 21,
                'relato_cliente' => 'Notebook com tela apagada.',
                'prioridade' => 'normal',
                'data_previsao' => '2026-06-24',
                'observacoes_internas' => 'Cadastro inicial com fotos.',
                'fotos' => [
                    UploadedFile::fake()->image('frente.jpg'),
                    UploadedFile::fake()->image('verso.jpg'),
                ],
            ]);

        $response
            ->assertRedirect(route('orders.show', 88))
            ->assertSessionHas('success');

        Http::assertSent(static function ($request): bool {
            $contentType = strtolower(implode(';', $request->header('Content-Type')));
            $body = $request->body();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/orders'
                && str_contains($contentType, 'multipart/form-data')
                && ! str_contains($contentType, 'application/json')
                && str_contains($body, 'name="cliente_id"')
                && str_contains($body, "\r\n\r\n11\r\n")
                && str_contains($body, 'name="equipamento_id"')
                && str_contains($body, "\r\n\r\n21\r\n")
                && str_contains($body, 'name="fotos[]"')
                && str_contains($body, 'frente.jpg')
                && str_contains($body, 'verso.jpg');
        });
    }

    public function test_client_detail_page_renders_related_orders_and_equipments(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'status_cadastro' => 'ativo',
                        'tipo_pessoa' => 'juridica',
                        'cpf_cnpj' => '11.111.111/0001-11',
                        'rg_ie' => 'IE-123',
                        'email' => 'alpha@example.com',
                        'telefone1' => '(21) 98888-1111',
                        'telefone2' => '(21) 97777-2222',
                        'nome_contato' => 'Contato Alpha',
                        'telefone_contato' => '(21) 96666-3333',
                        'cep' => '20000-000',
                        'endereco' => 'Rua A',
                        'numero' => '100',
                        'complemento' => 'Sala 2',
                        'bairro' => 'Centro',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                        'referencia' => 'Próximo à praça',
                        'observacoes' => 'Cliente estratégico',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'numero_os_legado' => '26050001',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                            'data_abertura' => '19/05/2026',
                            'data_previsao' => '22/05/2026',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 5,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'resumo_tecnico' => 'Notebook Acer Nitro',
                            'numero_serie' => 'SN-12345',
                            'imei' => '',
                            'desktop_modalidade' => 'desktop',
                            'status_operacional' => 'Ativo',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 5,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'clientes' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                    'equipamentos' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/clientes/201');

        $response
            ->assertOk()
            ->assertSee('Cliente Alpha')
            ->assertSee('Ordens de serviço do cliente')
            ->assertSee('Equipamentos do cliente')
            ->assertSee('OS1001')
            ->assertSee('Notebook Acer Nitro')
            ->assertSee(route('orders.create', ['cliente_id' => 201]), false)
            ->assertSee('data-new-order-action="client"', false);
    }

    public function test_client_detail_page_renders_quick_actions_and_edit_link(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'cpf_cnpj' => '11.111.111/0001-11',
                        'rg_ie' => 'IE-123',
                        'email' => 'alpha@example.com',
                        'telefone1' => '(21) 98888-1111',
                        'telefone2' => '(21) 97777-2222',
                        'nome_contato' => 'Contato Alpha',
                        'telefone_contato' => '(21) 96666-3333',
                        'cep' => '20000-000',
                        'endereco' => 'Rua A',
                        'numero' => '100',
                        'complemento' => 'Sala 2',
                        'referencia' => 'Próximo à praça',
                        'bairro' => 'Centro',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                        'observacoes' => 'Cliente estratégico',
                        'status_cadastro' => 'completo',
                        'preferencia_contato' => 'WhatsApp',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'numero_os_legado' => '26050001',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                            'data_abertura' => '19/05/2026',
                            'data_previsao' => '22/05/2026',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 5,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'resumo_tecnico' => 'Notebook Acer Nitro',
                            'numero_serie' => 'SN-12345',
                            'imei' => '',
                            'desktop_modalidade' => 'desktop',
                            'status_operacional' => 'Ativo',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 5,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'clientes' => ['visualizar', 'editar'],
                    'os' => ['visualizar', 'criar'],
                    'equipamentos' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/clientes/201');

        $response
            ->assertOk()
            ->assertSee('Mais ações')
            ->assertSee('Editar cliente')
            ->assertSee('Ver OS do cliente')
            ->assertSee('Ver equipamentos')
            ->assertSee('Ações rápidas')
            ->assertSee('Ligar')
            ->assertSee('WhatsApp')
            ->assertSee('E-mail')
            ->assertSee('Ordens de serviço do cliente')
            ->assertSee('Equipamentos do cliente')
            ->assertSee('OS1001')
            ->assertSee('Notebook Acer Nitro');
    }

    public function test_client_index_renders_even_when_optional_contact_fields_are_missing(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/me' => Http::response([
                'status' => 'success',
                'data' => $this->fakeUser([
                    'permissions' => [
                        'clientes' => ['visualizar'],
                    ],
                    'modules' => ['clientes'],
                ]),
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                            'cpf_cnpj' => '11.111.111/0001-11',
                            'nome_contato' => 'Contato Alpha',
                            'orders_count' => 8,
                            'primary_photo_id' => 91,
                            'equipments_count' => 4,
                            'telefone1' => '(21) 98888-1111',
                            'email' => 'alpha@example.com',
                            'cidade' => 'Rio de Janeiro',
                            'uf' => 'RJ',
                            'status_cadastro' => 'completo',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
            ], syncedAt: 0))
            ->get('/clientes');

        $response
            ->assertOk()
            ->assertSee('Cliente Alpha')
            ->assertSee('Contato Alpha')
            ->assertSee('8 OS')
            ->assertSee('4 equipamentos')
            ->assertSee('Ligar')
            ->assertSee('WhatsApp')
            ->assertSee('E-mail')
            ->assertSee('Ações');
    }

    public function test_client_create_and_edit_pages_render_forms(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'tipo_pessoa' => 'juridica',
                        'nome_razao' => 'Cliente Alpha',
                        'cpf_cnpj' => '11.111.111/0001-11',
                        'rg_ie' => 'IE-123',
                        'email' => 'alpha@example.com',
                        'telefone1' => '(21) 98888-1111',
                        'telefone2' => '(21) 97777-2222',
                        'nome_contato' => 'Contato Alpha',
                        'telefone_contato' => '(21) 96666-3333',
                        'cep' => '20000-000',
                        'endereco' => 'Rua A',
                        'numero' => '100',
                        'complemento' => 'Sala 2',
                        'referencia' => 'Próximo à praça',
                        'bairro' => 'Centro',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                        'observacoes' => 'Cliente estratégico',
                        'status_cadastro' => 'completo',
                        'preferencia_contato' => 'WhatsApp',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $createResponse = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/clientes/novo');

        $createResponse
            ->assertOk()
            ->assertSee('Novo cliente')
            ->assertSee('Cadastro operacional do cliente')
            ->assertSee('DADOS PESSOAIS')
            ->assertSee('CONTATO ADICIONAL (opcional)')
            ->assertSee('ENDEREÇO')
            ->assertSee('Nome / Razão Social *')
            ->assertDontSee('Situação cadastral')
            ->assertSee('Criar cliente');

        $editResponse = $this
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/clientes/201/editar');

        $editResponse
            ->assertOk()
            ->assertSee('Editar cliente')
            ->assertSee('Edição de cliente')
            ->assertSee('Salvar alterações')
            ->assertSee('Cliente Alpha');
    }

    public function test_nova_os_page_renders_quick_client_modal_when_create_permission_exists(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 51,
                            'nome' => 'Tecnico Banco',
                            'email' => 'tecnico@empresa.com',
                            'perfil' => 'tecnico',
                            'ativo' => true,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 100,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['criar'],
                'clientes' => ['criar', 'visualizar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/os/criar');

        $response
            ->assertOk()
            ->assertSee('Novo cliente')
            ->assertSee('Cadastro rápido de cliente')
            ->assertSee('Abrir cadastro completo')
            ->assertSee(route('clients.quick.store'), false);
    }

    public function test_quick_client_store_creates_client_and_returns_json(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/clients' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 501,
                        'nome_razao' => 'Cliente Novo',
                        'telefone1' => '(21) 99999-8888',
                        'email' => 'novo@example.com',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['criar'],
                'clientes' => ['criar'],
            ]))
            ->postJson('/clientes/rapido', [
                'nome_razao' => 'Cliente Novo',
                'telefone1' => '(21) 99999-8888',
                'email' => 'novo@example.com',
                'cpf_cnpj' => '11.111.111/0001-11',
                'nome_contato' => 'Contato Novo',
                'telefone_contato' => '(21) 98888-7777',
                'cep' => '20000-000',
                'endereco' => 'Rua Nova',
                'numero' => '100',
                'bairro' => 'Centro',
                'cidade' => 'Rio de Janeiro',
                'uf' => 'RJ',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client.id', 501)
            ->assertJsonPath('client.nome_razao', 'Cliente Novo');

        Http::assertSent(function ($request): bool {
            if ($request->url() !== 'http://127.0.0.1:8000/api/v1/clients') {
                return false;
            }

            $payload = $request->data();

            return ($payload['nome_razao'] ?? null) === 'Cliente Novo'
                && ($payload['telefone1'] ?? null) === '(21) 99999-8888'
                && ($payload['tipo_pessoa'] ?? null) === 'fisica'
                && ($payload['status_cadastro'] ?? null) === 'completo';
        });
    }

    public function test_quick_client_store_returns_field_errors_when_required_data_is_missing(): void
    {
        Http::fake();

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['criar'],
                'clientes' => ['criar'],
            ]))
            ->postJson('/clientes/rapido', [
                'email' => 'sem-nome-e-telefone@example.com',
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['nome_razao', 'telefone1']);

        Http::assertNothingSent();
    }

    public function test_nova_os_equipment_search_returns_compact_json_for_select2(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'cliente_id' => 201,
                            'cliente_nome' => 'Cliente Alpha',
                            'resumo_tecnico' => '',
                            'marca_nome' => 'Acer',
                            'modelo_nome' => 'Nitro 5',
                            'numero_serie' => 'SN-12345',
                            'primary_photo_url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 10,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['criar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->getJson('/os/equipamentos/buscar?q=notebook&client_id=201&page=1');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('equipments.0.id', 301)
            ->assertJsonPath('equipments.0.label', 'Acer / Nitro 5')
            ->assertJsonPath('equipments.0.summary', '')
            ->assertJsonPath('equipments.0.brand_name', 'Acer')
            ->assertJsonPath('equipments.0.model_name', 'Nitro 5')
            ->assertJsonPath('equipments.0.client_id', 201)
            ->assertJsonPath('equipments.0.client_name', 'Cliente Alpha')
            ->assertJsonPath('equipments.0.photo_url', route('equipments.photos.show', [301, 91]));

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/v1/equipments')
                && str_contains($request->url(), 'search=notebook')
                && str_contains($request->url(), 'client_id=201')
                && str_contains($request->url(), 'per_page=10');
        });
    }

    public function test_nova_os_page_prefills_selected_client_and_selected_equipment_in_wizard(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 51,
                            'nome' => 'Tecnico Banco',
                            'email' => 'tecnico@empresa.com',
                            'perfil' => 'tecnico',
                            'ativo' => true,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'telefone1' => '(21) 98888-1111',
                        'email' => 'alpha@example.com',
                        'cidade' => 'Rio de Janeiro',
                        'uf' => 'RJ',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients*' => Http::response([
                'status' => 'success',
                'data' => [
                    'clients' => [
                        [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        [
                            'id' => 202,
                            'nome_razao' => 'Cliente Beta',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        'resumo_tecnico' => '',
                        'marca_nome' => 'Acer',
                        'modelo_nome' => 'Nitro 5',
                        'numero_serie' => 'SN-12345',
                        'primary_photo_url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                    'clientes' => ['visualizar', 'editar'],
                    'equipamentos' => ['visualizar', 'criar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/criar?cliente_id=202&equipamento_id=301');

        $response
            ->assertOk()
            ->assertSee('Cliente Alpha')
            ->assertSee('(21) 98888-1111', false)
            ->assertSee('alpha@example.com', false)
            ->assertSee('Rio de Janeiro', false)
            ->assertSee('Editar cliente', false)
            ->assertSee(route('clients.edit', 201), false)
            ->assertSee('Editar equipamento', false)
            ->assertSee(route('equipments.edit', 301), false)
            ->assertSee('Acer / Nitro 5', false)
            ->assertSee(route('equipments.photos.show', [301, 91]), false)
            ->assertSee('Novo equipamento', false)
            ->assertSee('quickEquipmentModal', false)
            ->assertSee('embedded=1', false)
            ->assertSee('Busque por nome, serie ou cliente')
            ->assertSee(route('orders.clients.search'), false)
            ->assertSee(route('orders.equipments.search'), false)
            ->assertSee('value="201"', false)
            ->assertDontSee('value="202"', false)
            ->assertSee('value="301"', false);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/v1/equipments/301');
        });
    }

    public function test_orders_show_page_renders_summary_grid_and_full_width_operational_cards(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'em_execucao',
                        'status_nome' => 'Em execução do serviço',
                        'status_cor' => '#64748b',
                        'is_encerrada' => false,
                        'prioridade' => 'alta',
                        'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                        'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                        'tecnico' => ['id' => 51, 'nome' => 'Tecnico Banco'],
                        'fotos' => [],
                        'documentos' => [],
                        'status_disponiveis' => [],
                        'proximas_etapas' => [],
                        'orcamento' => null,
                        'financeiro_resumo' => [
                            'titulo_id' => 63,
                            'valor_titulo' => 80.0,
                            'valor_recebido' => 80.0,
                            'saldo_aberto' => 0.0,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                    'financeiro' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501');

        $response
            ->assertOk()
            ->assertSee('Ver lançamento financeiro')
            ->assertSee(route('financeiro.show', 63), false)
            ->assertSee('data-new-order-context-trigger', false)
            ->assertSee('newOrderFromOrderModal', false)
            ->assertSee('Mesmo equipamento')
            ->assertSee('Equipamento novo')
            ->assertSee(route('orders.create', ['cliente_id' => 201, 'equipamento_id' => 301]))
            ->assertSee(route('orders.create', ['cliente_id' => 201]), false);

        $html = $response->getContent();
        $photosPosition = strpos($html, '<i class="bi bi-images me-1"></i>Fotos');
        $historyPosition = strpos($html, 'data-event-timeline');

        $this->assertNotFalse($photosPosition);
        $this->assertNotFalse($historyPosition);
        $this->assertGreaterThan($photosPosition, $historyPosition);

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        $summaryGrid = $xpath->query('//*[@data-os-top-grid]')->item(0);
        $fullWidthContainer = $xpath->query('//*[@data-os-full-width-cards]')->item(0);

        $this->assertInstanceOf(\DOMElement::class, $summaryGrid);
        $this->assertInstanceOf(\DOMElement::class, $fullWidthContainer);
        $this->assertCount(3, $xpath->query('./article[@data-os-summary-card]', $summaryGrid));
        $this->assertCount(5, $xpath->query('./article | ./section[@data-event-timeline]', $fullWidthContainer));

        foreach (['Defeito e Solução', 'Valores e Orçamento', 'Documentos', 'Fotos', 'Histórico da OS'] as $cardTitle) {
            $this->assertStringContainsString($cardTitle, $fullWidthContainer->textContent);
        }
    }

    public function test_orders_audit_page_renders_complete_paginated_events_and_forwards_filters(): void
    {
        Http::preventStrayRequests();
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/events*' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'em_execucao',
                        'status_nome' => 'Em execução do serviço',
                        'status_cor' => '#64748b',
                        'cliente_id' => 201,
                        'cliente_nome' => 'Cliente Alpha',
                        'equipamento_id' => 301,
                        'equipamento_resumo_curto' => 'Notebook Acer Nitro 5',
                        'equipamento_numero_serie' => 'SN-12345',
                        'tecnico' => [
                            'id' => 51,
                            'nome' => 'Técnico Banco',
                            'email' => 'tecnico@empresa.com',
                        ],
                        'data_abertura' => '2026-07-16T08:00:00-03:00',
                        'status_atualizado_em' => '2026-07-16T10:00:00-03:00',
                    ],
                    'events' => [
                        [
                            'id' => 91,
                            'category' => 'status',
                            'type' => 'status_alterado',
                            'title' => 'Status alterado',
                            'description' => 'triagem → em_execucao',
                            'data' => [
                                'status_anterior' => 'triagem',
                                'status_novo' => 'em_execucao',
                                'contexto' => ['canal' => 'painel'],
                            ],
                            'origin' => 'usuario',
                            'created_at' => '2026-07-16T10:00:00-03:00',
                            'user' => [
                                'id' => 51,
                                'name' => 'Técnico Banco',
                                'email' => 'tecnico@empresa.com',
                            ],
                            'provenance' => [
                                'kind' => 'legacy',
                                'legacy_table' => 'os_status_historico',
                                'legacy_id' => 44,
                                'append_only' => true,
                            ],
                        ],
                    ],
                    'stats' => [
                        'total' => 251,
                        'categories' => [
                            'status' => 80,
                            'orcamento' => 40,
                            'financeiro' => 50,
                            'documento' => 30,
                            'mensagem' => 20,
                            'registro' => 31,
                        ],
                        'origins' => ['usuario' => 180, 'sistema' => 71],
                        'types' => ['status_alterado' => 79, 'os_criada' => 1],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 2,
                        'per_page' => 25,
                        'total' => 80,
                        'last_page' => 4,
                        'from' => 26,
                        'to' => 50,
                    ],
                ],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/historico?category=status&per_page=25&page=2');

        $response
            ->assertOk()
            ->assertSee('Auditoria completa da ordem de serviço')
            ->assertSee('<strong>251</strong> registros append-only', false)
            ->assertSee('Status alterado')
            ->assertSee('status anterior')
            ->assertSee('triagem')
            ->assertSee('Importado de os_status_historico #44')
            ->assertSee('Exibindo 26–50 de 80 registros encontrados.')
            ->assertSee(route('orders.show', 501), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('>2</a>', false);

        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/api/v1/orders/501/events')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['category'] ?? null) === 'status'
                && ($query['per_page'] ?? null) === '25'
                && ($query['page'] ?? null) === '2';
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function orderMapFixtures(array $orderOverrides = []): array
    {
        $order = array_merge([
            'id' => 501,
            'numero_os' => 'OS26070009',
            'status' => 'reparo_execucao',
            'status_nome' => 'Em execução do serviço',
            'status_cor' => '#4DABF7',
            'status_congela_prazo' => false,
            'status_ordem_fluxo' => 80,
            'is_encerrada' => false,
            'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha', 'telefone1' => '(11) 99999-0000'],
            'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5', 'tipo_nome' => 'Notebook', 'marca_nome' => 'Acer', 'modelo_nome' => 'Nitro 5'],
            'relato_cliente' => 'Não liga mais desde ontem.',
            'status_disponiveis' => [
                ['codigo' => 'triagem', 'nome' => 'Triagem', 'congela_prazo' => false],
                ['codigo' => 'diagnostico', 'nome' => 'Diagnóstico Técnico', 'congela_prazo' => false],
                ['codigo' => 'reparo_execucao', 'nome' => 'Em execução do serviço', 'congela_prazo' => false],
                ['codigo' => 'testes_operacionais', 'nome' => 'Testes Operacionais', 'congela_prazo' => false],
            ],
            'proximas_etapas' => [
                ['codigo' => 'testes_operacionais', 'nome' => 'Testes Operacionais', 'congela_prazo' => false, 'grupo_macro' => 'qualidade', 'ordem_fluxo' => 110],
            ],
            'historico' => [],
            'procedimentos_historico' => [],
        ], $orderOverrides);

        return array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501/events*' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => ['id' => 501, 'numero_os' => 'OS26070009'],
                    'events' => [
                        [
                            'id' => 92,
                            'category' => 'status',
                            'type' => 'status_alterado',
                            'title' => 'Status alterado',
                            'description' => 'diagnostico → reparo_execucao',
                            'data' => ['status_anterior' => 'diagnostico', 'status_novo' => 'reparo_execucao'],
                            'origin' => 'usuario',
                            'created_at' => '2026-07-16T10:00:00-03:00',
                            'user' => ['id' => 51, 'name' => 'Técnico Banco'],
                        ],
                        [
                            'id' => 91,
                            'category' => 'status',
                            'type' => 'status_alterado',
                            'title' => 'Status alterado',
                            'description' => 'triagem → diagnostico',
                            'data' => ['status_anterior' => 'triagem', 'status_novo' => 'diagnostico'],
                            'origin' => 'usuario',
                            'created_at' => '2026-07-16T09:00:00-03:00',
                            'user' => ['id' => 51, 'name' => 'Técnico Banco'],
                        ],
                    ],
                    'stats' => [],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => ['current_page' => 1, 'per_page' => 100, 'total' => 2, 'last_page' => 1],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => ['order' => $order],
                'error' => null,
                'meta' => [],
            ]),
        ]);
    }

    public function test_order_map_page_renders_flow_svg_trail_and_config(): void
    {
        Http::fake($this->orderMapFixtures());

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar', 'editar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/mapa');

        $response
            ->assertOk()
            ->assertSee('Mapa da ordem de serviço')
            ->assertSee('OS26070009')
            // SVG embed com nós endereçáveis presente na página.
            ->assertSee('data-status="triagem"', false)
            ->assertSee('data-edge="reparo_concluido:__baixa__"', false)
            // Painel de trajeto renderizado no servidor, em ordem cronológica.
            ->assertSeeInOrder(['Triagem', 'Diagnóstico Técnico', 'Em execução do serviço'])
            // Config para o JS do mapa (Js::from escapa aspas como ").
            ->assertSee('window.__DESKTOP_OS_MAP', false)
            ->assertSee('\\u0022canEditStatus\\u0022:true', false)
            // Controles de tela cheia (entrar pela toolbar, sair pelo X/Esc).
            ->assertSee('id="osMapFullscreen"', false)
            ->assertSee('id="osMapExitFullscreen"', false)
            ->assertSee('assets/js/orders-map.js', false)
            // Contexto de cliente e equipamento no painel lateral.
            ->assertSee('Cliente Alpha')
            ->assertSee('(11) 99999-0000')
            ->assertSee('Acer')
            ->assertSee('Nitro 5')
            ->assertSee('Não liga mais desde ontem.')
            // Resumo da OS + equipamento dentro da moldura do mapa (visível
            // mesmo em tela cheia, onde cabeçalho/painel lateral somem).
            ->assertSee('id="osMapLegendOs"', false)
            ->assertSee('Notebook Acer Nitro 5');

        Http::assertSent(static function ($request): bool {
            if (! str_contains($request->url(), '/api/v1/orders/501/events')) {
                return false;
            }

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['category'] ?? null) === 'status'
                && ($query['per_page'] ?? null) === '100';
        });
    }

    public function test_order_map_page_marks_closed_order_read_only(): void
    {
        Http::fake($this->orderMapFixtures([
            'status' => 'entregue_reparado_pago',
            'status_nome' => 'Entregue - Reparado e Pago',
            'is_encerrada' => true,
            'proximas_etapas' => [],
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar', 'editar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/mapa');

        $response
            ->assertOk()
            ->assertSee('OS encerrada — o mapa é somente leitura')
            ->assertSee('\\u0022isEncerrada\\u0022:true', false);
    }

    public function test_order_map_page_requires_view_permission(): void
    {
        Http::fake($this->orderMapFixtures());

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['clientes' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/mapa');

        $response->assertRedirect();
    }

    public function test_order_map_data_endpoint_returns_json_with_rendered_trail(): void
    {
        Http::fake($this->orderMapFixtures());

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar', 'editar']]),
                ['desktop_theme' => 'default']
            ))
            ->getJson('/os/501/mapa/dados');

        $response
            ->assertOk()
            ->assertJsonPath('order.status', 'reparo_execucao')
            ->assertJsonPath('canEditStatus', true)
            ->assertJsonPath('pathTruncated', false)
            ->assertJsonCount(2, 'path');

        // trailHtml é o mesmo partial usado no carregamento normal da
        // página — sem isso o JS teria que duplicar a lógica de rótulo.
        $this->assertStringContainsString('Triagem', $response->json('trailHtml'));
        $this->assertStringContainsString('Diagnóstico Técnico', $response->json('trailHtml'));
    }

    public function test_order_map_data_endpoint_requires_view_permission(): void
    {
        Http::fake($this->orderMapFixtures());

        // EnsureRoutePermission não distingue JSON de navegação normal —
        // sempre redireciona pra primeira rota permitida (mesmo com Accept:
        // json), igual ao teste da página HTML do mapa.
        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['clientes' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->getJson('/os/501/mapa/dados');

        $response->assertRedirect();
    }

    public function test_orders_status_context_exposes_deadline_freeze_flags(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'reparo_concluido',
                        'status_nome' => 'Reparo Concluído',
                        'status_cor' => '#64748b',
                        'status_congela_prazo' => true,
                        'status_ordem_fluxo' => 160,
                        'is_encerrada' => false,
                        'prioridade' => 'alta',
                        'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                        'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                        'tecnico' => ['id' => 51, 'nome' => 'Tecnico Banco'],
                        'status_disponiveis' => [
                            ['codigo' => 'aguardando_reparo', 'nome' => 'Aguardando Reparo', 'congela_prazo' => false],
                        ],
                        'proximas_etapas' => [
                            [
                                'codigo' => 'aguardando_reparo',
                                'nome' => 'Aguardando Reparo',
                                'congela_prazo' => false,
                                'grupo_macro' => 'execucao',
                                'ordem_fluxo' => 70,
                                'cor' => '#4DABF7',
                                'icone' => 'bi-arrow-counterclockwise',
                            ],
                        ],
                        'historico' => [],
                        'procedimentos_historico' => [],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession(['os' => ['visualizar']]),
                ['desktop_theme' => 'default']
            ))
            ->getJson('/os/501/status-context');

        $response->assertOk()
            ->assertJsonPath('status_congela_prazo', true)
            ->assertJsonPath('status_ordem_fluxo', 160)
            ->assertJsonPath('proximas_etapas.0.congela_prazo', false)
            ->assertJsonPath('proximas_etapas.0.ordem_fluxo', 70)
            ->assertJsonPath('proximas_etapas.0.grupo_macro', 'execucao')
            ->assertJsonPath('status_disponiveis.0.congela_prazo', false);
    }

    public function test_orders_show_page_hides_financeiro_link_without_permission(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'em_execucao',
                        'status_nome' => 'Em execução do serviço',
                        'status_cor' => '#64748b',
                        'is_encerrada' => false,
                        'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                        'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                        'fotos' => [],
                        'documentos' => [],
                        'status_disponiveis' => [],
                        'proximas_etapas' => [],
                        'orcamento' => null,
                        'financeiro_resumo' => ['titulo_id' => 63],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501');

        $response
            ->assertOk()
            ->assertDontSee('Ver lançamento financeiro');
    }

    public function test_orders_show_page_hides_financeiro_link_when_no_lancamento_linked(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'em_execucao',
                        'status_nome' => 'Em execução do serviço',
                        'status_cor' => '#64748b',
                        'is_encerrada' => false,
                        'cliente' => ['id' => 201, 'nome_razao' => 'Cliente Alpha'],
                        'equipamento' => ['id' => 301, 'resumo_tecnico' => 'Notebook Acer Nitro 5'],
                        'fotos' => [],
                        'documentos' => [],
                        'status_disponiveis' => [],
                        'proximas_etapas' => [],
                        'orcamento' => null,
                        'financeiro_resumo' => ['titulo_id' => null],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar'],
                    'financeiro' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501');

        $response
            ->assertOk()
            ->assertDontSee('Ver lançamento financeiro');
    }

    public function test_orders_edit_page_renders_same_wizard_layout_prefilled_with_order_data(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                        'status' => 'em_execucao',
                        'status_nome' => 'Em execução do serviço',
                        'prioridade' => 'alta',
                        'cliente_id' => 201,
                        'equipamento_id' => 301,
                        'tecnico' => [
                            'id' => 51,
                            'nome' => 'Tecnico Banco',
                            'email' => 'tecnico@empresa.com',
                        ],
                        'data_entrada' => '01/07/2026 09:00',
                        'data_previsao' => '2026-07-15',
                        'relato_cliente' => 'cooler do processador com mal funcionamento.',
                        'acessorios' => 'Carregador original',
                        'observacoes_internas' => 'Cliente avisado sobre orcamento.',
                        'fotos' => [
                            ['id' => 91, 'tipo_label' => 'Entrada', 'nome_arquivo' => 'foto1.jpg'],
                        ],
                        'status_disponiveis' => [
                            ['codigo' => 'em_execucao', 'nome' => 'Em execução do serviço'],
                            ['codigo' => 'testes_finais', 'nome' => 'Testes finais'],
                            ['codigo' => 'aguardando_pecas', 'nome' => 'Aguardando peças'],
                        ],
                        'orcamento' => ['id' => 777, 'numero' => 'ORC-2607-000009'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/clients/201' => Http::response([
                'status' => 'success',
                'data' => [
                    'client' => [
                        'id' => 201,
                        'nome_razao' => 'Cliente Alpha',
                        'telefone1' => '(21) 98888-1111',
                        'email' => 'alpha@example.com',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'resumo_tecnico' => '',
                        'marca_nome' => 'Acer',
                        'modelo_nome' => 'Nitro 5',
                        'numero_serie' => 'SN-12345',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 51,
                            'nome' => 'Tecnico Banco',
                            'email' => 'tecnico@empresa.com',
                            'perfil' => 'tecnico',
                            'ativo' => true,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/501/editar');

        $response
            ->assertOk()
            ->assertSee('Editar OS OS26070009')
            ->assertSee('Salvar alteracoes', false)
            ->assertSee('Cliente Alpha')
            ->assertSee('Acer / Nitro 5')
            ->assertSee('Tecnico Banco')
            ->assertSee('Em execução do serviço')
            ->assertSee('cooler do processador', false)
            ->assertSee('Carregador original')
            ->assertSee('Fotos ja anexadas', false)
            ->assertSee(asset('assets/libs/cropperjs/cropper.min.css'), false)
            ->assertSee(asset('assets/libs/cropperjs/cropper.min.js'), false)
            ->assertSee('orderPhotoCropModal', false)
            ->assertSee('data-order-photo-crop-confirm', false)
            ->assertSee('data-order-photo-crop-action="rotate-left"', false)
            ->assertSee('Cada imagem será cortada antes do envio')
            ->assertSee('Alterar status', false)
            ->assertSee('Testes finais')
            ->assertSee('Aguardando peças')
            ->assertSee(route('orders.status.update', 501), false)
            ->assertSee(route('orders.update', 501), false)
            ->assertSee(route('orders.photos.show', [501, 91]), false)
            ->assertSee('value="201"', false)
            ->assertSee('value="301"', false)
            ->assertSee('Ver orçamento', false)
            ->assertSee(route('orcamentos.show', 777), false)
            ->assertDontSee('Gerar orçamento', false)
            ->assertDontSee('Fluxo inicial');
    }

    public function test_order_photo_upload_script_replaces_original_files_with_cropped_files(): void
    {
        $script = file_get_contents(public_path('assets/js/orders-create.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString('state.photoCropQueue', $script);
        $this->assertStringContainsString('getCroppedCanvas', $script);
        $this->assertStringContainsString('commitCroppedPhoto(croppedFile', $script);
        $this->assertStringContainsString('transfer.items.add(entry.file)', $script);
        $this->assertStringContainsString('maxPhotoUploadBytes', $script);
        $this->assertStringContainsString('initAccessoryPresets', $script);
        $this->assertStringContainsString('accessoriesField', $script);
    }

    public function test_orders_closure_receipt_validator_reads_account_from_current_row(): void
    {
        $script = file_get_contents(public_path('assets/js/orders-closure.js'));

        $this->assertIsString($script);

        $validatorStart = strpos($script, 'const validateReceiptRow = (row) => {');
        $validatorEnd = strpos($script, 'const validateFinancialStep = () => {');

        $this->assertNotFalse($validatorStart);
        $this->assertNotFalse($validatorEnd);

        $validator = substr($script, $validatorStart, $validatorEnd - $validatorStart);

        $this->assertStringContainsString(
            'const accountId = row.querySelector(\'[data-field="conta_financeira_id"]\')?.value || \'\';',
            $validator
        );
        $this->assertStringContainsString("financialAccounts.length > 0 && accountId === ''", $validator);
    }

    public function test_collapsed_sidebar_closes_implicitly_open_group_popovers(): void
    {
        $script = file_get_contents(public_path('assets/js/desktop.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("if (sidebar.classList.contains('is-collapsed')) {\n            closeCollapsedSidebarPopovers();", $script);
        $this->assertStringContainsString("const collapsed = sidebar.classList.toggle('is-collapsed');", $script);
        $this->assertStringContainsString("if (collapsed) {\n                closeCollapsedSidebarPopovers();", $script);
        $this->assertStringContainsString("event.key !== 'Escape'", $script);
        $this->assertStringContainsString('toggle.focus({ preventScroll: true });', $script);
    }

    public function test_orders_closure_page_disables_receber_saldo_total_when_no_open_balance(): void
    {
        Http::preventStrayRequests();

        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/orders/3614' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 3614,
                        'numero_os' => 'OS26070002',
                        'status' => 'triagem',
                        'status_nome' => 'Triagem',
                        'estado_fluxo' => 'em_atendimento',
                        'valor_final' => 0,
                        'cliente_id' => 1168,
                        'cliente_nome' => 'Cliente Sem Saldo',
                        'equipamento_id' => 3603,
                        'equipamento_nome' => 'Notebook Zero',
                        'equipamento_tipo_nome' => 'Notebook',
                        'equipamento_numero_serie' => 'SN-0000',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/orders/3614/closure' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 3614,
                        'numero_os' => 'OS26070002',
                        'status' => 'triagem',
                        'estado_fluxo' => 'em_atendimento',
                        'valor_final' => 0,
                    ],
                    'cliente_telefone' => '',
                    'opcoes_encerramento' => [
                        ['codigo' => 'reparo_concluido', 'nome' => 'Reparo Concluído'],
                        ['codigo' => 'devolvido_sem_reparo', 'nome' => 'Devolvido sem reparo'],
                    ],
                    'financeiro' => [
                        'valor_titulo' => 0,
                        'valor_movimentado' => 0,
                        'valor_aberto' => 0,
                        'total_movimentos' => 0,
                        'status_resolvido' => null,
                        'percentual_quitado' => 0,
                    ],
                    'custo_summary' => [
                        'pecas' => 0,
                        'servicos' => 0,
                        'total' => 0,
                    ],
                    'retorno_padrao' => now()->addDays(180)->toDateString(),
                    'cartao' => [
                        'operadoras' => [],
                        'bandeiras' => [],
                        'taxas' => [],
                    ],
                    'status_pagamento_pendente' => [
                        'codigo' => 'entregue_pagamento_pendente',
                        'nome' => 'Entregue - Pend?ncia Financeira',
                    ],
                    'status_sem_reparo' => ['devolvido_sem_reparo', 'descartado'],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]));

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'dashboard' => ['visualizar'],
                    'os' => ['visualizar', 'editar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/os/3614/baixa');

        $response
            ->assertOk()
            ->assertSee('Recebimentos e adiantamentos');

        $this->assertMatchesRegularExpression('/data-action="receber-saldo-total"[^>]*disabled/', $response->getContent());

        Http::allowStrayRequests();
    }

    public function test_orders_update_with_photos_uses_multipart_and_redirects_to_detail(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/orders/501' => Http::response([
                'status' => 'success',
                'data' => [
                    'order' => [
                        'id' => 501,
                        'numero_os' => 'OS26070009',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'os' => ['visualizar', 'editar'],
            ]))
            ->patch('/os/501', [
                'cliente_id' => 201,
                'equipamento_id' => 301,
                'relato_cliente' => 'Cooler trocado, aguardando testes finais.',
                'acessorios' => 'Fonte ATX, Cabo de força',
                'prioridade' => 'alta',
                'data_previsao' => '2026-07-20',
                'observacoes_internas' => 'Peca chegou no prazo.',
                'fotos' => [
                    UploadedFile::fake()->image('reparo.jpg'),
                ],
            ]);

        $response
            ->assertRedirect(route('orders.show', 501))
            ->assertSessionHas('success');

        Http::assertSent(static function ($request): bool {
            $contentType = strtolower(implode(';', $request->header('Content-Type')));
            $body = $request->body();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/orders/501'
                && str_contains($contentType, 'multipart/form-data')
                && str_contains($body, 'name="_method"')
                && str_contains($body, "\r\n\r\nPATCH\r\n")
                && str_contains($body, 'name="acessorios"')
                && str_contains($body, 'Fonte ATX, Cabo de força')
                && str_contains($body, 'name="fotos[]"')
                && str_contains($body, 'reparo.jpg');
        });
    }

    public function test_equipment_index_renders_operational_hub_with_actions_and_counts(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'cliente_id' => 201,
                            'cliente_nome' => 'Cliente Alpha',
                            'resumo_tecnico' => 'Notebook Acer Nitro',
                            'numero_serie' => 'SN-12345',
                            'imei' => '',
                            'desktop_modalidade' => 'desktop',
                            'status_operacional' => 'Ativo',
                            'orders_count' => 8,
                            'primary_photo_id' => 91,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'equipamentos' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                    'clientes' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/equipamentos');

        $response
            ->assertOk()
            ->assertSee('Notebook Acer Nitro')
            ->assertSee('8 OS')
            ->assertSee('Cliente Alpha')
            ->assertSee('S/N SN-12345')
            ->assertSee(route('equipments.photos.show', [301, 91]), false)
            ->assertSee('Ações')
            ->assertSee('Abrir cliente')
            ->assertSee('Ver OS')
            ->assertSee('Nova OS');
    }

    public function test_equipment_detail_page_renders_client_and_related_orders(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/301' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 301,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                            'cpf_cnpj' => '11.111.111/0001-11',
                            'telefone1' => '(21) 98888-1111',
                            'email' => 'alpha@example.com',
                            'cidade' => 'Rio de Janeiro',
                            'uf' => 'RJ',
                        ],
                        'tipo_id' => 1,
                        'tipo_nome' => 'Notebook',
                        'marca_id' => 1,
                        'marca_nome' => 'Acer',
                        'modelo_id' => 1,
                        'modelo_nome' => 'Nitro 5',
                        'cor' => 'Preto',
                        'numero_serie' => 'SN-12345',
                        'imei' => '',
                        'observacoes' => 'Observação técnica',
                        'desktop_modalidade' => 'desktop',
                        'resumo_tecnico' => '',
                        'status_operacional' => 'Ativo',
                        'status' => 'ativo',
                        'orders_count' => 8,
                        'primary_photo_id' => 91,
                        'photos' => [
                            [
                                'id' => 91,
                                'is_principal' => true,
                                'url' => 'http://127.0.0.1:8000/api/v1/equipments/301/photos/91',
                            ],
                        ],
                        'created_at' => '12/01/2026 10:15',
                        'updated_at' => '12/01/2026 10:16',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/orders*' => Http::response([
                'status' => 'success',
                'data' => [
                    'orders' => [
                        [
                            'id' => 1001,
                            'numero_os' => 'OS1001',
                            'numero_os_legado' => '26050001',
                            'cliente_nome' => 'Cliente Alpha',
                            'status_nome' => 'Em execução',
                            'status_cor' => '#6f5afc',
                            'data_previsao' => '22/05/2026',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 5,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge(
                $this->desktopSession([
                    'equipamentos' => ['visualizar'],
                    'os' => ['visualizar', 'criar'],
                    'clientes' => ['visualizar'],
                ]),
                ['desktop_theme' => 'default']
            ))
            ->get('/equipamentos/301');

        $response
            ->assertOk()
            ->assertSee('Notebook · Acer · Nitro 5')
            ->assertDontSee('Sem resumo tecnico')
            ->assertSee('Foto principal do equipamento')
            ->assertSee(route('equipments.photos.show', [301, 91]), false)
            ->assertSee('data-photo-viewer-trigger', false)
            ->assertSee('Cliente vinculado')
            ->assertSee('Cliente Alpha')
            ->assertSee('Ordens de serviço vinculadas')
            ->assertSee('OS1001')
            ->assertSee('Nova OS')
            ->assertSee('Mais ações')
            ->assertSee('data-new-order-action="equipment"', false)
            ->assertSee(route('orders.create', ['cliente_id' => 201, 'equipamento_id' => 301]))
            ->assertSee('Abrir cliente');

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/v1/orders') && str_contains($request->url(), 'equipment_id=301');
        });
    }

    public function test_equipment_create_page_renders_tabs_quick_actions_and_collector_flow(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/form-data' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [
                            [
                                'id' => 201,
                                'nome_razao' => 'Cliente Alpha',
                                'cpf_cnpj' => '11.111.111/0001-11',
                                'telefone1' => '(21) 98888-1111',
                                'nome_contato' => 'Contato Alpha',
                                'telefone_contato' => '(21) 97777-0000',
                                'email' => 'alpha@example.com',
                            ],
                            [
                                'id' => 202,
                                'nome_razao' => 'Cliente Beta',
                                'cpf_cnpj' => '22.222.222/0001-22',
                                'telefone1' => '(21) 96666-2222',
                                'telefone_contato' => '',
                                'email' => 'beta@example.com',
                            ],
                        ],
                        'types' => [
                            ['id' => 1, 'nome' => 'Desktop', 'slug' => 'desktop', 'family' => 'desktop'],
                            ['id' => 2, 'nome' => 'Notebook', 'slug' => 'notebook', 'family' => 'notebook'],
                        ],
                        'brands' => [
                            ['id' => 2, 'nome' => 'Montado'],
                            ['id' => 3, 'nome' => 'Dell'],
                        ],
                        'models' => [
                            ['id' => 2, 'marca_id' => 2, 'nome' => 'Desktop montado'],
                            ['id' => 3, 'marca_id' => 3, 'nome' => 'Inspiron 15'],
                        ],
                        'desktop_defaults' => [
                            'marca_id' => 2,
                            'modelo_id' => 2,
                            'marca_nome' => 'Montado',
                            'modelo_nome' => 'Desktop montado',
                        ],
                        'password_modes' => [
                            ['value' => 'desenho', 'label' => 'Desenho'],
                            ['value' => 'texto', 'label' => 'Texto'],
                        ],
                        'max_photos' => 4,
                        'collector' => [
                            'pairing_ttl_minutes' => 30,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
                'os' => ['visualizar', 'criar'],
            ]))
            ->get('/equipamentos/novo?cliente_id=201&cliente_busca_label=Cliente%20Alpha');

        $html = $response->getContent();
        $quickClientModalPosition = strpos($html, 'id="quickClientModal"');
        $equipmentScriptPosition = strpos($html, 'assets/js/equipments-create.js');
        $clientsScriptPosition = strpos($html, 'assets/js/clients-form.js');

        $response
            ->assertOk()
            ->assertSee('Novo equipamento')
            ->assertSee('Informa')
            ->assertSee('Ajuda')
            ->assertSee('Fotos *')
            ->assertSee('Adicionar da galeria')
            ->assertSee('A foto principal e obrigatoria no cadastro inicial', false)
            ->assertSee('select2.min.js', false)
            ->assertSee('select2-bootstrap-5-theme.min.css', false)
            ->assertSee('id="equipmentClientSelect"', false)
            ->assertSee('id="equipmentBrand" class="form-select" disabled', false)
            ->assertSee('id="equipmentModel" class="form-select" disabled', false)
            ->assertSee('id="quickModelBrand" class="form-select" disabled', false)
            ->assertSee('Selecione o tipo primeiro...', false)
            ->assertSee('Cliente Alpha', false)
            ->assertSee('Contato Alpha', false)
            ->assertSee('alpha@example.com', false)
            ->assertSee('id="equipmentCollectorCard" aria-hidden="true"', false)
            ->assertSee('window.__EQUIPMENT_CREATE', false)
            ->assertSee('catalog_relations', false)
            ->assertSee('desktopPhotoViewerModal', false)
            ->assertSee('id="equipmentClientLabel" value="Cliente Alpha"', false)
            ->assertSee('value="201" selected', false)
            ->assertDontSee('clientsSearch', false);

        $response->assertSeeInOrder([
            'Cliente *',
            'Tipo *',
            'Marca',
            'Modelo',
            'Senha de acesso',
            'Estado f',
            'Observa',
        ]);
        $response
            ->assertDontSee('id="equipmentAccessories"', false)
            ->assertDontSee('name="acessorios"', false);

        /*
            ->assertSee('Cadastro rÃ¡pido de cliente')
            ->assertSee('clients-form.js', false)
            ->assertSee('quickClient', false)
            ->assertSee('quickBrand', false)
            ->assertSee('createPairing', false);

        $this->assertNotFalse($quickClientModalPosition);
        $this->assertNotFalse($equipmentScriptPosition);
        $this->assertNotFalse($clientsScriptPosition);
        $this->assertTrue($quickClientModalPosition < $equipmentScriptPosition);
        $this->assertTrue($quickClientModalPosition < $clientsScriptPosition);
    }

        */

        $response
            ->assertSee('clients-form.js', false)
            ->assertSee('id="quickClientModal"', false)
            ->assertSee('id="quickClientForm"', false)
            ->assertSee('id="quickClientErrors"', false)
            ->assertSee('quickClient', false)
            ->assertSee('quickBrand', false)
            ->assertSee('createPairing', false);
    }

    public function test_equipment_create_embedded_mode_omits_desktop_chrome_and_keeps_form_visible(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/form-data' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'clients' => [
                            [
                                'id' => 201,
                                'nome_razao' => 'Cliente Alpha',
                                'cpf_cnpj' => '11.111.111/0001-11',
                                'telefone1' => '(21) 98888-1111',
                                'nome_contato' => 'Contato Alpha',
                                'telefone_contato' => '(21) 97777-0000',
                                'email' => 'alpha@example.com',
                            ],
                        ],
                        'types' => [
                            ['id' => 1, 'nome' => 'Desktop', 'slug' => 'desktop', 'family' => 'desktop'],
                        ],
                        'brands' => [
                            ['id' => 2, 'nome' => 'Montado'],
                        ],
                        'models' => [
                            ['id' => 2, 'marca_id' => 2, 'nome' => 'Desktop montado'],
                        ],
                        'desktop_defaults' => [
                            'marca_id' => 2,
                            'modelo_id' => 2,
                            'marca_nome' => 'Montado',
                            'modelo_nome' => 'Desktop montado',
                        ],
                        'password_modes' => [
                            ['value' => 'desenho', 'label' => 'Desenho'],
                            ['value' => 'texto', 'label' => 'Texto'],
                        ],
                        'max_photos' => 4,
                        'collector' => [
                            'pairing_ttl_minutes' => 30,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
                'os' => ['visualizar', 'criar'],
            ]))
            ->get('/equipamentos/novo?embedded=1&cliente_id=201&cliente_busca_label=Cliente%20Alpha');

        $response
            ->assertOk()
            ->assertSee('Novo equipamento')
            ->assertSee('Cadastro operacional do equipamento')
            ->assertSee('Cliente Alpha', false)
            ->assertDontSee('desktop-sidebar', false)
            ->assertDontSee('desktop-topbar', false)
            ->assertDontSee('desktop-system-footer', false)
            ->assertDontSee('Ajuda', false)
            ->assertDontSee('Voltar', false)
            ->assertSee('desktop-body-embedded', false)
            ->assertSee('data-equipment-embedded-cancel', false)
            ->assertSee('id="equipmentClientLabel" value="Cliente Alpha"', false);
    }

    public function test_equipment_create_page_shows_local_collector_for_desktop_family_types(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/form-data' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'types' => [
                            ['id' => 1, 'nome' => 'Desktop', 'slug' => 'desktop', 'family' => 'desktop'],
                            ['id' => 2, 'nome' => 'Notebook', 'slug' => 'notebook', 'family' => 'notebook'],
                            ['id' => 3, 'nome' => 'Celular', 'slug' => 'celular', 'family' => 'mobile'],
                        ],
                        'brands' => [
                            ['id' => 2, 'nome' => 'Montado'],
                            ['id' => 3, 'nome' => 'Dell'],
                        ],
                        'models' => [
                            ['id' => 2, 'marca_id' => 2, 'nome' => 'Desktop montado'],
                            ['id' => 3, 'marca_id' => 3, 'nome' => 'Inspiron 15'],
                        ],
                        'desktop_defaults' => [
                            'marca_id' => 2,
                            'modelo_id' => 2,
                            'marca_nome' => 'Montado',
                            'modelo_nome' => 'Desktop montado',
                        ],
                        'password_modes' => [
                            ['value' => 'desenho', 'label' => 'Desenho'],
                            ['value' => 'texto', 'label' => 'Texto'],
                        ],
                        'max_photos' => 4,
                        'collector' => [
                            'pairing_ttl_minutes' => 30,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
                'os' => ['visualizar', 'criar'],
            ]), [
                '_old_input' => [
                    'tipo_id' => 1,
                ],
            ]))
            ->get('/equipamentos/novo');

        $response
            ->assertOk()
            ->assertSee('id="equipmentCollectorCard"', false)
            ->assertSee('id="equipmentCollectorCard" aria-hidden="false"', false);
    }

    public function test_equipment_create_page_locks_modalidade_to_oem_for_notebook_type(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/form-data' => Http::response([
                'status' => 'success',
                'data' => [
                    'form' => [
                        'types' => [
                            ['id' => 1, 'nome' => 'Desktop', 'slug' => 'desktop', 'family' => 'desktop'],
                            ['id' => 2, 'nome' => 'Notebook', 'slug' => 'notebook', 'family' => 'notebook'],
                        ],
                        'brands' => [
                            ['id' => 2, 'nome' => 'Montado'],
                            ['id' => 3, 'nome' => 'Dell'],
                        ],
                        'models' => [
                            ['id' => 2, 'marca_id' => 2, 'nome' => 'Desktop montado'],
                            ['id' => 3, 'marca_id' => 3, 'nome' => 'Inspiron 15'],
                        ],
                        'desktop_defaults' => [
                            'marca_id' => 2,
                            'modelo_id' => 2,
                            'marca_nome' => 'Montado',
                            'modelo_nome' => 'Desktop montado',
                        ],
                        'password_modes' => [
                            ['value' => 'desenho', 'label' => 'Desenho'],
                            ['value' => 'texto', 'label' => 'Texto'],
                        ],
                        'max_photos' => 4,
                        'collector' => [
                            'pairing_ttl_minutes' => 30,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession(array_merge($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
                'os' => ['visualizar', 'criar'],
            ]), [
                '_old_input' => [
                    'tipo_id' => 2,
                ],
            ]))
            ->get('/equipamentos/novo');

        $response
            ->assertOk()
            ->assertSee('id="equipmentDesktopMode" class="form-select" disabled', false)
            ->assertSee('Notebook é sempre cadastrado como OEM / fabricante.')
            ->assertSeeInOrder([
                '<option value="oem"',
                'selected',
                'OEM / fabricante',
            ], false);
    }

    public function test_equipment_quick_brand_route_forwards_selected_type_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/brands' => Http::response([
                'status' => 'success',
                'data' => [
                    'brand' => [
                        'id' => 51,
                        'tipo_id' => 4,
                        'nome' => 'Epson',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
            ]))
            ->postJson('/equipamentos/marcas/rapido', [
                'tipo_id' => 4,
                'nome' => 'Epson',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('brand.tipo_id', 4)
            ->assertJsonPath('brand.nome', 'Epson');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/equipments/brands'
                && $request['tipo_id'] === 4
                && $request['nome'] === 'Epson';
        });
    }

    public function test_equipment_quick_model_route_forwards_selected_type_and_brand_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments/models' => Http::response([
                'status' => 'success',
                'data' => [
                    'model' => [
                        'id' => 81,
                        'tipo_id' => 4,
                        'marca_id' => 51,
                        'nome' => 'L3250',
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
            ]))
            ->postJson('/equipamentos/modelos/rapido', [
                'tipo_id' => 4,
                'marca_id' => 51,
                'nome' => 'L3250',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('model.tipo_id', 4)
            ->assertJsonPath('model.marca_id', 51)
            ->assertJsonPath('model.nome', 'L3250');

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/equipments/models'
                && $request['tipo_id'] === 4
                && $request['marca_id'] === 51
                && $request['nome'] === 'L3250';
        });
    }

    public function test_equipment_create_submission_redirects_to_detail_after_backend_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 401,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                        ],
                        'tipo_id' => 1,
                        'tipo_nome' => 'Desktop',
                        'marca_id' => 2,
                        'marca_nome' => 'Montado',
                        'modelo_id' => 2,
                        'modelo_nome' => 'Desktop montado',
                        'numero_serie' => 'SN-NEW-001',
                        'desktop_modalidade' => 'montado',
                        'status_operacional' => 'ativo',
                        'status' => 'ativo',
                        'orders_count' => 0,
                        'primary_photo_id' => 77,
                        'photos' => [
                            ['id' => 77, 'is_principal' => true],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
            ]))
            ->post('/equipamentos', [
                'cliente_id' => 201,
                'cliente_busca_label' => 'Cliente Alpha',
                'tipo_id' => 1,
                'numero_serie_visual' => 'SN-NEW-001',
                'desktop_modalidade' => 'montado',
                'foto_principal_index' => 0,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento-principal.jpg'),
                ],
            ]);

        $response
            ->assertRedirect(route('equipments.show', 401))
            ->assertSessionHas('success', 'Equipamento cadastrado com sucesso.');

        Http::assertSent(static function ($request): bool {
            $contentType = strtolower(implode(';', $request->header('Content-Type')));
            $body = $request->body();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/equipments'
                && str_contains($contentType, 'multipart/form-data')
                && str_contains($body, 'name="cliente_id"')
                && str_contains($body, "\r\n\r\n201\r\n")
                && str_contains($body, 'name="tipo_id"')
                && str_contains($body, "\r\n\r\n1\r\n")
                && str_contains($body, 'name="numero_serie"')
                && str_contains($body, 'SN-NEW-001')
                && str_contains($body, 'name="desktop_modalidade"')
                && str_contains($body, 'montado');
        });
    }

    public function test_equipment_create_submission_requires_photo_before_calling_backend(): void
    {
        Http::fake();

        $response = $this
            ->from('/equipamentos/novo')
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
            ]))
            ->post('/equipamentos', [
                'cliente_id' => 201,
                'cliente_busca_label' => 'Cliente Alpha',
                'tipo_id' => 1,
                'numero_serie_visual' => 'SN-SEM-FOTO-001',
                'desktop_modalidade' => 'montado',
                'foto_principal_index' => 0,
            ]);

        $response
            ->assertRedirect('/equipamentos/novo')
            ->assertSessionHasErrors(['fotos']);

        Http::assertNothingSent();
    }

    public function test_equipment_create_submission_with_photo_uses_multipart_without_json_content_type(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 402,
                        'cliente_id' => 201,
                        'tipo_id' => 1,
                        'numero_serie' => 'SN-FOTO-001',
                        'desktop_modalidade' => 'montado',
                        'photos' => [
                            ['id' => 1],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
            ]))
            ->post('/equipamentos', [
                'cliente_id' => 201,
                'cliente_busca_label' => 'Cliente Alpha',
                'tipo_id' => 1,
                'numero_serie_visual' => 'SN-FOTO-001',
                'desktop_modalidade' => 'montado',
                'foto_principal_index' => 0,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento.jpg'),
                ],
            ]);

        $response
            ->assertRedirect(route('equipments.show', 402))
            ->assertSessionHas('success', 'Equipamento cadastrado com sucesso.');

        Http::assertSent(static function ($request): bool {
            $contentType = strtolower(implode(';', $request->header('Content-Type')));
            $body = $request->body();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/equipments'
                && str_contains($contentType, 'multipart/form-data')
                && ! str_contains($contentType, 'application/json')
                && str_contains($body, 'name="cliente_id"')
                && str_contains($body, "\r\n\r\n201\r\n")
                && str_contains($body, 'name="tipo_id"')
                && str_contains($body, "\r\n\r\n1\r\n")
                && str_contains($body, 'name="numero_serie"')
                && str_contains($body, 'SN-FOTO-001');
        });
    }

    public function test_equipment_create_embedded_submission_returns_json_payload_for_os_modal(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/equipments' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipment' => [
                        'id' => 403,
                        'cliente_id' => 201,
                        'client' => [
                            'id' => 201,
                            'nome_razao' => 'Cliente Alpha',
                            'cpf_cnpj' => '00.000.000/0001-00',
                            'telefone1' => '(11) 99999-0000',
                        ],
                        'tipo_id' => 1,
                        'tipo_nome' => 'Desktop',
                        'marca_id' => 2,
                        'marca_nome' => 'Acer',
                        'modelo_id' => 7,
                        'modelo_nome' => 'Nitro 5',
                        'numero_serie' => 'SN-EMBED-001',
                        'desktop_modalidade' => 'montado',
                        'status_operacional' => 'ativo',
                        'status' => 'ativo',
                        'orders_count' => 0,
                        'primary_photo_id' => 91,
                        'photos' => [
                            ['id' => 91, 'is_principal' => true],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar', 'criar'],
                'clientes' => ['visualizar', 'criar'],
            ]))
            ->post('/equipamentos?embedded=1', [
                'cliente_id' => 201,
                'cliente_busca_label' => 'Cliente Alpha',
                'tipo_id' => 1,
                'numero_serie_visual' => 'SN-EMBED-001',
                'desktop_modalidade' => 'montado',
                'foto_principal_index' => 0,
                'fotos' => [
                    UploadedFile::fake()->image('equipamento-embed.jpg'),
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Equipamento cadastrado com sucesso.')
            ->assertJsonPath('equipment.id', 403)
            ->assertJsonPath('equipment.label', 'Acer / Nitro 5')
            ->assertJsonPath('equipment.summary', '')
            ->assertJsonPath('equipment.brandName', 'Acer')
            ->assertJsonPath('equipment.modelName', 'Nitro 5')
            ->assertJsonPath('equipment.serial', 'SN-EMBED-001')
            ->assertJsonPath('equipment.clientId', 201)
            ->assertJsonPath('equipment.clientName', 'Cliente Alpha')
            ->assertJsonPath('equipment.photoUrl', route('equipments.photos.show', [403, 91]));

        Http::assertSent(static function ($request): bool {
            $contentType = strtolower(implode(';', $request->header('Content-Type')));
            $body = $request->body();

            return $request->url() === 'http://127.0.0.1:8000/api/v1/equipments'
                && str_contains($contentType, 'multipart/form-data')
                && str_contains($body, 'name="cliente_id"')
                && str_contains($body, "\r\n\r\n201\r\n")
                && str_contains($body, 'name="tipo_id"')
                && str_contains($body, "\r\n\r\n1\r\n")
                && str_contains($body, 'name="numero_serie"')
                && str_contains($body, 'SN-EMBED-001');
        });
    }

    public function test_users_index_renders_records_from_backend_services(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/users*' => Http::response([
                'status' => 'success',
                'data' => [
                    'users' => [
                        [
                            'id' => 7,
                            'nome' => 'Ana Gestora',
                            'email' => 'ana@empresa.com',
                            'telefone' => '(22) 99999-1111',
                            'perfil' => 'gerente',
                            'grupo_id' => 4,
                            'group' => [
                                'id' => 4,
                                'nome' => 'Gerência',
                            ],
                            'foto' => '',
                            'ativo' => true,
                            'ultimo_acesso' => '2026-06-22T09:55:00-03:00',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 1,
                        'last_page' => 1,
                        'from' => 1,
                        'to' => 1,
                    ],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/groups' => Http::response([
                'status' => 'success',
                'data' => [
                    'groups' => [
                        [
                            'id' => 4,
                            'nome' => 'Gerência',
                            'descricao' => 'Acesso de gestão',
                            'sistema' => false,
                            'users_count' => 1,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'usuarios' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/usuarios');

        $response
            ->assertOk()
            ->assertSee('Ana Gestora')
            ->assertSee('Gerência')
            ->assertSee('Novo usuário');
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
     * @return array<string, mixed>
     */
    /**
     * @return array<string, Response>
     */
    private function notificationsFixture(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
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
            ]),
        ];
    }

    private function dashboardSummaryFixture(array $overrides = []): array
    {
        return array_replace_recursive([
            'status' => 'success',
            'data' => [
                'access' => [
                    'profile' => 'gerente',
                    'is_technician' => false,
                    'has_financial_access' => true,
                ],
                'stats' => [
                    'orders' => 69,
                    'clients' => 1302,
                    'equipments' => 3573,
                    'users' => 12,
                    'groups' => 4,
                    'total_os' => 3580,
                    'equipamento_entregue_total' => 2160,
                    'equipamento_entregue_mes_atual' => 18,
                    'faturamento_mes' => 660.0,
                    'comissao_acumulada' => 0,
                ],
                'hero_card' => [
                    'type' => 'financial',
                    'label' => 'Faturamento mês',
                    'value' => 660.0,
                    'value_type' => 'money',
                    'meta' => 'Baseado na movimentação operacional do mês.',
                    'icon' => 'bi-currency-dollar',
                    'accent' => '#16a34a',
                    'action_label' => 'Ajuda do painel',
                    'action_url' => null,
                ],
                'context_card' => [
                    'type' => 'financial',
                    'title' => 'Resumo financeiro',
                    'subtitle' => 'Comparativo operacional do mês corrente.',
                    'chart' => [
                        'labels' => ['Receitas', 'Despesas', 'Resultado caixa', 'Pendentes'],
                        'values' => [660, 120, 540, 310],
                    ],
                    'legend' => [
                        ['label' => 'Receitas', 'color' => '#16a34a'],
                        ['label' => 'Despesas', 'color' => '#ef4444'],
                        ['label' => 'Resultado caixa', 'color' => '#6366f1'],
                        ['label' => 'Pendentes', 'color' => '#f59e0b'],
                    ],
                ],
                'charts' => [
                    'monthly' => [
                        'year' => 2026,
                        'labels' => ['Jan', 'Fev', 'Mar'],
                        'points' => [
                            ['mes' => 1, 'label' => 'Jan', 'total' => 2, 'entregues_reparadas' => 1],
                            ['mes' => 2, 'label' => 'Fev', 'total' => 0, 'entregues_reparadas' => 0],
                            ['mes' => 3, 'label' => 'Mar', 'total' => 0, 'entregues_reparadas' => 0],
                        ],
                        'series' => [
                            [
                                'key' => 'abertas',
                                'label' => 'OS abertas',
                                'color' => '#6f5afc',
                                'backgroundColor' => 'rgba(111, 90, 252, 0.18)',
                                'data' => [2, 0, 0],
                            ],
                            [
                                'key' => 'entregues_reparadas',
                                'label' => 'OS entregues reparadas',
                                'color' => '#16a34a',
                                'backgroundColor' => 'rgba(22, 163, 74, 0.18)',
                                'data' => [1, 0, 0],
                            ],
                        ],
                    ],
                    'status' => [
                        'total' => 1,
                        'labels' => ['Triagem'],
                        'series' => [
                            [
                                'key' => 'status',
                                'label' => 'OS em aberto',
                                'data' => [1],
                                'backgroundColor' => ['#6f5afc'],
                            ],
                        ],
                        'items' => [
                            [
                                'codigo' => 'triagem',
                                'nome' => 'Triagem',
                                'cor' => '#6f5afc',
                                'grupo_macro' => 'recepcao',
                                'total' => 1,
                            ],
                        ],
                    ],
                    'equipment_types' => [
                        'period' => [
                            'mes' => 1,
                            'ano' => 2026,
                            'mes_label' => 'Janeiro',
                            'periodo_label' => 'Janeiro/2026',
                            'years' => [2026, 2025],
                        ],
                        'labels' => ['Desktop'],
                        'series' => [
                            [
                                'key' => 'equipamentos',
                                'label' => 'OS por tipo',
                                'data' => [8],
                                'backgroundColor' => ['#3b82f6'],
                            ],
                        ],
                        'items' => [
                            [
                                'tipo_nome' => 'Desktop',
                                'total' => 8,
                                'equipamentos_unicos' => 8,
                            ],
                        ],
                    ],
                    'financial' => [
                        'receitas' => 660.0,
                        'despesas' => 120.0,
                        'resultado_caixa' => 540.0,
                        'pendentes' => 310.0,
                        'month' => 1,
                        'year' => 2026,
                        'delivered_current_month_count' => 1,
                        'has_access' => true,
                    ],
                    'technician' => [
                        'labels' => ['Técnico Campo'],
                        'values' => [4],
                        'highlight_id' => 7,
                        'highlight_name' => 'Técnico Campo',
                        'highlight_total' => 4,
                        'commission_total' => 0.0,
                        'month' => 1,
                        'year' => 2026,
                    ],
                ],
                'filters' => [
                    'year' => 2026,
                    'years' => [2026, 2025],
                    'equipment_month' => 1,
                    'equipment_year' => 2026,
                    'equipment_years' => [2026, 2025],
                    'months' => [
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
                    ],
                ],
                'recent_orders' => [
                    [
                        'id' => 1002,
                        'numero_os' => 'OS26010002',
                        'cliente_nome' => 'Ana Comércio LTDA',
                        'equipamento_resumo_tecnico' => 'Notebook Acer Nitro',
                        'status_nome' => 'Entregue Reparo',
                        'status_cor' => '#64748b',
                        'dias_em_aberto' => 0,
                        'data_label' => '12/01/2026',
                    ],
                ],
                'recent_clients' => [
                    [
                        'id' => 201,
                        'nome_razao' => 'Ana Comércio LTDA',
                        'email' => 'ana@empresa.com',
                    ],
                ],
                'recent_equipments' => [
                    [
                        'id' => 301,
                        'resumo_tecnico' => 'Notebook Acer Nitro',
                    ],
                ],
                'low_stock' => [],
            ],
            'error' => null,
            'meta' => [],
        ], $overrides);
    }

    public function test_pending_signature_requires_document_review_page_before_signing(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/document-signatures/pending' => Http::response([
                'status' => 'success',
                'data' => [
                    'requests' => [[
                        'id' => 73,
                        'order_id' => 501,
                        'order_number' => 'OS26070009',
                        'document_type' => 'abertura',
                        'requested_by' => 'Atendente',
                        'responsible_user_id' => 99,
                        'responsible_user' => 'Usuário de Teste',
                        'responsible_email' => 'usuario@teste.local',
                    ]],
                ],
                'error' => null,
                'meta' => [],
            ]),
        ]));

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/documentos/assinaturas/73/revisar');

        $response
            ->assertOk()
            ->assertSee('Visualize e analise antes de assinar')
            ->assertSee('Prévia completa do documento')
            ->assertSee(route('document-signatures.preview', 73), false)
            ->assertSee('name="review_confirmed"', false)
            ->assertSee('data-review-confirmation', false)
            ->assertSee('Assinar e emitir documento');
    }

    public function test_pending_signature_preview_is_proxied_as_private_pdf(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
            'http://127.0.0.1:8000/api/v1/document-signatures/73/preview' => Http::response(
                '%PDF-1.4 preview',
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="preview.pdf"',
                    'Cache-Control' => 'private, no-store, max-age=0',
                ]
            ),
        ]));

        $response = $this
            ->withSession($this->desktopSession(['os' => ['visualizar']]))
            ->get('/documentos/assinaturas/73/previa');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertSee('%PDF-1.4 preview', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function desktopSession(array $permissions, ?int $syncedAt = null): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => $syncedAt ?? time(),
                'user' => $this->fakeUser([
                    'permissions' => $permissions,
                    'modules' => array_keys($permissions),
                ]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
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
