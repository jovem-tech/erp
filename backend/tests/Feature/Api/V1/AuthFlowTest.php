<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(2, [
            'os' => ['visualizar', 'editar'],
            'equipamentos' => ['visualizar'],
            'clientes' => ['visualizar'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_health_returns_ok(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', config('app.version'));
    }

    public function test_login_returns_bearer_token_with_expiration(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 12:00:00', 'America/Sao_Paulo'));

        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Senha@123',
            'device_name' => 'pwa-mobile',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.expires_at', '2026-06-29T12:00:00-03:00')
            ->assertJsonPath('data.user.group.id', 2)
            ->assertJsonPath('data.user.modules.0', 'clientes');

        $this->assertNotEmpty($response->json('data.access_token'));
    }

    public function test_login_is_rate_limited_after_repeated_failed_attempts(): void
    {
        $user = $this->makeUser();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Senha@errada',
                'device_name' => 'pwa-mobile',
            ])->assertUnauthorized()
                ->assertJsonPath('error.code', 'AUTH_INVALID_CREDENTIALS');
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Senha@errada',
            'device_name' => 'pwa-mobile',
        ]);

        $response->assertTooManyRequests()
            ->assertJsonPath('error.code', 'AUTH_LOGIN_RATE_LIMITED');

        $retryAfter = (int) data_get($response->json(), 'error.details.retry_after');
        $this->assertGreaterThan(0, $retryAfter);
    }

    public function test_me_returns_authenticated_user_with_effective_permissions(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.nome', $user->nome)
            ->assertJsonPath('data.group.id', 2)
            ->assertJsonPath('data.modules.0', 'clientes');

        $permissions = $response->json('data.permissions', []);

        $this->assertSame(['visualizar'], $permissions['clientes']);
        $this->assertSame(['visualizar'], $permissions['equipamentos']);
        $this->assertSame(['editar', 'visualizar'], $permissions['os']);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->makeUser();
        $token = $this->loginAndGetToken($user->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.revoked', true);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    public function test_refresh_issues_a_new_token(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-22 12:00:00', 'America/Sao_Paulo'));

        $user = $this->makeUser();
        $oldToken = $this->loginAndGetToken($user->email);

        Carbon::setTestNow(Carbon::parse('2026-06-23 12:00:00', 'America/Sao_Paulo'));

        $response = $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->postJson('/api/v1/auth/refresh');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', '2026-06-30T12:00:00-03:00');

        $newToken = (string) $response->json('data.access_token');

        $this->assertNotSame($oldToken, $newToken);

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer ' . $newToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_expired_token_is_rejected_by_auth_sanctum(): void
    {
        $user = $this->makeUser();
        $expiredToken = $user->createToken('expired-device', ['*'], now()->subDay())->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $expiredToken)
            ->getJson('/api/v1/auth/me');

        $response->assertUnauthorized()
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    private function makeUser(): User
    {
        return $this->createUserRecord([
            'nome' => 'Técnico PWA',
            'email' => 'tecnico.pwa@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'pwa-mobile',
        ]);

        return (string) $response->json('data.access_token');
    }
}
