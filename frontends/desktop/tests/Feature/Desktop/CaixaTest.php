<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Turnos de caixa no desktop — specs/028-caixa-sessoes.
 */
class CaixaTest extends TestCase
{
    use RefreshDatabase;

    public function test_caixa_fechado_oferece_abertura(): void
    {
        Http::fake($this->fixtures(sessaoAberta: false));

        $this->withSession($this->desktopSession(['caixa' => ['visualizar', 'criar', 'editar']]))
            ->get('/caixa')
            ->assertOk()
            ->assertSee('Caixa fechado')
            ->assertSee('Abrir caixa')
            ->assertSee('id="caixaAbrirModal"', false);
    }

    public function test_caixa_aberto_mostra_totais_sem_revelar_o_esperado(): void
    {
        Http::fake($this->fixtures(sessaoAberta: true));

        $response = $this->withSession($this->desktopSession(['caixa' => ['visualizar', 'criar', 'editar']]))
            ->get('/caixa')
            ->assertOk()
            ->assertSee('Vendas em dinheiro')
            ->assertSee('Sangria')
            ->assertSee('Fechar caixa')
            ->assertSee('id="caixaFecharModal"', false);

        // Conferência cega: a tela do turno aberto não pode exibir o esperado.
        $response->assertDontSee('Esperado em caixa');
    }

    public function test_fechamento_envia_valor_contado_normalizado(): void
    {
        Http::fake($this->fixtures(sessaoAberta: true));

        $this->withSession($this->desktopSession(['caixa' => ['visualizar', 'editar']]))
            ->from('/caixa')
            ->post('/caixa/9/fechar', ['valor_informado' => '1.234,56'])
            ->assertRedirect('/caixa/9');

        Http::assertSent(static function ($request): bool {
            if ($request->url() !== 'http://127.0.0.1:8000/api/v1/caixa/9/fechar') {
                return false;
            }

            return $request['valor_informado'] === '1234.56';
        });
    }

    public function test_sangria_exige_motivo(): void
    {
        Http::fake($this->fixtures(sessaoAberta: true));

        $this->withSession($this->desktopSession(['caixa' => ['visualizar', 'editar']]))
            ->from('/caixa')
            ->post('/caixa/9/movimentos', ['tipo' => 'sangria', 'valor' => '50,00'])
            ->assertRedirect('/caixa')
            ->assertSessionHasErrors('motivo');
    }

    public function test_usuario_sem_permissao_de_criar_nao_abre_caixa(): void
    {
        Http::fake($this->fixtures(sessaoAberta: false));

        $this->withSession($this->desktopSession(['caixa' => ['visualizar']]))
            ->post('/caixa/abrir', ['valor_abertura' => '100,00'])
            ->assertRedirect('/caixa')
            ->assertSessionHas('error');
    }

    /** @return array<string, mixed> */
    private function fixtures(bool $sessaoAberta): array
    {
        $sessao = [
            'id' => 9,
            'status' => 'aberta',
            'status_label' => 'Aberta',
            'conta_financeira_id' => 1,
            'conta_nome' => 'Caixa da loja',
            'operador_id' => 1,
            'operador_nome' => 'Administrador',
            'aberto_em' => '2026-08-13T08:00:00-03:00',
            'fechado_em' => null,
            'valor_abertura' => 100.0,
            'abertura_automatica' => false,
            'valor_informado' => null,
            'diferenca' => null,
            'total_vendas_dinheiro' => 250.0,
            'total_suprimentos' => 0.0,
            'total_sangrias' => 50.0,
            'quantidade_vendas' => 4,
            'movimentos' => [[
                'id' => 1,
                'tipo' => 'sangria',
                'tipo_label' => 'Sangria',
                'valor' => 50.0,
                'motivo' => 'Depósito bancário',
                'responsavel_nome' => 'Administrador',
                'created_at' => '2026-08-13T12:00:00-03:00',
            ]],
        ];

        return [
            'http://127.0.0.1:8000/api/v1/caixa/atual' => Http::response([
                'status' => 'success',
                'data' => [
                    'conta' => $sessaoAberta ? ['id' => 1, 'nome' => 'Caixa da loja'] : null,
                    'sessao' => $sessaoAberta ? $sessao : null,
                    'contas_destino' => [['id' => 2, 'nome' => 'Banco Inter', 'tipo' => 'banco']],
                    'status_options' => [],
                ],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/caixa/9/fechar' => Http::response([
                'status' => 'success',
                'data' => ['sessao' => array_merge($sessao, [
                    'status' => 'fechada',
                    'valor_esperado' => 300.0,
                    'valor_informado' => 1234.56,
                    'diferenca' => 934.56,
                ])],
                'error' => null,
                'meta' => [],
            ]),
            'http://127.0.0.1:8000/api/v1/caixa/*' => Http::response([
                'status' => 'success',
                'data' => ['sessao' => $sessao],
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
