<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Desativar um usuário tem de encerrar o acesso dele à API na hora.
 *
 * Antes, `tokens()->delete()` só era chamado na troca/reset de senha e o guard
 * `auth:sanctum` não olhava a coluna `ativo` — um funcionário desligado seguia
 * com acesso total por até SANCTUM_EXPIRATION (7 dias).
 *
 * Usa token Bearer real de propósito: `Sanctum::actingAs` injeta o usuário
 * direto e não exercita o guard, que é justamente o que precisa ser testado.
 */
class InactiveUserTokenTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(3, ['dashboard' => ['visualizar']]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('teste')->plainTextToken;
    }

    public function test_token_de_usuario_ativo_funciona(): void
    {
        $user = $this->createUserRecord(['email' => 'ativo@example.com', 'ativo' => true]);

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($user))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'ativo@example.com');
    }

    public function test_token_para_de_valer_quando_o_usuario_e_desativado(): void
    {
        $user = $this->createUserRecord(['email' => 'demitido@example.com', 'ativo' => true]);
        $token = $this->tokenFor($user);

        // Ainda funciona enquanto ativo.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        // Desativação feita fora do controller (edição direta), para provar que a
        // guarda de runtime cobre qualquer caminho, não só a tela de usuários.
        $user->forceFill(['ativo' => false])->save();

        // Em teste a aplicacao e' compartilhada entre as requisicoes e o guard
        // guarda o usuario ja resolvido, entao sem isto a segunda chamada nem
        // revalidaria o token. Em producao cada requisicao e' um processo novo.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    public function test_desativar_pela_api_revoga_os_tokens_existentes(): void
    {
        $admin = $this->createUserRecord(['email' => 'admin@example.com', 'grupo_id' => 4, 'ativo' => true]);
        $this->grantGroupPermissions(4, ['usuarios' => ['visualizar', 'editar']]);

        $alvo = $this->createUserRecord(['email' => 'alvo@example.com', 'ativo' => true]);
        $tokenDoAlvo = $this->tokenFor($alvo);

        $this->assertSame(1, $alvo->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$this->tokenFor($admin))
            ->patchJson('/api/v1/users/'.$alvo->id, ['ativo' => false])
            ->assertOk();

        $this->assertSame(0, $alvo->fresh()->tokens()->count(), 'os tokens deveriam ter sido revogados');

        // Ver acima: solta o guard resolvido da requisicao anterior (a do admin).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$tokenDoAlvo)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
