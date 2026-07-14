<?php

namespace Tests\Feature\Desktop;

use App\Models\SessionSecuritySetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

class SessionSecurityTest extends TestCase
{
    use RefreshDatabase;


    public function test_login_without_remember_issues_a_cookie_that_dies_with_the_browser(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/login' => Http::response($this->loginPayload(), 200),
        ]);

        $response = $this->post('/login', [
            'email' => 'ana@empresa.com',
            'password' => 'Senha@123',
        ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('desktop_auth.remember_me', false);

        $cookie = $this->sessionCookieFrom($response);
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime(), 'Sem "lembrar-me", o cookie de sessão deve morrer ao fechar o navegador (sem Expires/Max-Age).');

        // O XSRF-TOKEN não deve nascer com validade de 30 dias: sem "lembrar-me"
        // ele fica curto (validade = timeout de inatividade, padrão 120 min).
        $xsrf = $this->cookieFrom($response, 'XSRF-TOKEN');
        $this->assertNotNull($xsrf);
        $this->assertNotSame(0, $xsrf->getExpiresTime());
        $this->assertLessThan(time() + 86400, $xsrf->getExpiresTime(), 'Sem "lembrar-me", o XSRF-TOKEN não deve ter validade longa (30 dias).');
    }

    public function test_login_with_remember_issues_a_persistent_cookie(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/login' => Http::response($this->loginPayload(), 200),
        ]);

        $response = $this->post('/login', [
            'email' => 'ana@empresa.com',
            'password' => 'Senha@123',
            'remember' => '1',
        ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('desktop_auth.remember_me', true);

        $cookie = $this->sessionCookieFrom($response);
        $this->assertNotNull($cookie);
        $this->assertGreaterThan(time() + 3600, $cookie->getExpiresTime(), 'Com "lembrar-me", o cookie deve sobreviver ao fechamento do navegador.');
    }

    public function test_authenticated_request_without_remember_keeps_issuing_a_cookie_that_dies_with_the_browser(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], false))
            ->get('/dashboard');

        $response->assertOk();

        $cookie = $this->sessionCookieFrom($response);
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime());
    }

    public function test_idle_timeout_logs_out_a_non_remembered_session(): void
    {
        $session = $this->desktopSession(['dashboard' => ['visualizar']], false);
        $session['desktop_auth']['last_activity'] = time() - (200 * 60);

        $response = $this
            ->withSession($session)
            ->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Sessão encerrada por inatividade.')
            ->assertSessionMissing('desktop_auth');
    }

    public function test_recent_activity_does_not_trigger_idle_timeout(): void
    {
        $session = $this->desktopSession(['dashboard' => ['visualizar']], false);
        $session['desktop_auth']['last_activity'] = time() - 60;

        $response = $this
            ->withSession($session)
            ->get('/dashboard');

        $response->assertOk()->assertSessionHas('desktop_auth');
    }

    public function test_remembered_session_survives_long_inactivity(): void
    {
        $session = $this->desktopSession(['dashboard' => ['visualizar']], true);
        $session['desktop_auth']['last_activity'] = time() - (200 * 60);

        $response = $this
            ->withSession($session)
            ->get('/dashboard');

        $response->assertOk()->assertSessionHas('desktop_auth');
    }

    public function test_logout_resets_cookie_to_die_with_the_browser(): void
    {
        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/logout' => Http::response([
                'status' => 'success',
                'data' => null,
                'error' => null,
                'meta' => [],
            ], 200),
        ]);

        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], true))
            ->post('/logout');

        $response->assertRedirect(route('login'));

        $cookie = $this->sessionCookieFrom($response);
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime(), 'Após logout, mesmo vindo de uma sessão "lembrada", o próximo cookie deve voltar a morrer com o navegador.');
    }

    public function test_admin_can_update_idle_timeout_and_remember_lifetime(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']], false))
            ->post('/configuracoes/sessao-seguranca', [
                'idle_timeout_minutes' => 30,
                'remember_me_lifetime_days' => 7,
                'remember_me_enabled' => '1',
            ]);

        $response
            ->assertRedirect(route('configurations.system.index', ['tab' => 'sessao']))
            ->assertSessionHas('success');

        $this->assertSame(1, SessionSecuritySetting::count());
        $setting = SessionSecuritySetting::first();
        $this->assertSame(30, $setting->idle_timeout_minutes);
        $this->assertSame(7, $setting->remember_me_lifetime_days);
        $this->assertTrue($setting->remember_me_enabled);
    }

    public function test_disabling_remember_me_hides_the_checkbox_on_the_login_page(): void
    {
        SessionSecuritySetting::create([
            'idle_timeout_minutes' => 120,
            'remember_me_enabled' => false,
            'remember_me_lifetime_days' => 30,
        ]);

        $response = $this->get('/login');

        $response->assertOk()->assertDontSee('Manter-me conectado neste dispositivo');
    }

    public function test_disabling_remember_me_prevents_login_from_honoring_the_checkbox(): void
    {
        SessionSecuritySetting::create([
            'idle_timeout_minutes' => 120,
            'remember_me_enabled' => false,
            'remember_me_lifetime_days' => 30,
        ]);

        Http::fake([
            'http://127.0.0.1:8000/api/v1/auth/login' => Http::response($this->loginPayload(), 200),
        ]);

        $response = $this->post('/login', [
            'email' => 'ana@empresa.com',
            'password' => 'Senha@123',
            'remember' => '1',
        ]);

        $response->assertSessionHas('desktop_auth.remember_me', false);

        $cookie = $this->sessionCookieFrom($response);
        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime(), 'Com o recurso desativado, marcar o checkbox (forçado via request) não deve gerar cookie persistente.');
    }

    public function test_disabling_remember_me_immediately_strips_idle_timeout_exemption_from_already_remembered_sessions(): void
    {
        SessionSecuritySetting::create([
            'idle_timeout_minutes' => 120,
            'remember_me_enabled' => false,
            'remember_me_lifetime_days' => 30,
        ]);

        $session = $this->desktopSession(['dashboard' => ['visualizar']], true);
        $session['desktop_auth']['last_activity'] = time() - (200 * 60);

        $response = $this
            ->withSession($session)
            ->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Sessão encerrada por inatividade.')
            ->assertSessionMissing('desktop_auth');
    }

    public function test_reopen_guard_script_renders_for_non_remembered_session(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], false))
            ->get('/dashboard');

        $response->assertOk()->assertSee('__DESKTOP_SESSION_GUARD', false);
    }

    public function test_reopen_guard_script_is_absent_for_remembered_session(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], true))
            ->get('/dashboard');

        $response->assertOk()->assertDontSee('__DESKTOP_SESSION_GUARD', false);
    }

    public function test_close_warning_is_armed_by_default_for_non_remembered_session(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], false))
            ->get('/dashboard');

        $response->assertOk()->assertSee('warnOnClose\u0022:true', false);
    }

    public function test_close_warning_can_be_disabled_via_settings(): void
    {
        SessionSecuritySetting::create([
            'idle_timeout_minutes' => 120,
            'remember_me_enabled' => true,
            'remember_me_lifetime_days' => 30,
            'warn_on_close' => false,
        ]);

        $response = $this
            ->withSession($this->desktopSession(['dashboard' => ['visualizar']], false))
            ->get('/dashboard');

        $response->assertOk()->assertSee('warnOnClose\u0022:false', false);
    }

    public function test_admin_can_update_close_warning_toggle(): void
    {
        $response = $this
            ->withSession($this->desktopSession(['configuracoes' => ['visualizar', 'editar']], false))
            ->post('/configuracoes/sessao-seguranca', [
                'idle_timeout_minutes' => 120,
                'remember_me_lifetime_days' => 30,
                'remember_me_enabled' => '1',
                // warn_on_close ausente = desmarcado
            ]);

        $response->assertRedirect(route('configurations.system.index', ['tab' => 'sessao']));
        $this->assertFalse(SessionSecuritySetting::first()->warn_on_close);
    }

    public function test_idle_timeout_setting_from_database_is_applied(): void
    {
        SessionSecuritySetting::create([
            'idle_timeout_minutes' => 10,
            'remember_me_enabled' => true,
            'remember_me_lifetime_days' => 30,
        ]);

        $session = $this->desktopSession(['dashboard' => ['visualizar']], false);
        $session['desktop_auth']['last_activity'] = time() - (15 * 60);

        $response = $this
            ->withSession($session)
            ->get('/dashboard');

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Sessão encerrada por inatividade.');
    }

    /**
     * @return array<string, mixed>
     */
    private function loginPayload(): array
    {
        return [
            'status' => 'success',
            'data' => [
                'access_token' => 'token-123',
                'user' => $this->fakeUser([
                    'permissions' => ['dashboard' => ['visualizar']],
                    'modules' => ['dashboard'],
                ]),
            ],
            'error' => null,
            'meta' => [],
        ];
    }

    private function sessionCookieFrom(\Illuminate\Testing\TestResponse $response): ?Cookie
    {
        return $this->cookieFrom($response, (string) config('session.cookie'));
    }

    private function cookieFrom(\Illuminate\Testing\TestResponse $response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $permissions
     * @return array<string, mixed>
     */
    private function desktopSession(array $permissions, bool $rememberMe): array
    {
        return [
            'desktop_theme' => 'default',
            'desktop_auth' => [
                'token' => 'desktop-session-token',
                'synced_at' => time(),
                'remember_me' => $rememberMe,
                'last_activity' => time(),
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
