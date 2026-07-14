<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConfigurationSystemTest extends TestCase
{
    public function test_system_settings_page_renders_separated_sections(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
            ]))
            ->get('/configuracoes/sistema');

        $response
            ->assertOk()
            ->assertSee('Configurações do Sistema')
            ->assertSee('Aparência')
            ->assertSee('Dados da Empresa')
            ->assertSee('Sessão e Segurança')
            ->assertSee('Integrações')
            ->assertSee(route('configurations.integrations.index'), false)
            ->assertDontSee('Configurações WhatsApp')
            ->assertDontSee('Salvar integrações')
            // Sessão sem permissão de "usuarios"/"grupos" — abas/links somem.
            ->assertDontSee('Gerenciar níveis de acesso')
            ->assertDontSee('Novo usuário');
    }

    public function test_system_settings_page_shows_niveis_de_acesso_link_when_permitted(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
                'grupos' => ['visualizar'],
            ]))
            ->get('/configuracoes/sistema');

        $response
            ->assertOk()
            ->assertSee('Gerenciar níveis de acesso')
            ->assertSee(route('groups.index'), false);
    }

    public function test_system_settings_page_embeds_users_management_when_permitted(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
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
                            'group' => ['id' => 4, 'nome' => 'Gerência'],
                            'foto' => '',
                            'ativo' => true,
                            'ultimo_acesso' => '2026-06-22T09:55:00-03:00',
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [
                    'pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1],
                ],
            ]),
            'http://127.0.0.1:8000/api/v1/groups' => Http::response([
                'status' => 'success',
                'data' => ['groups' => [['id' => 4, 'nome' => 'Gerência', 'descricao' => '', 'sistema' => false, 'users_count' => 1]]],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'configuracoes' => ['visualizar'],
                'usuarios' => ['visualizar', 'criar', 'editar'],
            ]))
            ->get('/configuracoes/sistema?tab=usuarios');

        $response
            ->assertOk()
            ->assertSee('Usuários', false)
            ->assertSee('Ana Gestora')
            ->assertSee('Gerência')
            ->assertSee('Novo usuário')
            ->assertSee(route('configurations.system.index', ['tab' => 'usuarios']), false);
    }

    /**
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_theme' => 'default',
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
