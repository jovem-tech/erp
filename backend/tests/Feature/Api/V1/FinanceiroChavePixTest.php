<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Cadastro de chaves Pix (Financeiro > Configurações > Formas de Pagamento >
 * Pix), que alimenta as condições comerciais do orçamento.
 */
class FinanceiroChavePixTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
    }

    public function test_catalog_lists_pix_keys_and_their_types(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->postJson('/api/v1/financeiro/chaves-pix', [
            'tipo' => 'email',
            'chave' => 'financeiro@jovemtech.com.br',
            'titular' => 'Jovem Tech',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/financeiro/catalogo')->assertOk();

        $this->assertCount(1, $response->json('data.chaves_pix'));
        $this->assertSame('financeiro@jovemtech.com.br', $response->json('data.chaves_pix.0.chave'));
        $this->assertSame(
            ['cpf', 'cnpj', 'email', 'telefone', 'aleatoria'],
            array_column($response->json('data.chaves_pix_tipos'), 'value')
        );
    }

    public function test_only_one_key_stays_marked_as_principal(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $primeira = (int) $this->postJson('/api/v1/financeiro/chaves-pix', [
            'tipo' => 'cnpj',
            'chave' => '12.345.678/0001-90',
            'principal' => true,
        ])->assertCreated()->json('data.chave_pix.id');

        $segunda = (int) $this->postJson('/api/v1/financeiro/chaves-pix', [
            'tipo' => 'telefone',
            'chave' => '22992741003',
            'principal' => true,
        ])->assertCreated()->json('data.chave_pix.id');

        $this->assertSame(0, (int) DB::table('financeiro_chaves_pix')->where('id', $primeira)->value('principal'));
        $this->assertSame(1, (int) DB::table('financeiro_chaves_pix')->where('id', $segunda)->value('principal'));
    }

    public function test_duplicated_key_is_rejected(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $payload = ['tipo' => 'aleatoria', 'chave' => 'b1f0c0de-0000-4000-8000-000000000000'];

        $this->postJson('/api/v1/financeiro/chaves-pix', $payload)->assertCreated();
        $this->postJson('/api/v1/financeiro/chaves-pix', $payload)->assertStatus(422);
    }

    public function test_key_can_be_deactivated_and_deleted(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $id = (int) $this->postJson('/api/v1/financeiro/chaves-pix', [
            'tipo' => 'cpf',
            'chave' => '123.456.789-00',
        ])->assertCreated()->json('data.chave_pix.id');

        $this->patchJson('/api/v1/financeiro/chaves-pix/'.$id, ['ativo' => false])
            ->assertOk()
            ->assertJsonPath('data.chave_pix.ativo', false);

        $this->deleteJson('/api/v1/financeiro/chaves-pix/'.$id)->assertOk();
        $this->assertDatabaseMissing('financeiro_chaves_pix', ['id' => $id]);
    }

    public function test_unknown_key_type_is_rejected(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->postJson('/api/v1/financeiro/chaves-pix', [
            'tipo' => 'boleto',
            'chave' => 'qualquer',
        ])->assertStatus(422);
    }
}
