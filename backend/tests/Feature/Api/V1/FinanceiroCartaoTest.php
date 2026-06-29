<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroCartaoTest extends TestCase
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

    public function test_index_returns_catalog_dataset_and_gateway_summary(): void
    {
        $this->seedCartaoCatalog();

        Sanctum::actingAs($this->makeAdminUser(), ['*']);

        $response = $this->getJson('/api/v1/financeiro/cartoes');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.cartoes.summary.operadoras_total', 1)
            ->assertJsonPath('data.cartoes.summary.bandeiras_total', 1)
            ->assertJsonPath('data.cartoes.summary.taxas_total', 1)
            ->assertJsonPath('data.cartoes.operadoras.0.nome', 'Stone')
            ->assertJsonPath('data.cartoes.bandeiras.0.nome', 'Visa')
            ->assertJsonPath('data.cartoes.taxas.0.modalidade', 'credito')
            ->assertJsonPath('data.gateway.gateway_catalog.asaas.label', 'Asaas');
    }

    public function test_simulator_calculates_net_amount_with_the_active_rate(): void
    {
        $catalog = $this->seedCartaoCatalog();

        Sanctum::actingAs($this->makeAdminUser(), ['*']);

        $response = $this->postJson('/api/v1/financeiro/cartoes/simular', [
            'valor_bruto' => 100.00,
            'operadora_id' => $catalog['operadora_id'],
            'bandeira_id' => $catalog['bandeira_id'],
            'modalidade' => 'credito',
            'parcelas' => 3,
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.simulation.valor_bruto', 100.0)
            ->assertJsonPath('data.simulation.valor_taxa', 3.19)
            ->assertJsonPath('data.simulation.valor_liquido', 96.81)
            ->assertJsonPath('data.simulation.modalidade', 'credito')
            ->assertJsonPath('data.simulation.operadora_nome', 'Stone')
            ->assertJsonPath('data.simulation.bandeira_nome', 'Visa');
    }

    public function test_operadora_bandeira_and_taxa_lifecycle_use_soft_deactivation(): void
    {
        Sanctum::actingAs($this->makeAdminUser(), ['*']);
        $catalog = $this->seedCartaoCatalog();

        $operadora = $this->postJson('/api/v1/financeiro/cartoes/operadoras', [
            'nome' => 'Cielo',
            'descricao' => 'Operadora teste',
            'ordem_exibicao' => 5,
            'prazo_padrao_dias' => 14,
            'ativo' => true,
        ])->assertCreated();

        $operadoraId = (int) $operadora->json('data.operadora.id');

        $this->patchJson('/api/v1/financeiro/cartoes/operadoras/' . $operadoraId, [
            'nome' => 'Cielo Atualizada',
            'descricao' => 'Operadora atualizada',
            'ordem_exibicao' => 8,
            'prazo_padrao_dias' => 21,
            'ativo' => true,
        ])->assertOk()
            ->assertJsonPath('data.operadora.nome', 'Cielo Atualizada')
            ->assertJsonPath('data.operadora.prazo_padrao_dias', 21);

        $this->deleteJson('/api/v1/financeiro/cartoes/operadoras/' . $operadoraId)
            ->assertOk()
            ->assertJsonPath('data.operadora.ativo', false);

        $this->assertDatabaseHas('financeiro_cartao_operadoras', [
            'id' => $operadoraId,
            'ativo' => 0,
        ]);

        $bandeira = $this->postJson('/api/v1/financeiro/cartoes/bandeiras', [
            'nome' => 'Mastercard',
            'ordem_exibicao' => 9,
            'ativo' => true,
        ])->assertCreated();

        $bandeiraId = (int) $bandeira->json('data.bandeira.id');

        $this->deleteJson('/api/v1/financeiro/cartoes/bandeiras/' . $bandeiraId)
            ->assertOk()
            ->assertJsonPath('data.bandeira.ativo', false);

        $taxa = $this->postJson('/api/v1/financeiro/cartoes/taxas', [
            'operadora_id' => $catalog['operadora_id'],
            'bandeira_id' => $catalog['bandeira_id'],
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 6,
            'taxa_percentual' => 2.79,
            'taxa_fixa' => 0.40,
            'prazo_recebimento_dias' => 30,
            'observacoes' => 'Taxa de teste',
            'ativo' => true,
        ])->assertCreated();

        $taxaId = (int) $taxa->json('data.taxa.id');

        $this->deleteJson('/api/v1/financeiro/cartoes/taxas/' . $taxaId)
            ->assertOk()
            ->assertJsonPath('data.taxa.ativo', false);

        $this->assertDatabaseHas('financeiro_cartao_taxas', [
            'id' => $taxaId,
            'ativo' => 0,
        ]);
    }

    public function test_gateway_taxa_lifecycle_uses_configuration_storage(): void
    {
        Sanctum::actingAs($this->makeAdminUser(), ['*']);

        $store = $this->postJson('/api/v1/financeiro/cartoes/taxas-online', [
            'provider' => 'asaas',
            'modalidade' => 'PIX',
            'taxa_percentual' => 1.99,
            'taxa_fixa' => 0,
            'ordem_exibicao' => 10,
            'observacoes' => 'Taxa online teste',
            'ativo' => true,
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.gateway_taxa.provider', 'asaas')
            ->assertJsonPath('data.gateway_taxa.modalidade', 'PIX')
            ->assertJsonPath('data.gateway_taxa.ativo', true);

        $gatewayTaxaId = (int) $store->json('data.gateway_taxa.id');

        $this->patchJson('/api/v1/financeiro/cartoes/taxas-online/' . $gatewayTaxaId, [
            'provider' => 'asaas',
            'modalidade' => 'BOLETO',
            'taxa_percentual' => 2.49,
            'taxa_fixa' => 0,
            'ordem_exibicao' => 20,
            'observacoes' => 'Taxa online atualizada',
            'ativo' => true,
        ])->assertOk()
            ->assertJsonPath('data.gateway_taxa.modalidade', 'BOLETO');

        $this->deleteJson('/api/v1/financeiro/cartoes/taxas-online/' . $gatewayTaxaId)
            ->assertOk()
            ->assertJsonPath('data.gateway_taxa.ativo', false);
    }

    public function test_user_without_financeiro_permission_is_blocked(): void
    {
        Sanctum::actingAs($this->createUserRecord([
            'nome' => 'Atendente Financeiro',
            'email' => 'atendente.financeiro@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]), ['*']);

        $this->getJson('/api/v1/financeiro/cartoes')->assertForbidden();
    }

    /**
     * @return array<string, int>
     */
    private function seedCartaoCatalog(): array
    {
        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Stone',
            'descricao' => 'Operadora principal',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bandeiraId = (int) DB::table('financeiro_cartao_bandeiras')->insertGetId([
            'nome' => 'Visa',
            'ordem_exibicao' => 1,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => $bandeiraId,
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 6,
            'taxa_percentual' => 3.19,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 30,
            'observacoes' => 'Taxa principal',
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'operadora_id' => $operadoraId,
            'bandeira_id' => $bandeiraId,
        ];
    }

    private function makeAdminUser()
    {
        return $this->createUserRecord([
            'nome' => 'Administrador Financeiro',
            'email' => 'admin.financeiro@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }
}
