<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroPrecificacaoTest extends TestCase
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

    public function test_index_and_update_routes_manage_precificacao_settings(): void
    {
        Sanctum::actingAs($this->makeAdminUser(), ['*']);

        $index = $this->getJson('/api/v1/financeiro/precificacao');

        $index->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.precificacao.summary.componentes_peca_total', 3)
            ->assertJsonPath('data.precificacao.summary.componentes_servico_custo_total', 1)
            ->assertJsonPath('data.precificacao.summary.componentes_servico_risco_total', 1)
            ->assertJsonPath('data.precificacao.summary.categorias_peca_total', 1)
            ->assertJsonPath('data.precificacao.summary.categorias_servico_total', 1);

        $update = $this->putJson('/api/v1/financeiro/precificacao', [
            'precificacao_peca_base' => 'venda',
            'precificacao_peca_encargos_percentual' => 18,
            'precificacao_peca_margem_percentual' => 42,
            'precificacao_peca_respeitar_preco_venda' => false,
            'precificacao_peca_usa_componentes' => true,
            'precificacao_servico_custo_hora_produtiva' => 55,
            'precificacao_servico_margem_percentual' => 30,
            'precificacao_servico_taxa_recebimento_percentual' => 4.5,
            'precificacao_servico_imposto_percentual' => 2.0,
            'precificacao_servico_tempo_padrao_horas' => 1.5,
            'precificacao_servico_usa_componentes' => true,
            'precificacao_servico_aplicar_catalogo' => true,
            'precificacao_servico_aplicar_piso' => false,
        ]);

        $update->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.precificacao.settings.precificacao_peca_base', 'venda')
            ->assertJsonPath('data.precificacao.settings.precificacao_servico_custo_hora_produtiva', '55')
            ->assertJsonPath('data.precificacao.summary.componentes_peca_total', 3);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'precificacao_peca_base',
            'valor' => 'venda',
        ]);

        $this->assertDatabaseHas('configuracoes', [
            'chave' => 'precificacao_servico_custo_hora_produtiva',
            'valor' => '55',
        ]);
    }

    public function test_simulators_apply_category_overrides_and_catalog_rules(): void
    {
        $pecaId = $this->createPecaRecord([
            'nome' => 'Placa de vídeo',
            'categoria' => 'Insumos',
            'preco_custo' => 25.00,
            'preco_venda' => 50.00,
        ]);

        $servicoId = $this->createServiceRecord([
            'nome' => 'Reparo de software',
            'valor' => 180.00,
            'tempo_padrao_horas' => 2.00,
            'custo_direto_padrao' => 30.00,
            'tipo_equipamento' => 'Notebook',
        ]);

        Sanctum::actingAs($this->makeAdminUser(), ['*']);

        $peca = $this->postJson('/api/v1/financeiro/precificacao/simular-peca', [
            'peca_id' => $pecaId,
        ]);

        $peca->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.simulation.categoria_override.categoria_nome', 'Insumos')
            ->assertJsonPath('data.simulation.valor_recomendado', 50.0);

        $servico = $this->postJson('/api/v1/financeiro/precificacao/simular-servico', [
            'servico_id' => $servicoId,
            'tipo_equipamento' => 'Software',
        ]);

        $servico->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.simulation.categoria_override.categoria_nome', 'Software')
            ->assertJsonPath('data.simulation.modo_precificacao', 'servico_auto_recomendado')
            ->assertJsonPath('data.simulation.valor_recomendado', 207.48);
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
