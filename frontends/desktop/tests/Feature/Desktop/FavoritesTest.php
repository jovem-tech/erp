<?php

namespace Tests\Feature\Desktop;

use App\Models\UserPreference;
use App\Support\DesktopPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FavoritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_star_is_offered_on_a_favoritable_page(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('data-desktop-favorite-toggle', false)
            ->assertSee('data-favorite-route="dashboard"', false);
    }

    /**
     * A estrela mora ao lado do TÍTULO da página, dentro do conteúdo — não na
     * topbar. Colada no nome da página, a ação diz sozinha o que ela fixa; na
     * topbar ela ficava ambígua, ainda por cima ao lado do ícone do próprio
     * menu de favoritos.
     */
    public function test_star_lives_inside_the_page_not_in_the_navbar(): void
    {
        $this->fakeBackend();

        $html = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'clientes' => ['visualizar']]))
            ->get('/clientes')
            ->assertOk()
            ->getContent();

        $navbarEnds = strpos($html, '<main class="desktop-content">');
        $star = strpos($html, 'data-desktop-favorite-toggle');
        $favoritesMenu = strpos($html, 'data-desktop-favorites-root');

        $this->assertNotFalse($navbarEnds);
        $this->assertNotFalse($star);
        $this->assertNotFalse($favoritesMenu);

        // O menu de favoritos continua na topbar; a estrela desceu para a página.
        $this->assertLessThan($navbarEnds, $favoritesMenu, 'O menu de favoritos deveria estar na topbar.');
        $this->assertGreaterThan($navbarEnds, $star, 'A estrela deveria estar dentro do conteúdo da página.');
    }

    /**
     * Registros individuais e telas auxiliares não entram nos favoritos — a
     * lista é de páginas do sistema.
     */
    public function test_star_is_absent_on_a_page_that_is_not_favoritable(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/buscar?q=teste')
            ->assertOk()
            ->assertDontSee('data-desktop-favorite-toggle', false);
    }

    public function test_toggling_adds_then_removes_the_page(): void
    {
        $this->fakeBackend();
        $session = $this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar']]);

        $this
            ->withSession($session)
            ->postJson('/favoritos/alternar', ['route' => 'orders.index'])
            ->assertOk()
            ->assertJsonPath('favorito', true)
            ->assertJsonPath('favoritos.0.route', 'orders.index')
            ->assertJsonPath('favoritos.0.label', 'Ordens de Serviço');

        $this->assertSame(['orders.index'], UserPreference::first()->favorites);

        $this
            ->withSession($session)
            ->postJson('/favoritos/alternar', ['route' => 'orders.index'])
            ->assertOk()
            ->assertJsonPath('favorito', false)
            ->assertJsonPath('favoritos', []);

        $this->assertSame([], UserPreference::first()->favorites);
    }

    public function test_unknown_route_cannot_be_favorited(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->postJson('/favoritos/alternar', ['route' => 'rota.inexistente'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('user_preferences', 0);
    }

    /**
     * A validação de favoritável passa pelo RBAC do módulo, então favoritar não
     * pode virar um caminho lateral para descobrir telas sem permissão.
     */
    public function test_page_the_user_cannot_see_cannot_be_favorited(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->postJson('/favoritos/alternar', ['route' => 'financeiro.index'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_favorites_are_listed_in_the_navbar(): void
    {
        $this->fakeBackend();

        UserPreference::create([
            'api_user_id' => 99,
            'favorites' => ['orders.index', 'clients.index'],
        ]);

        $response = $this
            ->withSession($this->desktopSession([
                'dashboard' => ['visualizar'],
                'os' => ['visualizar'],
                'clientes' => ['visualizar'],
            ]))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Nenhum favorito ainda');

        // Os rótulos também existem na sidebar, então o que prova a lista é a
        // marcação própria do dropdown — um link por favorito, na ordem gravada.
        $this->assertSame(2, substr_count($response->getContent(), 'desktop-favorites-link"'));
    }

    /**
     * Perder a permissão esconde o atalho, mas não apaga a escolha do usuário:
     * devolvida a permissão, o favorito volta sozinho.
     */
    public function test_favorite_without_permission_is_hidden_but_kept_in_storage(): void
    {
        $this->fakeBackend();

        UserPreference::create([
            'api_user_id' => 99,
            'favorites' => ['financeiro.index'],
        ]);

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Nenhum favorito ainda');

        $this->assertSame(['financeiro.index'], UserPreference::first()->favorites);
    }

    public function test_favorites_are_capped(): void
    {
        $this->fakeBackend();

        $permissions = [
            'dashboard' => ['visualizar'], 'agenda' => ['visualizar'], 'os' => ['visualizar'],
            'orcamentos' => ['visualizar'], 'vendas' => ['visualizar'], 'clientes' => ['visualizar'],
            'fornecedores' => ['visualizar'], 'servicos' => ['visualizar'], 'estoque' => ['visualizar'],
            'financeiro' => ['visualizar'], 'usuarios' => ['visualizar'], 'grupos' => ['visualizar'],
            'conhecimento' => ['visualizar'],
        ];

        $full = [
            'dashboard', 'agenda.index', 'orders.index', 'orcamentos.index', 'vendas.index',
            'clients.index', 'suppliers.index', 'servicos.index', 'estoque.index',
            'financeiro.index', 'users.index', 'groups.index',
        ];

        $this->assertCount(DesktopPreferences::MAX_FAVORITES, $full);

        UserPreference::create(['api_user_id' => 99, 'favorites' => $full]);

        $this
            ->withSession($this->desktopSession($permissions))
            ->postJson('/favoritos/alternar', ['route' => 'knowledge.defects.index'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);

        $this->assertSame($full, UserPreference::first()->favorites);
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
                'status' => 'success',
                'data' => [],
                'error' => null,
                'meta' => [],
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
