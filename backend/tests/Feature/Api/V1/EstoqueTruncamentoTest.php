<?php

namespace Tests\Feature\Api\V1;

use App\Services\Pdf\Contexts\BudgetPdfContextFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Truncamento de quantidade — specs/036-estoque-nucleo-razao.
 *
 * A v5.58.0.0 alargou as colunas para DECIMAL(14,4) mas deixou casts `(int)`
 * espalhados por serializadores, cards e PDFs. O resultado era pior que o
 * problema original: o banco guardava 2,5 e a tela mostrava 2, sem aviso.
 *
 * Cada caso aqui trava um ponto que truncava.
 */
class EstoqueTruncamentoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'estoque' => ['visualizar', 'criar', 'editar'],
            'vendas' => ['visualizar', 'criar'],
            'dashboard' => ['visualizar'],
            'orcamentos' => ['visualizar', 'criar', 'editar'],
        ]);
    }

    public function test_ficha_da_peca_mostra_movimentacao_fracionada(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $pecaId = $this->createPecaRecord(['codigo' => 'PC-CABO', 'quantidade_atual' => 3]);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'saida',
            'quantidade' => 0.5,
            'motivo' => 'Meio metro',
        ])->assertOk();

        $response = $this->getJson("/api/v1/estoque/{$pecaId}")->assertOk();

        // Com (int) no serializer, a movimentação aparecia como 0.
        $this->assertEqualsWithDelta(
            0.5,
            (float) $response->json('data.peca.movimentacoes.0.quantidade'),
            0.0001
        );
    }

    public function test_busca_do_pdv_mostra_saldo_fracionado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->createPecaRecord([
            'codigo' => 'PC-SOLDA',
            'nome' => 'Solda em fio',
            'quantidade_atual' => 1.25,
        ]);

        $response = $this->getJson('/api/v1/vendas/itens/buscar?search=PC-SOLDA')->assertOk();

        $item = collect($response->json('data.itens') ?? [])->firstWhere('codigo', 'PC-SOLDA');

        $this->assertNotNull($item, 'peça não encontrada na busca do PDV');
        // Com (int), o saldo 1,25 chegava ao PDV como 1 e o operador vendia a mais.
        $this->assertEqualsWithDelta(1.25, (float) $item['saldo'], 0.0001);
    }

    public function test_card_de_estoque_baixo_nao_se_contradiz(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->createPecaRecord([
            'codigo' => 'PC-PASTA',
            'quantidade_atual' => 0.5,
            'estoque_minimo' => 1.5,
        ]);

        $response = $this->getJson('/api/v1/dashboard/summary')->assertOk();

        $item = collect($response->json('data.low_stock') ?? [])->firstWhere('codigo', 'PC-PASTA');

        $this->assertNotNull($item, 'peça não apareceu no card de estoque baixo');
        // Com (int) nos dois, o card dizia "0 em estoque · mínimo 1".
        $this->assertEqualsWithDelta(0.5, (float) $item['quantidade_atual'], 0.0001);
        $this->assertEqualsWithDelta(1.5, (float) $item['estoque_minimo'], 0.0001);
    }

    /**
     * O pior dos truncamentos: o documento que o cliente assina. Um orçamento
     * de 1,5 h de serviço imprimia "1" na coluna de quantidade, divergindo do
     * valor total — que sempre usou a quantidade real.
     */
    public function test_pdf_de_orcamento_imprime_quantidade_fracionada(): void
    {
        $clienteId = $this->createClientRecord();
        $orcamentoId = $this->createBudgetRecord(['cliente_id' => $clienteId]);

        $this->createBudgetItemRecord($orcamentoId, [
            'tipo_item' => 'servico',
            'descricao' => 'Mão de obra especializada',
            'quantidade' => 1.5,
            'valor_unitario' => 100,
            'total' => 150,
        ]);

        $contexto = app(BudgetPdfContextFactory::class)->build(['budget_id' => $orcamentoId]);

        $linha = collect($contexto['itens'] ?? [])->firstWhere('descricao', 'Mão de obra especializada');

        $this->assertNotNull($linha, 'item não chegou ao contexto do PDF');
        // Com (int), esta linha imprimia 1 e contradizia o total de R$ 150,00.
        $this->assertEqualsWithDelta(1.5, (float) $linha['quantidade'], 0.0001);
    }
}
