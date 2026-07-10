<?php

namespace Tests\Feature\Api\V1;

use App\Models\Configuration;
use App\Notifications\FrontendPasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
    }

    public function test_forgot_password_sends_reset_link_for_active_user(): void
    {
        Notification::fake();
        config()->set('mail.default', 'smtp');

        $user = $this->createUserRecord([
            'email' => 'suporte@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reset_link_sent', true);

        Notification::assertSentTo(
            $user,
            FrontendPasswordResetNotification::class
        );
    }

    public function test_forgot_password_uses_database_smtp_when_env_mailer_is_log(): void
    {
        Notification::fake();

        config()->set('mail.default', 'log');

        foreach ([
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => '587',
            'smtp_crypto' => 'tls',
            'smtp_timeout' => '20',
            'smtp_user' => 'noreply@example.com',
            'smtp_pass' => 'smtp-secret',
            'smtp_from_email' => 'noreply@example.com',
            'smtp_from_name' => 'Sistema ERP',
        ] as $key => $value) {
            Configuration::query()->create([
                'chave' => $key,
                'valor' => $value,
                'tipo' => 'texto',
            ]);
        }

        $user = $this->createUserRecord([
            'email' => 'preview@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reset_link_sent', true)
            ->assertJsonPath('data.delivery.mode', 'email');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('noreply@example.com', config('mail.from.address'));

        Notification::assertSentTo(
            $user,
            FrontendPasswordResetNotification::class
        );
    }

    public function test_forgot_password_fails_closed_when_no_operational_mailer_is_available(): void
    {
        Notification::fake();

        config()->set('mail.default', 'log');

        $user = $this->createUserRecord([
            'email' => 'sem-mailer@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'AUTH_PASSWORD_RESET_CHANNEL_UNAVAILABLE');

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'nao-existe@empresa.com',
        ])->assertStatus(503)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'AUTH_PASSWORD_RESET_CHANNEL_UNAVAILABLE');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_rejects_decommissioned_sistema_hml_frontend(): void
    {
        Notification::fake();

        $user = $this->createUserRecord([
            'email' => 'bff@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
            'frontend' => 'sistema-hml',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('status', 'error');

        Notification::assertNothingSent();
    }

    public function test_forgot_password_responds_without_leaking_unknown_email(): void
    {
        Notification::fake();
        config()->set('mail.default', 'smtp');

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'nao-existe@empresa.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reset_link_sent', true);

        Notification::assertNothingSent();
    }

    public function test_password_reset_link_uses_desktop_frontend_url_instead_of_api_port(): void
    {
        config()->set('services.frontend_desktop.url', 'https://192.168.1.100:8443');

        $url = FrontendPasswordResetNotification::resetUrlFor(
            'otaviomnsantos@gmail.com',
            'd25524e8517619cc7f316657ae5017bb6cab2bf0842daddbd159d6abb56dc2d2'
        );

        $this->assertSame(
            'https://192.168.1.100/redefinir-senha/d25524e8517619cc7f316657ae5017bb6cab2bf0842daddbd159d6abb56dc2d2?email=otaviomnsantos%40gmail.com',
            $url
        );
    }

    public function test_backend_reset_link_route_redirects_misplaced_api_link_to_desktop(): void
    {
        config()->set('services.frontend_desktop.url', 'https://192.168.1.100:8443');

        $this->get('/redefinir-senha/d25524e8517619cc7f316657ae5017bb6cab2bf0842daddbd159d6abb56dc2d2?email=otaviomnsantos%40gmail.com')
            ->assertRedirect('https://192.168.1.100/redefinir-senha/d25524e8517619cc7f316657ae5017bb6cab2bf0842daddbd159d6abb56dc2d2?email=otaviomnsantos%40gmail.com');
    }

    public function test_forgot_password_rate_limit_is_scoped_by_email_not_only_by_ip(): void
    {
        Notification::fake();
        config()->set('mail.default', 'smtp');

        $firstUser = $this->createUserRecord([
            'email' => 'primeiro-reset@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);
        $secondUser = $this->createUserRecord([
            'email' => 'segundo-reset@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson('/api/v1/auth/password/forgot', [
                'email' => $firstUser->email,
            ])->assertOk();
        }

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $firstUser->email,
        ])->assertStatus(429)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertJsonPath('error.message', 'Muitas tentativas. Aguarde alguns instantes e tente novamente.');

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $secondUser->email,
        ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reset_link_sent', true);
    }

    public function test_reset_password_updates_password_and_revokes_tokens(): void
    {
        $user = $this->createUserRecord([
            'email' => 'reset@empresa.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
            'ativo' => true,
        ]);

        $user->createToken('desktop', ['*'], now()->addDay());
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NovaSenha@123',
            'password_confirmation' => 'NovaSenha@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.password_reset', true);

        $user->refresh();

        $this->assertTrue(Hash::check('NovaSenha@123', (string) $user->senha));
        $this->assertSame(0, $user->tokens()->count());
    }
}
