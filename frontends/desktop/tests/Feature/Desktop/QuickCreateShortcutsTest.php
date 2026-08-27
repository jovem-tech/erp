<?php

namespace Tests\Feature\Desktop;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickCreateShortcutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quick_create_items_declare_their_shortcut(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['criar'],
                'orcamentos' => ['criar'],
                'vendas' => ['criar'],
                'financeiro' => ['criar'],
            ]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-desktop-quick-create="os"', false)
            ->assertSee('data-desktop-quick-create="orcamento"', false)
            ->assertSee('data-desktop-quick-create="venda"', false)
            ->assertSee('data-desktop-quick-create="lancamento"', false)
            // A tecla precisa estar visível: atalho que ninguém vê não é usado.
            ->assertSee('>F1</kbd>', false)
            ->assertSee('>F2</kbd>', false)
            ->assertSee('>F3</kbd>', false)
            ->assertSee('>F4</kbd>', false);
    }

    /**
     * O atalho aponta para o próprio link do dropdown, que só existe com
     * permissão. Sem o link, a tecla volta a ser do navegador — nada de rota
     * duplicada no JS driblando o RBAC.
     */
    public function test_shortcut_target_disappears_without_create_permission(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['criar'],
            ]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-desktop-quick-create="os"', false)
            ->assertDontSee('data-desktop-quick-create="orcamento"', false)
            ->assertDontSee('data-desktop-quick-create="venda"', false)
            ->assertDontSee('data-desktop-quick-create="lancamento"', false);
    }

    /**
     * O PDV reivindica só o que usa: F2 confirma a venda, F3 alterna a tela
     * cheia e F4 abre o cliente (vendas-pdv.js). F1 fica fora da lista — o PDV
     * não usa essa tecla, e abrir uma OS a partir do balcão é caso real.
     */
    public function test_pdv_claims_only_the_keys_it_actually_uses(): void
    {
        Http::fake(['http://127.0.0.1:8000/api/v1/*' => Http::response([
            'status' => 'success', 'data' => [], 'error' => null, 'meta' => [],
        ], 200)]);

        $this
            ->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->assertSee('data-desktop-fkeys-owner="F2 F3 F4"', false);
    }

    /**
     * As teclas reivindicadas pelo PDV têm que ser exatamente as que o
     * vendas-pdv.js trata — se ele passar a usar outra, ou deixar de usar uma,
     * a lista do Blade fica mentindo e o atalho global volta a brigar com o
     * balcão sem ninguém perceber.
     */
    public function test_pdv_claim_matches_the_keys_its_script_handles(): void
    {
        $script = file_get_contents(public_path('assets/js/vendas-pdv.js'));

        preg_match_all("/evento\\.key === '(F\\d)'/", (string) $script, $matches);
        $handled = array_values(array_unique($matches[1]));
        sort($handled);

        $this->assertSame(['F2', 'F3', 'F4'], $handled);
    }

    public function test_regular_screens_do_not_claim_the_function_keys(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('data-desktop-fkeys-owner', false);
    }

    private function fakeBackend(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
            'http://127.0.0.1:8000/api/v1/*' => Http::response([
                'status' => 'success', 'data' => [], 'error' => null, 'meta' => [],
            ], 200),
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $permissions
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
