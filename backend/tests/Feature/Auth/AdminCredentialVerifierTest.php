<?php

namespace Tests\Feature\Auth;

use App\Services\Auth\AdminCredentialVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Porta única de todas as confirmações "e-mail + senha de administrador" do
 * sistema (cancelar baixa de OS, editar orçamento de OS encerrada, devolução
 * de venda, caixa, excluir lançamento, estorno de fatura de cartão,
 * gerenciador de arquivos). Quem pode autorizar é decidido aqui e em nenhum
 * outro lugar — por isso a regra tem teste próprio, e não só através de cada
 * fluxo.
 */
class AdminCredentialVerifierTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private const SENHA = 'Senha@123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        RateLimiter::clear($this->throttleKeyFor('admin@example.com'));
    }

    public function test_legacy_admin_profile_is_accepted(): void
    {
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1, 'email' => 'legado@example.com']);

        $this->assertTrue($this->verify($admin->email)['ok']);
    }

    /**
     * O caso que motivou a regra: o campo "Perfil" da tela de usuários é só o
     * NOME do grupo em modo leitura, então quem está num grupo "Super
     * Administrador" tem perfil diferente de 'admin' e era recusado mesmo
     * podendo tudo no sistema.
     */
    public function test_super_administrator_by_rbac_group_is_accepted_even_without_the_legacy_profile(): void
    {
        $this->grantGroupPermissions(4, ['grupos' => ['visualizar', 'editar']]);
        $superAdmin = $this->createUserRecord([
            'perfil' => 'Super Administrador',
            'grupo_id' => 4,
            'email' => 'super@example.com',
        ]);

        $this->assertTrue($this->verify($superAdmin->email)['ok']);
    }

    /**
     * Poder ver grupos não é poder editá-los: só quem edita consegue se
     * conceder qualquer permissão, e é isso que caracteriza o super admin.
     */
    public function test_read_only_access_to_groups_is_not_enough(): void
    {
        $this->grantGroupPermissions(3, ['grupos' => ['visualizar']]);
        $usuario = $this->createUserRecord([
            'perfil' => 'Gerente',
            'grupo_id' => 3,
            'email' => 'gerente@example.com',
        ]);

        $this->assertFalse($this->verify($usuario->email)['ok']);
    }

    public function test_an_operator_without_administrative_permissions_is_refused(): void
    {
        $this->grantGroupPermissions(2, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
            'contas_saldos' => ['visualizar', 'criar', 'editar'],
        ]);
        $operador = $this->createUserRecord([
            'perfil' => 'atendente',
            'grupo_id' => 2,
            'email' => 'operador@example.com',
        ]);

        $this->assertFalse($this->verify($operador->email)['ok']);
    }

    public function test_wrong_password_is_refused_even_for_a_super_administrator(): void
    {
        $this->grantGroupPermissions(4, ['grupos' => ['visualizar', 'editar']]);
        $superAdmin = $this->createUserRecord([
            'perfil' => 'Super Administrador',
            'grupo_id' => 4,
            'email' => 'super2@example.com',
        ]);

        $resultado = $this->verify($superAdmin->email, 'senha-errada');

        $this->assertFalse($resultado['ok']);
        $this->assertSame('invalid', $resultado['error']);
    }

    public function test_an_inactive_administrator_is_refused(): void
    {
        $admin = $this->createUserRecord([
            'perfil' => 'admin',
            'grupo_id' => 1,
            'email' => 'inativo@example.com',
            'ativo' => 0,
        ]);

        $this->assertFalse($this->verify($admin->email)['ok']);
    }

    /**
     * Quando o fluxo diz exatamente quem pode autorizar, é essa habilidade que
     * vale — nem o perfil legado nem o super admin entram por cima.
     */
    public function test_an_explicit_ability_overrides_both_doors(): void
    {
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1, 'email' => 'semhabilidade@example.com']);

        $this->assertFalse($this->verify($admin->email, self::SENHA, 'arquivos:administrar')['ok']);
    }

    /** @return array<string, mixed> */
    private function verify(string $email, string $senha = self::SENHA, ?string $ability = null): array
    {
        return app(AdminCredentialVerifier::class)->verify(
            $email,
            $senha,
            'teste-admin-auth',
            '127.0.0.1',
            $ability
        );
    }

    private function throttleKeyFor(string $email): string
    {
        return 'teste-admin-auth:'.$email.'|127.0.0.1';
    }
}
