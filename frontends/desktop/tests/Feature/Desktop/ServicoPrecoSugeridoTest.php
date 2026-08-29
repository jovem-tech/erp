<?php

namespace Tests\Feature\Desktop;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Preço sugerido e cadeia de custo no cadastro de serviço — specs/037, Fase 3.
 *
 * O formulário tinha `valor`, `tempo_padrao_horas` e `custo_direto_padrao` como
 * três inputs soltos, sem uma linha de JavaScript. `tempo_padrao_horas` era
 * praticamente morto: existia e nenhum cálculo real o lia.
 */
class ServicoPrecoSugeridoTest extends TestCase
{
    public function test_formulario_carrega_a_cadeia_de_custo_e_a_sugestao(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response($this->notificationsPayload(), 200),
            'http://127.0.0.1:8000/api/v1/servicos/form-data' => Http::response([
                'status' => 'success',
                'data' => ['form' => ['tipos_equipamento' => [], 'status_options' => []]],
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['servicos' => ['visualizar', 'criar']]))
            ->get('/servicos/novo');

        $response->assertOk()
            ->assertSee('id="precoSugestao"', false)
            ->assertSee('id="cadeiaCusto"', false)
            ->assertSee('servicos-form.js', false)
            ->assertSee('sugerirPrecoUrl', false)
            // O rótulo novo é a correção de um bug de entrada de dados: nada
            // dizia se mão de obra entrava no custo direto, e metade dos
            // cadastros a incluía — dupla-contando contra tempo × custo-hora.
            ->assertSee('Custo de materiais por execução')
            ->assertSee('Não inclua mão de obra', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationsPayload(): array
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
}
