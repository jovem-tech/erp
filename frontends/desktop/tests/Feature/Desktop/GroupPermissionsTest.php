<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_and_balances_module_is_rendered_in_permissions_matrix(): void
    {
        Http::fake($this->catalogFixtures());

        $this->withSession($this->desktopSession(['grupos' => ['visualizar', 'editar']]))
            ->get('/grupos/5/permissoes')
            ->assertOk()
            ->assertSee('Contas e Saldos')
            ->assertSee('contas_saldos')
            ->assertSee('permissions[contas_saldos][]', false)
            ->assertSee('data-permission-row="contas_saldos"', false)
            ->assertSee('data-module-permission-toggle', false)
            ->assertSee('Selecionar todas')
            ->assertSee('data-permission-column-toggle="visualizar"', false)
            ->assertSee('data-permission-column-toggle="criar"', false)
            ->assertSee('Marcar coluna')
            ->assertSee('Desmarcar coluna')
            ->assertSee('data-select-all-permissions', false)
            ->assertSee('Marcar todas as permissões')
            ->assertSee('data-clear-all-permissions', false)
            ->assertSee('Desmarcar todas as permissões');
    }

    public function test_accounts_and_balances_permissions_are_forwarded_to_backend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/groups/5/permissions' => Http::response([
                'status' => 'success',
                'data' => ['permissions' => ['contas_saldos' => ['visualizar', 'criar']]],
                'error' => null,
                'meta' => [],
            ]),
        ]);

        $this->withSession($this->desktopSession(['grupos' => ['visualizar', 'editar']]))
            ->from('/grupos/5/permissoes')
            ->post('/grupos/5/permissoes', [
                'permissions' => [
                    'contas_saldos' => ['visualizar', 'criar'],
                ],
            ])
            ->assertRedirect('/grupos/5/permissoes')
            ->assertSessionHas('success');

        Http::assertSent(static fn ($request): bool => $request->url() === 'http://127.0.0.1:8000/api/v1/groups/5/permissions'
            && $request->method() === 'PUT'
            && $request['permissions']['contas_saldos'] === ['visualizar', 'criar']);
    }

    /** @return array<string, mixed> */
    private function catalogFixtures(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/groups/5/permissions' => Http::response([
                'status' => 'success',
                'data' => [
                    'group' => [
                        'id' => 5,
                        'nome' => 'Suporte',
                        'descricao' => 'Atendimento e suporte ao cliente',
                        'sistema' => false,
                        'users_count' => 0,
                    ],
                    'permissions' => [
                        'contas_saldos' => ['visualizar'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/modules' => Http::response([
                'status' => 'success',
                'data' => [
                    'modules' => [[
                        'id' => 15,
                        'nome' => 'Contas e Saldos',
                        'slug' => 'contas_saldos',
                        'icone' => 'bi-wallet2',
                        'ordem_menu' => 47,
                        'ativo' => true,
                    ]],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/permissions' => Http::response([
                'status' => 'success',
                'data' => [
                    'permissions' => [
                        ['id' => 1, 'nome' => 'Visualizar', 'slug' => 'visualizar'],
                        ['id' => 2, 'nome' => 'Criar', 'slug' => 'criar'],
                        ['id' => 3, 'nome' => 'Editar', 'slug' => 'editar'],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 6, 'total' => 0, 'last_page' => 1]],
            ]),
        ];
    }

    /** @param array<string, array<int, string>> $permissions @return array<string, mixed> */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 1,
                    'nome' => 'Administrador',
                    'email' => 'admin@example.com',
                    'perfil' => 'admin',
                    'ativo' => true,
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                ],
            ],
        ];
    }
}
