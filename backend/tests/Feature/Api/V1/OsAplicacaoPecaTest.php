<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Aplicacao de peca na OS — specs/038.
 *
 * O elo que faltava para o CMV existir. Ate esta entrega NENHUM caminho do
 * sistema criava movimentacao de estoque a partir de uma OS: o consumo era
 * 100% manual, ninguem lancava, e o resultado era CMV R$ 0,00 em 2.187 OS
 * entregues e pagas — com a margem de contribuicao e o DRE gerencial prontos e
 * famintos.
 */
class OsAplicacaoPecaTest extends TestCase
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
            'financeiro' => ['visualizar', 'editar'],
        ]);
        // Técnico que não mexe em estoque: cobre o caso negativo.
        $this->grantGroupPermissions(3, [
            'os' => ['visualizar', 'editar'],
        ]);
    }

    private function criarOs(): int
    {
        $clienteId = $this->createClientRecord();

        return $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $this->createEquipmentRecord($clienteId),
            'status' => 'aguardando_reparo',
            'valor_total' => 400,
            'valor_final' => 400,
        ]);
    }

    public function test_aplicar_peca_gera_saida_de_estoque_e_baixa_o_saldo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-OS',
            'nome' => 'Tela LCD',
            'preco_custo' => 90.00,
            'quantidade_atual' => 5,
        ]);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 2]],
        ])->assertOk()->assertJsonPath('data.aplicacao.aplicadas', 1);

        // Saldo decrementado atomicamente.
        $this->assertEqualsWithDelta(
            3.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
            0.0001
        );

        // A movimentação É o registro de aplicação: `os_id` + `saida`.
        $movimento = DB::table('movimentacoes')->where('os_id', $osId)->first();
        $this->assertNotNull($movimento);
        $this->assertSame('saida', $movimento->tipo);
        $this->assertEqualsWithDelta(2.0, (float) $movimento->quantidade, 0.0001);
    }

    /**
     * O teste que prova o motivo da entrega inteira: com a peca aplicada, o CMV
     * da OS deixa de ser zero.
     */
    public function test_cmv_da_os_deixa_de_ser_zero(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-CMV',
            'preco_custo' => 90.00,
            'quantidade_atual' => 5,
        ]);

        $antes = (float) $this->postJson('/api/v1/financeiro/margem/'.$osId.'/recalcular')
            ->json('data.margem.custo_pecas');
        $this->assertEqualsWithDelta(0.0, $antes, 0.01);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 2]],
        ])->assertOk();

        DB::table('os')->where('id', $osId)->update(['status' => 'entregue_reparado_pago']);

        $depois = (float) $this->postJson('/api/v1/financeiro/margem/'.$osId.'/recalcular')
            ->json('data.margem.custo_pecas');

        // 2 × R$ 90,00 — o número que estava zerado em 2.187 OS.
        $this->assertEqualsWithDelta(180.0, $depois, 0.01);
    }

    /**
     * Saldo insuficiente para com a operacao e devolve os ofensores, no mesmo
     * formato que o PDV ja consome.
     */
    public function test_saldo_insuficiente_bloqueia_e_lista_os_ofensores(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord(['codigo' => 'PC-FALTA', 'quantidade_atual' => 1]);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 5]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OS_ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('error.details.itens.0.codigo', 'PC-FALTA');

        // Nada gravado: a transação inteira voltou.
        $this->assertDatabaseCount('movimentacoes', 0);
    }

    /**
     * Confirmacao explicita permite saldo negativo — mesma decisao do PDV: e o
     * sinal honesto de que o inventario precisa de acerto, e recusar faria o
     * tecnico contornar por fora do sistema.
     */
    public function test_confirmacao_explicita_permite_saldo_negativo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord(['codigo' => 'PC-NEG', 'quantidade_atual' => 1]);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 3]],
            'confirmar_estoque_insuficiente' => true,
        ])->assertOk()->assertJsonPath('data.aplicacao.divergente', true);

        $this->assertEqualsWithDelta(
            -2.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
            0.0001
        );
    }

    public function test_sem_permissao_de_estoque_nao_aplica(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord(['codigo' => 'PC-RBAC', 'quantidade_atual' => 5]);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 1]],
        ])->assertStatus(403);
    }
}
