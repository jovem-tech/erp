<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinanceiroMargemTest extends TestCase
{
    public function test_margem_page_renders_relatorio(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/margem*' => Http::response([
                'status' => 'success',
                'data' => [
                    'margem' => [
                        'mes' => '2026-06',
                        'periodo_label' => '06/2026',
                        'total_os' => 2,
                        'ticket_medio' => 250,
                        'margem_media_percentual' => 55.5,
                        'margem_total' => 280,
                        'por_tecnico' => [
                            ['tecnico_id' => 3, 'total_os' => 2, 'margem_media_percentual' => 55.5, 'margem_total' => 280],
                        ],
                        'piores_os' => [
                            ['os_id' => 10, 'numero_os' => 'OS2606001', 'margem_contribuicao' => 80, 'percentual_margem' => 30],
                        ],
                        'melhores_os' => [
                            ['os_id' => 11, 'numero_os' => 'OS2606002', 'margem_contribuicao' => 200, 'percentual_margem' => 81],
                        ],
                    ],
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/relatorios/margem?mes=2026-06');

        $response->assertOk()
            ->assertSee('Margem por OS')
            ->assertSee('OS2606001')
            ->assertSee('OS2606002');
    }

    public function test_configuracoes_page_renders_comissoes(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/financeiro/catalogo*' => Http::response([
                'status' => 'success',
                'data' => [
                    'categorias' => [],
                    'dre_grupos' => [],
                    'dre_subgrupos' => [],
                    'comissoes_tecnicos' => [
                        ['id' => 1, 'tecnico_id' => 7, 'percentual_padrao' => 10, 'tecnico' => ['nome' => 'Técnico Teste']],
                    ],
                    'comissao_percentual_padrao' => 5,
                ],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['visualizar']]))
            ->get('/financeiro/configuracoes');

        $response->assertOk()
            ->assertSee('Comissionamento')
            ->assertSee('Técnico Teste');
    }

    public function test_save_comissao_posts_to_api_and_redirects(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/financeiro/comissoes' => Http::response([
                'status' => 'success',
                'data' => ['comissao' => ['id' => 1, 'tecnico_id' => 7, 'percentual_padrao' => 10]],
                'error' => null,
                'meta' => [],
            ], 201),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['financeiro' => ['editar']]))
            ->post('/financeiro/configuracoes/comissoes', [
                'tecnico_id' => 7,
                'percentual_padrao' => 10,
            ]);

        $response->assertRedirect(route('financeiro.configuracoes'));
        Http::assertSent(static fn ($request) => $request->url() === 'http://127.0.0.1:8000/api/v1/financeiro/comissoes' && $request->method() === 'POST');
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
