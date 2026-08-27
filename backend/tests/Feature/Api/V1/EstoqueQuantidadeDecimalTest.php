<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Quantidade de estoque em fracao — specs/036-estoque-nucleo-razao.
 *
 * Insumo se mede em fracao: 0,5 m de cabo, 1,5 g de pasta termica, meio metro
 * de solda. Enquanto `pecas.quantidade_atual` e `movimentacoes.quantidade`
 * eram INT, qualquer fracao era truncada em silencio — sem erro, sem aviso, e
 * o saldo simplesmente errado.
 */
class EstoqueQuantidadeDecimalTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'estoque' => ['visualizar', 'criar', 'editar', 'excluir', 'encerrar', 'exportar', 'importar'],
        ]);
    }

    public function test_movimentacao_fracionada_e_gravada_sem_truncar(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-CABO',
            'nome' => 'Cabo flat (metro)',
            'quantidade_atual' => 3,
        ]);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'saida',
            'quantidade' => 0.5,
            'motivo' => 'Meio metro aplicado no reparo',
        ])->assertOk();

        // Com INT, 0,5 virava 0 e o saldo continuaria 3.
        $this->assertEqualsWithDelta(
            2.5,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
            0.0001
        );

        $this->assertEqualsWithDelta(
            0.5,
            (float) DB::table('movimentacoes')->where('peca_id', $pecaId)->value('quantidade'),
            0.0001
        );
    }

    /**
     * O cast do model e o segundo lugar onde a fracao poderia morrer: com
     * `'quantidade_atual' => 'integer'` o Eloquent trunca ao ler, mesmo com a
     * coluna correta no banco.
     */
    public function test_model_devolve_fracao_ao_ler(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $pecaId = $this->createPecaRecord(['codigo' => 'PC-PASTA', 'quantidade_atual' => 1.25]);

        $response = $this->getJson("/api/v1/estoque/{$pecaId}")->assertOk();

        $this->assertEqualsWithDelta(1.25, (float) $response->json('data.peca.quantidade_atual'), 0.0001);
    }

    /**
     * Guarda contra regressao de locale: a atualizacao de saldo e feita por
     * expressao SQL crua, e um float interpolado sob pt_BR sairia como "1,5",
     * quebrando a query. Ver SaleStockService::quantidadeSql().
     */
    public function test_saldo_fracionado_sobrevive_a_locale_pt_br(): void
    {
        $anterior = setlocale(LC_NUMERIC, 0);
        setlocale(LC_NUMERIC, 'pt_BR.UTF-8', 'pt_BR', 'C');

        try {
            Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

            $pecaId = $this->createPecaRecord(['codigo' => 'PC-SOLDA', 'quantidade_atual' => 10]);

            $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
                'tipo' => 'entrada',
                'quantidade' => 2.75,
                'motivo' => 'Compra fracionada',
            ])->assertOk();

            $this->assertEqualsWithDelta(
                12.75,
                (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
                0.0001
            );
        } finally {
            setlocale(LC_NUMERIC, $anterior !== false ? $anterior : 'C');
        }
    }
}
