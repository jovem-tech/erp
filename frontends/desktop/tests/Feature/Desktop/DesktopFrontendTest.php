<?php

namespace Tests\Feature\Desktop;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DesktopFrontendTest extends TestCase
{
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

    public function test_dashboard_index_renders_shell_before_summary_hydration(): void
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
            ->get('/dashboard?ano=2026&equip_mes=1&equip_ano=2026');

        $response
            ->assertOk()
            ->assertSee('window.__DESKTOP_DASHBOARD = {"dataUrl":"', false)
            ->assertSee(route('dashboard.data'), false)
            ->assertSee('Janeiro')
            ->assertSee('dashboardYear')
            ->assertSee('dashboardEquipmentMonth')
            ->assertSee('dashboardEquipmentYear');

        Http::assertSentCount(1);
    }

    public function test_commercial_people_menu_groups_clients_suppliers_and_technical_team(): void
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
                'clientes' => ['visualizar'],
                'fornecedores' => ['visualizar'],
                'funcionarios' => ['visualizar'],
            ]))
            ->get('/fornecedores');

        $response
            ->assertOk()
            ->assertSee('Pessoas')
            ->assertSee('Clientes')
            ->assertSee('Fornecedores')
            ->assertSee('Equipe Técnica')
            ->assertSee('Estrutura em andamento');
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

            throw new \RuntimeException('Unexpected HTTP request: ' . $request->url());
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

            throw new \RuntimeException('Unexpected HTTP request: ' . $request->url());
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

    public function test_orders_index_starts_with_collapsed_sidebar_for_workspace_wide_table(): void
    {
        Http::fake(array_merge($this->notificationsFixture(), [
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
                'os' => ['visualizar'],
            ]))
            ->get('/os');

        $response
            ->assertOk()
            ->assertSee('Filtros avançados')
            ->assertSee('desktop-sidebar is-collapsed', false)
            ->assertSee('desktop-main is-expanded', false)
            ->assertSee('OS26060006')
            ->assertSee('Notebook Dell Inspiron 15');

        Http::assertSent(static function ($request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/api/v1/orders')
                && str_contains($request->url(), 'status_scope=open');
        });
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
            ->assertSee('OS pronta para execução')
            ->assertDontSee('Configurações do sistema');
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
            ->assertJsonPath('data.charts.monthly.year', 2026)
            ->assertJsonPath('data.charts.equipmentTypes.period.mes', 1)
            ->assertJsonPath('data.lowStock', []);
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
                            'marca' => 'Acer',
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
                'os' => ['visualizar'],
                'clientes' => ['visualizar'],
                'equipamentos' => ['visualizar'],
                'usuarios' => ['visualizar'],
                'grupos' => ['visualizar'],
            ]))
            ->get('/buscar?q=ana&scope=tudo');

        $response
            ->assertOk()
            ->assertSee('Ordens de Serviço')
            ->assertSee('OS1001')
            ->assertSee('Ana Comércio LTDA')
            ->assertSee('Notebook Acer Nitro')
            ->assertSee('Ana Gestora')
            ->assertSee('Ana Equipe');
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
            ->assertJsonPath('data.scope', 'os');
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

    public function test_nova_os_button_visible_and_create_page_renders_form(): void
    {
        Http::fake([
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
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 21,
                            'resumo_tecnico' => 'Notebook Acer Nitro',
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
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'clientes' => ['visualizar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/os/criar');

        $response
            ->assertOk()
            ->assertSee('Nova OS')
            ->assertSee('Ana Comércio LTDA')
            ->assertSee('Notebook Acer Nitro')
            ->assertSee('Relato do cliente');
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
                'cliente_id' => 11,
                'equipamento_id' => 21,
                'relato_cliente' => 'Notebook não liga.',
                'prioridade' => 'alta',
                'data_previsao' => '2026-06-24',
                'observacoes_internas' => 'Prioridade do balcão.',
            ]);

        $response
            ->assertRedirect(route('orders.show', 77))
            ->assertSessionHas('success');
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
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/clientes/201');

        $response
            ->assertOk()
            ->assertSee('Cliente Alpha')
            ->assertSee('Ordens de serviço do cliente')
            ->assertSee('Equipamentos do cliente')
            ->assertSee('OS1001')
            ->assertSee('Notebook Acer Nitro')
            ->assertSee(route('orders.create', ['cliente_id' => 201]), false);
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
            ->withSession($this->desktopSession([
                'clientes' => ['visualizar', 'editar'],
                'os' => ['visualizar', 'criar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/clientes/201');

        $response
            ->assertOk()
            ->assertSee('Editar cliente')
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
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => [
                        'current_page' => 1,
                        'per_page' => 100,
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

    public function test_nova_os_page_prefills_selected_client_and_filters_equipments(): void
    {
        Http::fake([
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
            'http://127.0.0.1:8000/api/v1/equipments*' => Http::response([
                'status' => 'success',
                'data' => [
                    'equipments' => [
                        [
                            'id' => 301,
                            'resumo_tecnico' => 'Notebook Acer Nitro',
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
                'dashboard' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'clientes' => ['visualizar'],
                'equipamentos' => ['visualizar'],
            ]))
            ->get('/os/criar?cliente_id=201&equipamento_id=301');

        $response
            ->assertOk()
            ->assertSee('Cliente Alpha')
            ->assertSee('Notebook Acer Nitro')
            ->assertSee('value="201" selected', false)
            ->assertSee('value="301" selected', false);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), '/api/v1/equipments') && str_contains($request->url(), 'client_id=201');
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
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'clientes' => ['visualizar'],
            ]))
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
                        'marca_id' => 1,
                        'modelo_id' => 1,
                        'cor' => 'Preto',
                        'numero_serie' => 'SN-12345',
                        'imei' => '',
                        'observacoes' => 'Observação técnica',
                        'desktop_modalidade' => 'desktop',
                        'resumo_tecnico' => 'Notebook Acer Nitro',
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
            ->withSession($this->desktopSession([
                'equipamentos' => ['visualizar'],
                'os' => ['visualizar', 'criar'],
                'clientes' => ['visualizar'],
            ]))
            ->get('/equipamentos/301');

        $response
            ->assertOk()
            ->assertSee('Notebook Acer Nitro')
            ->assertSee('Foto principal do equipamento')
            ->assertSee(route('equipments.photos.show', [301, 91]), false)
            ->assertSee('Cliente vinculado')
            ->assertSee('Cliente Alpha')
            ->assertSee('Ordens de serviço vinculadas')
            ->assertSee('OS1001')
            ->assertSee('Nova OS')
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
            ->get('/equipamentos/novo');

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
            ->assertDontSee('clientsSearch', false);

        $response->assertSeeInOrder([
            'Cliente *',
            'Tipo *',
            'Marca',
            'Modelo',
            'Senha de acesso',
            'Acess',
            'Estado f',
            'Observa',
        ]);

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
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    /**
     * @return array<string, \Illuminate\Http\Client\Response>
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
    private function dashboardSummaryFixture(): array
    {
        return [
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
        ];
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