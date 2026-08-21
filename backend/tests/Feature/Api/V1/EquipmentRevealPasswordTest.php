<?php

namespace Tests\Feature\Api\V1;

use App\Models\Equipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Step-up de administrador para revelar a senha de acesso do equipamento.
 *
 * O ponto central: quem autoriza é a PERMISSÃO `grupos:editar`, não o nome do
 * grupo. Até a v5.39.5.0 a checagem era `str_contains($grupo->nome, 'admin')`,
 * então um grupo chamado "Administrativo" — sem permissão nenhuma — liberava a
 * senha de desbloqueio do aparelho do cliente.
 */
class EquipmentRevealPasswordTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        // Grupo 3 = quem opera a tela de equipamentos (vê, mas não autoriza).
        $this->grantGroupPermissions(3, [
            'equipamentos' => ['visualizar', 'criar', 'editar'],
        ]);

        // Grupo 4 = super administrador de fato: pode editar grupos.
        $this->grantGroupPermissions(4, [
            'grupos' => ['visualizar', 'editar'],
        ]);

        // Grupo 5: nome contém "admin", permissões nenhuma. É a armadilha.
        DB::table('grupos')->insert([
            'id' => 5,
            'nome' => 'Administrativo',
            'descricao' => 'Setor administrativo, sem privilégio de RBAC',
            'sistema' => 0,
            'created_at' => now(),
        ]);
    }

    /**
     * Cria via Eloquent de proposito: e' assim que o EquipmentWorkflowService
     * grava, e e' o que faz o cast `encrypted` cifrar o valor. Um insert pelo
     * query builder gravaria texto puro e nao exercitaria a cifragem.
     */
    private function equipmentWithPassword(): int
    {
        return (int) Equipment::query()->create([
            'cliente_id' => $this->createClientRecord(),
            'desktop_modalidade' => 'notebook',
            'senha_acesso' => 'padrao-1-2-5-8',
        ])->id;
    }

    public function test_a_senha_fica_cifrada_no_banco(): void
    {
        $id = $this->equipmentWithPassword();

        $gravado = (string) DB::table('equipamentos')->where('id', $id)->value('senha_acesso');

        $this->assertNotSame('padrao-1-2-5-8', $gravado, 'a senha nao pode ficar em texto puro no banco');
        $this->assertStringNotContainsString('padrao-1-2-5-8', $gravado);
        $this->assertSame('padrao-1-2-5-8', Equipment::query()->find($id)->senha_acesso, 'o cast tem de decifrar na leitura');
    }

    private function actAsOperator(): void
    {
        Sanctum::actingAs($this->createUserRecord([
            'email' => 'operador@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]), ['*']);
    }

    public function test_grupo_apenas_com_nome_administrativo_nao_revela_a_senha(): void
    {
        $this->actAsOperator();

        $this->createUserRecord([
            'nome' => 'Fulano do Administrativo',
            'email' => 'administrativo@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 5,
        ]);

        $response = $this->postJson('/api/v1/equipments/'.$this->equipmentWithPassword().'/reveal-password', [
            'admin_email' => 'administrativo@example.com',
            'admin_password' => 'Senha@123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'EQUIPMENT_PASSWORD_REVEAL_ADMIN_AUTH_INVALID');

        $this->assertStringNotContainsString('padrao-1-2-5-8', $response->getContent());
    }

    public function test_permissao_grupos_editar_revela_a_senha(): void
    {
        $this->actAsOperator();

        $this->createUserRecord([
            'nome' => 'Super Administrador',
            'email' => 'super@example.com',
            'perfil' => 'gerente',
            'grupo_id' => 4,
        ]);

        $this->postJson('/api/v1/equipments/'.$this->equipmentWithPassword().'/reveal-password', [
            'admin_email' => 'super@example.com',
            'admin_password' => 'Senha@123',
        ])
            ->assertOk()
            ->assertJsonPath('data.senha_acesso', 'padrao-1-2-5-8');
    }

    public function test_perfil_admin_legado_continua_revelando_a_senha(): void
    {
        $this->actAsOperator();

        $this->createUserRecord([
            'nome' => 'Admin Legado',
            'email' => 'legado@example.com',
            'perfil' => 'admin',
            'grupo_id' => 3,
        ]);

        $this->postJson('/api/v1/equipments/'.$this->equipmentWithPassword().'/reveal-password', [
            'admin_email' => 'legado@example.com',
            'admin_password' => 'Senha@123',
        ])
            ->assertOk()
            ->assertJsonPath('data.senha_acesso', 'padrao-1-2-5-8');
    }

    public function test_senha_de_administrador_errada_nao_revela(): void
    {
        $this->actAsOperator();

        $this->createUserRecord([
            'nome' => 'Super Administrador',
            'email' => 'super@example.com',
            'perfil' => 'gerente',
            'grupo_id' => 4,
        ]);

        $this->postJson('/api/v1/equipments/'.$this->equipmentWithPassword().'/reveal-password', [
            'admin_email' => 'super@example.com',
            'admin_password' => 'senha-errada',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'EQUIPMENT_PASSWORD_REVEAL_ADMIN_AUTH_INVALID');
    }
}
