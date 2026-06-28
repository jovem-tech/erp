<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
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
            ->assertSee('Novo lançamento');
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
                'valor' => 150.0,
                'data_vencimento' => now()->addDays(5)->toDateString(),
            ]);

        $response->assertRedirect(route('financeiro.index'));
        Http::assertSent(static function ($request) {
            return $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro' && $request->method() === 'POST';
        });
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
