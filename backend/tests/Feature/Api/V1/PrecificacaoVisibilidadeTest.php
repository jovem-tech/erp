<?php

namespace Tests\Feature\Api\V1;

use App\Support\FaixaMargem;
use App\Support\VisibilidadeCusto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Visibilidade de custo — specs/037-precificacao-integrada-ao-fluxo.
 *
 * Decisao do dono: quem tem permissao financeira ve custo e margem em reais;
 * os demais veem semaforo e piso. O teste olha o JSON, nao o HTML — esconder
 * na view deixa o numero no devtools, e e exatamente isso que a redacao no DTO
 * existe para impedir.
 */
class PrecificacaoVisibilidadeTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        // Grupo 1: vende E enxerga o financeiro.
        $this->grantGroupPermissions(1, [
            'vendas' => ['visualizar', 'criar'],
            'financeiro' => ['visualizar'],
        ]);
        // Grupo 3: vende, mas nao enxerga custo.
        $this->grantGroupPermissions(3, [
            'vendas' => ['visualizar', 'criar'],
        ]);

        $this->createPecaRecord([
            'codigo' => 'PC-VIS',
            'nome' => 'Tela LCD',
            'preco_custo' => 120.00,
            'preco_venda' => 250.00,
            'quantidade_atual' => 5,
        ]);
    }

    public function test_quem_tem_permissao_financeira_ve_custo_e_margem(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $response = $this->getJson('/api/v1/vendas/itens/buscar?search=PC-VIS')->assertOk();

        $item = collect($response->json('data.itens'))->firstWhere('codigo', 'PC-VIS');

        $this->assertSame(VisibilidadeCusto::COMPLETO, $response->json('data.visibilidade_custo'));
        $this->assertEqualsWithDelta(120.0, (float) $item['custo_unitario'], 0.01);
        // (250 - 120) / 250 = 52%
        $this->assertEqualsWithDelta(52.0, (float) $item['margem_percentual'], 0.01);
        $this->assertSame(FaixaMargem::VERDE, $item['faixa']);
    }

    /**
     * O caso que a decisao do dono existe para resolver: o tecnico precisa
     * saber que passou do piso sem que a tabela de custo da empresa fique
     * exposta para quem atende o balcao.
     */
    public function test_quem_nao_tem_permissao_financeira_nao_recebe_o_custo_no_payload(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $response = $this->getJson('/api/v1/vendas/itens/buscar?search=PC-VIS')->assertOk();

        $item = collect($response->json('data.itens'))->firstWhere('codigo', 'PC-VIS');

        $this->assertSame(VisibilidadeCusto::INDICATIVO, $response->json('data.visibilidade_custo'));

        // A CHAVE nao existe — nao e questao de estar vazia ou escondida.
        $this->assertArrayNotHasKey('custo_unitario', $item);
        $this->assertArrayNotHasKey('margem_percentual', $item);
        $this->assertArrayNotHasKey('composicao', $item);

        // Mas o que orienta a decisao continua chegando.
        $this->assertSame(FaixaMargem::VERDE, $item['faixa']);
        $this->assertEqualsWithDelta(120.0, (float) $item['preco_minimo'], 0.01);
        $this->assertFalse($item['abaixo_do_piso']);
    }

    /**
     * Peca sem custo cadastrado nao pode pintar verde: a margem aritmetica
     * seria 100% e o semaforo premiaria justamente o cadastro incompleto.
     */
    public function test_peca_sem_custo_fica_indefinida_e_nao_verde(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->createPecaRecord([
            'codigo' => 'PC-SEMCUSTO',
            'nome' => 'Conector sem custo',
            'preco_custo' => 0,
            'preco_venda' => 80.00,
            'quantidade_atual' => 3,
        ]);

        $response = $this->getJson('/api/v1/vendas/itens/buscar?search=PC-SEMCUSTO')->assertOk();

        $item = collect($response->json('data.itens'))->firstWhere('codigo', 'PC-SEMCUSTO');

        $this->assertSame(FaixaMargem::INDEFINIDO, $item['faixa']);
        $this->assertNull($item['custo_unitario']);
        $this->assertNull($item['margem_percentual']);
    }
}
