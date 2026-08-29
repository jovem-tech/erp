<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Support\ModoPrecificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Precificacao por linha do orcamento — specs/037, Fase 4.
 *
 * Ate esta entrega, 8 das 9 colunas de precificacao de `orcamento_itens` eram
 * gravadas com ZERO literal e `modo_precificacao` era a string 'manual'
 * hard-coded em cinco lugares. A tabela guardava campos que nao respondiam
 * nada.
 */
class OrcamentoPrecificacaoItemTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'orcamentos' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'estoque' => ['visualizar'],
        ]);

        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);
    }

    private function criarOrcamentoComPeca(float $valorUnitario, int $pecaId): int
    {
        $clienteId = $this->createClientRecord();

        $response = $this->postJson('/api/v1/orcamentos', [
            'tipo_orcamento' => 'previo',
            'status' => 'rascunho',
            'origem' => 'manual',
            'cliente_id' => $clienteId,
            'titulo' => 'Orçamento de teste',
            'validade_dias' => 10,
            'itens' => [[
                'tipo_item' => 'peca',
                'referencia_id' => $pecaId,
                'descricao' => 'Tela LCD',
                'quantidade' => 1,
                'valor_unitario' => $valorUnitario,
                'desconto' => 0,
                'acrescimo' => 0,
            ]],
        ])->assertCreated();

        return (int) $response->json('data.budget.id');
    }

    public function test_grava_a_cotacao_real_no_lugar_dos_zeros(): void
    {
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-ORC',
            'nome' => 'Tela LCD',
            'preco_custo' => 100.00,
            'preco_venda' => 250.00,
        ]);

        $orcamentoId = $this->criarOrcamentoComPeca(250.00, $pecaId);
        $item = DB::table('orcamento_itens')->where('orcamento_id', $orcamentoId)->first();

        $this->assertEqualsWithDelta(100.0, (float) $item->preco_custo_referencia, 0.01);
        $this->assertEqualsWithDelta(250.0, (float) $item->preco_venda_referencia, 0.01);
        // 5%, não 12%: a peça nasce na categoria "Insumos", que tem override
        // próprio em `precificacao_categorias` (encargos 5%, margem 20%) e
        // vence os componentes globais — ver lookupCategoryOverride().
        $this->assertEqualsWithDelta(5.0, (float) $item->percentual_encargos, 0.01);
        $this->assertGreaterThan(0, (float) $item->valor_encargos);
        $this->assertGreaterThan(0, (float) $item->valor_recomendado);
    }

    /**
     * A decisao que importa: a coluna guarda a margem COBRADA, nao a meta das
     * configuracoes. Guardar a meta faria a coluna dizer 45% numa linha que o
     * vendedor descontou para 5% — mentira gravada.
     */
    public function test_percentual_margem_e_o_cobrado_e_nao_a_meta(): void
    {
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-DESC',
            'nome' => 'Tela com desconto',
            'preco_custo' => 100.00,
            'preco_venda' => 250.00,
        ]);

        // Vendedor cobrou 120: margem real de 16,67%, longe da meta de 45%.
        $orcamentoId = $this->criarOrcamentoComPeca(120.00, $pecaId);
        $item = DB::table('orcamento_itens')->where('orcamento_id', $orcamentoId)->first();

        $this->assertEqualsWithDelta(20.0, (float) $item->valor_margem, 0.01);
        $this->assertEqualsWithDelta(16.67, (float) $item->percentual_margem, 0.01);
    }

    public function test_modo_precificacao_e_resolvido_por_comparacao(): void
    {
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-MODO',
            'nome' => 'Peça',
            'preco_custo' => 100.00,
            'preco_venda' => 250.00,
        ]);

        // Cobrou 250. Com `respeitar_preco_venda` ligado e o calculado em 125
        // (custo 100 + 5% + 20% da categoria Insumos), o recomendado VIRA os
        // proprios 250 — entao recomendado e tabela coincidem, e `sugerido`
        // vence o empate por ser a informacao mais forte.
        $sugerido = $this->criarOrcamentoComPeca(250.00, $pecaId);
        $this->assertSame(
            ModoPrecificacao::SUGERIDO,
            DB::table('orcamento_itens')->where('orcamento_id', $sugerido)->value('modo_precificacao')
        );

        // Cobrou um valor próprio.
        $manual = $this->criarOrcamentoComPeca(199.00, $pecaId);
        $this->assertSame(
            ModoPrecificacao::MANUAL,
            DB::table('orcamento_itens')->where('orcamento_id', $manual)->value('modo_precificacao')
        );
    }

    /**
     * `syncItems()` apaga e reinsere tudo a cada save. Sem congelar, editar a
     * observacao de um orcamento aprovado o reprecificaria pelos parametros de
     * hoje — e um snapshot que se recalcula nao e snapshot de nada.
     */
    public function test_orcamento_aprovado_nao_e_reprecificado_ao_editar(): void
    {
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-FROZEN',
            'nome' => 'Peça congelada',
            'preco_custo' => 100.00,
            'preco_venda' => 250.00,
        ]);

        $orcamentoId = $this->criarOrcamentoComPeca(250.00, $pecaId);
        $custoOriginal = (float) DB::table('orcamento_itens')
            ->where('orcamento_id', $orcamentoId)->value('preco_custo_referencia');

        DB::table('orcamentos')->where('id', $orcamentoId)->update(['status' => Budget::STATUS_APPROVED]);

        // O custo da peça sobe DEPOIS da aprovação.
        DB::table('pecas')->where('id', $pecaId)->update(['preco_custo' => 180.00]);

        $this->putJson("/api/v1/orcamentos/{$orcamentoId}", [
            'tipo_orcamento' => 'previo',
            'origem' => 'manual',
            'titulo' => 'Orçamento de teste',
            'validade_dias' => 10,
            'observacoes' => 'Só editei a observação.',
            'itens' => [[
                'tipo_item' => 'peca',
                'referencia_id' => $pecaId,
                'descricao' => 'Peça congelada',
                'quantidade' => 1,
                'valor_unitario' => 250.00,
                'desconto' => 0,
                'acrescimo' => 0,
            ]],
        ]);

        $custoDepois = (float) DB::table('orcamento_itens')
            ->where('orcamento_id', $orcamentoId)->value('preco_custo_referencia');

        $this->assertEqualsWithDelta($custoOriginal, $custoDepois, 0.01);
        $this->assertEqualsWithDelta(100.0, $custoDepois, 0.01);
    }
}
