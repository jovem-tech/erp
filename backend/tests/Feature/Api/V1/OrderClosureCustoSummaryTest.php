<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Custo estimado no encerramento da OS — specs/037, Fase 5.
 *
 * `buildCostSummary()` somava `os_itens.preco_custo_referencia`: uma tabela com
 * 2.306 linhas e ZERO com custo preenchido — o ERP novo nunca escreve nela e o
 * legado parou em 30/04/2026. A tela de baixa mostrava "Custo estimado de
 * peças/serviços: R$ 0,00" em TODA OS, exatamente onde o dono decide se a OS
 * deu lucro.
 *
 * Agora vem das mesmas fontes da margem, para encerramento e DRE nunca
 * discordarem sobre a mesma OS.
 */
class OrderClosureCustoSummaryTest extends TestCase
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
            'os' => ['visualizar', 'editar', 'encerrar'],
            'estoque' => ['visualizar', 'editar'],
            'financeiro' => ['visualizar'],
        ]);
    }

    public function test_custo_de_peca_vem_da_saida_de_estoque_e_deixa_de_ser_zero(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $clienteId = $this->createClientRecord();
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $this->createEquipmentRecord($clienteId),
            'status' => 'aguardando_reparo',
            'valor_total' => 300,
            'valor_final' => 300,
        ]);

        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-CUSTO',
            'preco_custo' => 45.00,
            'preco_venda' => 120.00,
            'quantidade_atual' => 10,
        ]);

        $this->createMovimentacaoRecord([
            'peca_id' => $pecaId,
            'os_id' => $orderId,
            'tipo' => 'saida',
            'quantidade' => 2,
            'responsavel_id' => $admin->id,
        ]);

        $resumo = $this->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk()
            ->json('data.custo_summary');

        // 2 × R$ 45,00 — antes desta entrega o valor era R$ 0,00.
        $this->assertEqualsWithDelta(90.0, (float) $resumo['pecas'], 0.01);
        $this->assertEqualsWithDelta(90.0, (float) $resumo['total'], 0.01);
    }

    /**
     * OS sem consumo registrado continua zerada — e isso e verdade, nao
     * defeito: nenhuma peca saiu do estoque para ela.
     */
    public function test_os_sem_movimentacao_continua_zerada(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $clienteId = $this->createClientRecord();
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $this->createEquipmentRecord($clienteId),
            'status' => 'aguardando_reparo',
            'valor_final' => 150,
        ]);

        $resumo = $this->getJson("/api/v1/orders/{$orderId}/closure")
            ->assertOk()
            ->json('data.custo_summary');

        $this->assertEqualsWithDelta(0.0, (float) $resumo['total'], 0.01);
    }
}
