<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
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

        $response = $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'nao-existe@empresa.com',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reset_link_sent', true);

        Notification::assertNothingSent();
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
