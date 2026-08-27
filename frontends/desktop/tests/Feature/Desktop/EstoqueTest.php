<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Telas de estoque — specs/036-estoque-nucleo-razao.
 *
 * Ate esta entrega o modulo de estoque nao tinha NENHUM teste no desktop,
 * apesar de 14 rotas e 4 telas. Comeca aqui pelo que a Fase 1a mudou: a
 * quantidade deixou de ser inteira, e a exibicao precisa mostrar a fracao sem
 * transformar "10" em "10,0000".
 */
class EstoqueTest extends TestCase
{
    public function test_listagem_exibe_quantidade_fracionada_sem_truncar(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->fakeNotificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/estoque/form-data' => Http::response([
                'status' => 'success',
                'data' => ['form' => ['tipos_equipamento' => [], 'status_options' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
            'http://127.0.0.1:8000/api/v1/estoque*' => Http::response([
                'status' => 'success',
                'data' => [
                    'pecas' => [
                        [
                            'id' => 1,
                            'codigo' => 'PC-CABO',
                            'nome' => 'Cabo flat (metro)',
                            'categoria' => 'Insumos',
                            'tipo_equipamento' => '',
                            'preco_custo' => 12.0,
                            'preco_venda' => 30.0,
                            'quantidade_atual' => 2.5,
                            'estoque_minimo' => 1.0,
                            'estoque_maximo' => 10.0,
                            'status' => 'ativo',
                            'ativo' => true,
                        ],
                    ],
                ],
                'error' => null,
                'meta' => ['pagination' => ['current_page' => 1, 'per_page' => 15, 'total' => 1, 'last_page' => 1, 'from' => 1, 'to' => 1]],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['estoque' => ['visualizar']]))
            ->get('/estoque');

        $response->assertOk()
            ->assertSee('Cabo flat (metro)')
            // A fracao aparece em pt-BR...
            ->assertSee('2,5')
            // ...e o inteiro NAO vira "1,0000".
            ->assertDontSee('1,0000');
    }

    /**
     * Mesma forma usada pelas demais suites de desktop (o helper e privado por
     * classe neste repositorio, nao um trait compartilhado).
     *
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions): array
    {
        return [
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'user' => [
                    'id' => 99,
                    'nome' => 'Usuário de Teste',
                    'email' => 'usuario@teste.local',
                    'perfil' => 'admin',
                    'group' => ['id' => 1, 'nome' => 'Administrador', 'sistema' => true],
                    'modules' => array_keys($permissions),
                    'permissions' => $permissions,
                    'foto' => '',
                    'ativo' => true,
                    'assinatura_cadastrada' => true,
                ],
            ],
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
}
