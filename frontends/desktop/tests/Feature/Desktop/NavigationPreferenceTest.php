<?php

namespace Tests\Feature\Desktop;

use App\Models\UserPreference;
use App\Support\DesktopPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavigationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_mode_keeps_the_sidebar_visible(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('desktop-sidebar is-hidden', false)
            ->assertDontSee('desktop-main is-full', false);
    }

    public function test_drawer_preference_hides_the_sidebar_across_the_system(): void
    {
        $this->fakeBackend();

        UserPreference::create([
            'api_user_id' => 99,
            'navigation_mode' => DesktopPreferences::NAV_MODE_DRAWER,
        ]);

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('desktop-sidebar is-hidden', false)
            ->assertSee('desktop-main is-full', false);
    }

    public function test_saving_the_mode_persists_for_the_next_login(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->post('/configuracoes/navegacao', ['navigation_mode' => 'drawer'])
            ->assertRedirect(route('configurations.system.index', ['tab' => 'aparencia']))
            ->assertSessionHas('desktop_nav_mode', 'drawer');

        $this->assertDatabaseHas('user_preferences', [
            'api_user_id' => 99,
            'navigation_mode' => 'drawer',
        ]);
    }

    /**
     * Preferência pessoal, não configuração do sistema: quem só enxerga o
     * dashboard também escolhe a própria navegação.
     */
    public function test_saving_the_mode_does_not_require_configuration_permission(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->post('/configuracoes/navegacao', ['navigation_mode' => 'drawer'])
            ->assertRedirect(route('configurations.system.index', ['tab' => 'aparencia']));
    }

    public function test_invalid_mode_is_rejected(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->post('/configuracoes/navegacao', ['navigation_mode' => 'flutuante'])
            ->assertSessionHas('error', 'Modo de navegação inválido.');

        $this->assertDatabaseMissing('user_preferences', ['navigation_mode' => 'flutuante']);
    }

    /**
     * Na gaveta não há o que recolher — o botão de recolher sai do DOM.
     *
     * Some do HTML, e não por CSS: o `.d-lg-inline-flex` do Bootstrap é
     * `!important` e vencia o `display:none` que tentava escondê-lo, então ele
     * aparecia dentro da gaveta aberta (inclusive em /os, antes desta entrega).
     */
    public function test_drawer_mode_drops_the_collapse_button(): void
    {
        $this->fakeBackend();

        UserPreference::create([
            'api_user_id' => 99,
            'navigation_mode' => DesktopPreferences::NAV_MODE_DRAWER,
        ]);

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('id="sidebarToggle"', false)
            ->assertDontSee('Recolher navegacao', false)
            // O sanduíche da topbar continua lá — é ele que abre a gaveta.
            ->assertSee('id="mobileSidebarToggle"', false);
    }

    public function test_fixed_mode_keeps_the_collapse_button(): void
    {
        $this->fakeBackend();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']]))
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('id="sidebarToggle"', false);
    }

    /**
     * A tabela de OS é a mais densa do sistema. No modo 'fixed' ela abre com a
     * sidebar retraída (80px) em vez de expandida (270px) — devolve largura sem
     * tirar o menu da tela.
     */
    public function test_os_listing_opens_with_a_collapsed_sidebar_in_fixed_mode(): void
    {
        $this->fakeEverything();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar']]))
            ->get('/os')
            ->assertOk()
            ->assertSee('desktop-sidebar is-collapsed', false)
            ->assertSee('desktop-main is-expanded', false)
            // Retraída, não escondida: o menu continua na tela.
            ->assertDontSee('desktop-sidebar is-hidden', false);
    }

    public function test_other_screens_keep_the_sidebar_expanded(): void
    {
        $this->fakeEverything();

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'clientes' => ['visualizar']]))
            ->get('/clientes')
            ->assertOk()
            ->assertDontSee('desktop-sidebar is-collapsed', false)
            ->assertDontSee('desktop-main is-expanded', false);
    }

    /**
     * No modo sanduíche o padrão de tela retraída não pode competir com a
     * gaveta: `is-hidden` e `is-collapsed` se anulam no CSS.
     */
    public function test_os_listing_uses_the_drawer_and_not_the_collapsed_default_in_drawer_mode(): void
    {
        $this->fakeEverything();

        UserPreference::create([
            'api_user_id' => 99,
            'navigation_mode' => DesktopPreferences::NAV_MODE_DRAWER,
        ]);

        $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar'], 'os' => ['visualizar']]))
            ->get('/os')
            ->assertOk()
            ->assertSee('desktop-sidebar is-hidden', false)
            ->assertDontSee('desktop-sidebar is-collapsed', false);
    }

    /**
     * O PDV precisa caber na tela sem rolagem (specs/027-vendas-balcao-pdv),
     * então ignora a preferência de propósito.
     */
    public function test_pdv_stays_full_width_even_with_the_fixed_preference(): void
    {
        Http::fake(array_merge($this->fakeBackendMap(), [
            'http://127.0.0.1:8000/api/v1/*' => Http::response([
                'status' => 'success',
                'data' => [],
                'error' => null,
                'meta' => [],
            ], 200),
        ]));

        UserPreference::create([
            'api_user_id' => 99,
            'navigation_mode' => DesktopPreferences::NAV_MODE_FIXED,
        ]);

        $this
            ->withSession($this->desktopSession(['vendas' => ['visualizar', 'criar']]))
            ->get('/vendas/nova')
            ->assertOk()
            ->assertSee('desktop-main is-full', false);
    }

    /**
     * Sessões abertas antes desta feature já têm `desktop_theme` gravado. O
     * sentinel de hidratação não pode ser essa chave, senão navegação e
     * favoritos só carregariam depois de um logout.
     */
    public function test_session_already_holding_the_theme_still_hydrates_the_new_keys(): void
    {
        $this->fakeBackend();

        UserPreference::create([
            'api_user_id' => 99,
            'desktop_theme' => 'dark',
            'navigation_mode' => DesktopPreferences::NAV_MODE_DRAWER,
        ]);

        $session = $this->desktopSession(['dashboard' => ['visualizar']]);
        $session['desktop_theme'] = 'jovem-tech';

        $this
            ->withSession($session)
            ->get('/dashboard')
            ->assertOk()
            ->assertSessionHas('desktop_nav_mode', DesktopPreferences::NAV_MODE_DRAWER)
            // O banco é a fonte da verdade: o tema em sessão é corrigido junto.
            ->assertSessionHas('desktop_theme', 'dark');
    }

    private function fakeBackend(): void
    {
        Http::fake($this->fakeBackendMap());
    }

    /**
     * Telas de listagem conversam com vários endpoints; o catch-all evita
     * enumerar todos só para conferir as classes do shell.
     */
    private function fakeEverything(): void
    {
        Http::fake(array_merge($this->fakeBackendMap(), [
            'http://127.0.0.1:8000/api/v1/*' => Http::response([
                'status' => 'success',
                'data' => [],
                'error' => null,
                'meta' => [],
            ], 200),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeBackendMap(): array
    {
        return [
            'http://127.0.0.1:8000/api/v1/notifications*' => Http::response([
                'status' => 'success',
                'data' => ['items' => [], 'unread_count' => 0],
                'error' => null,
                'meta' => ['pagination' => [
                    'current_page' => 1, 'per_page' => 6, 'total' => 0,
                    'last_page' => 1, 'from' => 0, 'to' => 0,
                ]],
            ], 200),
        ];
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
