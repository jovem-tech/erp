<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
    }

    public function test_default_dre_catalog_is_seeded_by_the_migration(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/financeiro/catalogo');

        $response->assertOk()
            ->assertJsonPath('data.dre_grupos.0.nome', 'Receita Operacional')
            ->assertJsonCount(5, 'data.dre_grupos')
            ->assertJsonCount(12, 'data.categorias');
    }

    public function test_receivable_without_client_or_order_is_rejected(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Receita avulsa de teste',
            'valor' => 150.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'FINANCEIRO_SAVE_FAILED');
    }

    public function test_lancamento_lifecycle_create_partial_and_full_settlement(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'OS de teste',
            'cliente_id' => $clienteId,
            'valor' => 200.00,
            'data_vencimento' => now()->addDays(10)->toDateString(),
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.lancamento.status', 'pendente')
            ->assertJsonPath('data.lancamento.grupo_dre', 'Receita Operacional')
            ->assertJsonPath('data.lancamento.subgrupo_dre', 'Serviços e peças de OS');

        $financeiroId = (int) $store->json('data.lancamento.id');

        $partial = $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 80.00,
            'forma_pagamento' => 'pix',
        ]);

        $partial->assertOk()
            ->assertJsonPath('data.resumo.status_resolvido', 'parcial')
            ->assertJsonPath('data.lancamento.status', 'parcial');

        $blockedTypeChange = $this->putJson("/api/v1/financeiro/{$financeiroId}", [
            'tipo' => 'pagar',
        ]);
        $blockedTypeChange->assertStatus(422);

        $final = $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 120.00,
            'forma_pagamento' => 'pix',
        ]);

        $final->assertOk()
            ->assertJsonPath('data.resumo.status_resolvido', 'pago')
            ->assertJsonPath('data.lancamento.status', 'pago');

        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
        ]);
        $this->assertDatabaseCount('financeiro_movimentos', 2);
    }

    public function test_creating_with_status_pago_registers_full_settlement_automatically(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Venda de peças',
            'descricao' => 'Venda de peça de teste',
            'cliente_id' => $clienteId,
            'valor' => 90.00,
            'status' => 'pago',
            'forma_pagamento' => 'dinheiro',
            'data_vencimento' => now()->toDateString(),
        ]);

        $store->assertCreated()->assertJsonPath('data.lancamento.status', 'pago');

        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
            'valor_movimento' => 90.00,
        ]);
    }

    public function test_dre_catalog_crud_for_grupo_subgrupo_and_categoria(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $grupo = $this->postJson('/api/v1/financeiro/dre-grupos', [
            'nome' => 'Investimentos',
            'ordem_exibicao' => 60,
        ])->assertCreated();

        $grupoId = (int) $grupo->json('data.dre_grupo.id');

        $subgrupo = $this->postJson('/api/v1/financeiro/dre-subgrupos', [
            'grupo_id' => $grupoId,
            'nome' => 'Equipamentos novos',
        ])->assertCreated();

        $subgrupoId = (int) $subgrupo->json('data.dre_subgrupo.id');

        $categoria = $this->postJson('/api/v1/financeiro/categorias', [
            'nome' => 'Compra de maquinário',
            'tipo' => 'pagar',
            'dre_grupo_id' => $grupoId,
            'dre_subgrupo_id' => $subgrupoId,
        ])->assertCreated();

        $this->assertDatabaseHas('financeiro_categorias', [
            'id' => $categoria->json('data.categoria.id'),
            'nome' => 'Compra de maquinário',
            'dre_grupo_id' => $grupoId,
        ]);

        $duplicate = $this->postJson('/api/v1/financeiro/dre-grupos', [
            'nome' => 'Investimentos',
        ]);
        $duplicate->assertStatus(422);
    }

    public function test_user_without_permission_cannot_create_lancamento(): void
    {
        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($attendant, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Serviço de teste',
            'cliente_id' => $clienteId,
            'valor' => 50,
            'data_vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertStatus(403);
    }
}
