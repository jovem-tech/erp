<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\Movimentacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Entrada de estoque no lancamento financeiro — specs/039.
 *
 * A outra metade do ciclo que a 038 comecou. La a peca passou a SAIR pela OS;
 * aqui ela passa a ENTRAR pela compra. Ate esta entrega o unico caminho que
 * somava saldo era o CRUD de peca, gravando `quantidade_atual` direto e sem
 * gerar movimentacao — os tres "furos" documentados na 036.
 */
class FinanceiroEntradaEstoqueTest extends TestCase
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
            'estoque' => ['visualizar', 'criar', 'editar'],
        ]);
        // Operador de financeiro que nao mexe em estoque: cobre o caso negativo.
        $this->grantGroupPermissions(3, [
            'financeiro' => ['visualizar', 'criar', 'editar'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payloadCompra(array $itens, array $extra = []): array
    {
        return array_merge([
            'tipo' => 'pagar',
            'categoria' => 'Compra de peças',
            'descricao' => 'Nota do fornecedor',
            'valor' => 500.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'itens_estoque' => $itens,
        ], $extra);
    }

    private function saldo(int $pecaId): float
    {
        return (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual');
    }

    public function test_lancamento_com_itens_gera_entradas_e_soma_saldo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $tela = $this->createPecaRecord(['codigo' => 'PC-1', 'nome' => 'Tela', 'quantidade_atual' => 2, 'preco_custo' => 90]);
        $bateria = $this->createPecaRecord(['codigo' => 'PC-2', 'nome' => 'Bateria', 'quantidade_atual' => 0, 'preco_custo' => 40]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $tela, 'quantidade' => 3, 'custo_unitario' => 110],
            ['peca_id' => $bateria, 'quantidade' => 5, 'custo_unitario' => 30],
        ]))->assertCreated();

        $this->assertEqualsWithDelta(5.0, $this->saldo($tela), 0.0001);
        $this->assertEqualsWithDelta(5.0, $this->saldo($bateria), 0.0001);

        $financeiroId = (int) Financeiro::query()->value('id');
        $movimentos = Movimentacao::query()->where('financeiro_id', $financeiroId)->get();

        $this->assertCount(2, $movimentos);
        $this->assertTrue($movimentos->every(fn (Movimentacao $m): bool => $m->tipo === 'entrada'));

        // O custo por linha so existe neste instante: `financeiro.valor` guarda
        // apenas o total da nota.
        $this->assertEqualsWithDelta(
            110.0,
            (float) $movimentos->firstWhere('peca_id', $tela)->custo_unitario,
            0.0001
        );
    }

    /**
     * Duas linhas da mesma peca tem de virar UM ajuste de saldo — senao o lock
     * protege a primeira e a segunda corre solta (ver agregarPorPeca()).
     */
    public function test_duas_linhas_da_mesma_peca_somam_uma_vez_no_saldo(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-3', 'quantidade_atual' => 1]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 3, 'custo_unitario' => 10],
            ['peca_id' => $peca, 'quantidade' => 2, 'custo_unitario' => 10],
        ]))->assertCreated();

        $this->assertEqualsWithDelta(6.0, $this->saldo($peca), 0.0001);
        $this->assertSame(2, Movimentacao::query()->where('peca_id', $peca)->count());
    }

    public function test_preco_custo_e_atualizado_e_linha_sem_custo_nao_toca_no_cadastro(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $comCusto = $this->createPecaRecord(['codigo' => 'PC-4', 'preco_custo' => 90, 'quantidade_atual' => 0]);
        $semCusto = $this->createPecaRecord(['codigo' => 'PC-5', 'preco_custo' => 70, 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $comCusto, 'quantidade' => 1, 'custo_unitario' => 115.50],
            ['peca_id' => $semCusto, 'quantidade' => 1],
        ]))->assertCreated();

        // Fato novo trazido pela nota: e assim que preco de fornecedor sobe.
        $this->assertEqualsWithDelta(115.50, (float) DB::table('pecas')->where('id', $comCusto)->value('preco_custo'), 0.01);
        // "Nao sei o custo" nao pode virar "o custo e zero".
        $this->assertEqualsWithDelta(70.0, (float) DB::table('pecas')->where('id', $semCusto)->value('preco_custo'), 0.01);
    }

    public function test_preco_venda_so_muda_quando_enviado_explicitamente(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $intocada = $this->createPecaRecord(['codigo' => 'PC-6', 'preco_venda' => 200, 'quantidade_atual' => 0]);
        $aplicada = $this->createPecaRecord(['codigo' => 'PC-7', 'preco_venda' => 200, 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $intocada, 'quantidade' => 1, 'custo_unitario' => 50],
            ['peca_id' => $aplicada, 'quantidade' => 1, 'custo_unitario' => 50, 'preco_venda' => 149.90],
        ]))->assertCreated();

        // Reprecificar em silencio uma peca que o dono precificou a mao seria
        // passar por cima dele: so muda quando ele clicou "Aplicar".
        $this->assertEqualsWithDelta(200.0, (float) DB::table('pecas')->where('id', $intocada)->value('preco_venda'), 0.01);
        $this->assertEqualsWithDelta(149.90, (float) DB::table('pecas')->where('id', $aplicada)->value('preco_venda'), 0.01);
    }

    /**
     * A regressao mais provavel desta entrega: a compra aconteceu UMA vez, o que
     * se parcela e o pagamento. Se a chamada escorregar para depois do ramo de
     * parcelas no create(), cada parcela gera sua propria entrada.
     */
    public function test_compra_parcelada_gera_entrada_uma_unica_vez(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-8', 'quantidade_atual' => 0]);
        $cartaoId = DB::table('financeiro_cartoes_credito')->insertGetId([
            'nome' => 'Cartão da oficina',
            'dia_fechamento' => 10,
            'dia_vencimento' => 20,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra(
            [['peca_id' => $peca, 'quantidade' => 4, 'custo_unitario' => 25]],
            [
                'valor' => 300.00,
                'forma_pagamento' => 'cartao_credito',
                'cartao_credito_id' => $cartaoId,
                'data_compra' => now()->toDateString(),
                'parcelas' => 3,
            ]
        ))->assertCreated();

        $this->assertSame(3, Financeiro::query()->count());
        $this->assertSame(1, Movimentacao::query()->count());
        $this->assertEqualsWithDelta(4.0, $this->saldo($peca), 0.0001);
    }

    /**
     * Rollback A — o lancamento falha, nenhuma entrada sobra.
     */
    public function test_falha_ao_criar_lancamento_nao_deixa_entrada_gravada(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-9', 'quantidade_atual' => 7]);

        Financeiro::created(static function (): void {
            throw new RuntimeException('boom');
        });

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 3, 'custo_unitario' => 10],
        ]))->assertStatus(422);

        $this->assertSame(0, Movimentacao::query()->count());
        $this->assertEqualsWithDelta(7.0, $this->saldo($peca), 0.0001);
    }

    /**
     * Rollback B — a entrada falha, nenhum lancamento sobra.
     *
     * E o teste que prova que a fronteira transacional esta no lugar certo:
     * quebra na hora se alguem mover a chamada para fora do DB::transaction do
     * create().
     */
    public function test_falha_ao_gravar_entrada_nao_deixa_lancamento_criado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-10', 'quantidade_atual' => 7]);

        Movimentacao::creating(static function (): void {
            throw new RuntimeException('boom');
        });

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 3, 'custo_unitario' => 10],
        ]))->assertStatus(422);

        $this->assertSame(0, Financeiro::query()->count());
        $this->assertEqualsWithDelta(7.0, $this->saldo($peca), 0.0001);
    }

    public function test_sem_permissao_de_estoque_o_lancamento_com_itens_e_recusado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-11', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 1, 'custo_unitario' => 10],
        ]))->assertStatus(403);

        $this->assertSame(0, Financeiro::query()->count());
        $this->assertSame(0, Movimentacao::query()->count());
    }

    /**
     * Sem itens, quem so tem financeiro continua lancando normalmente — a
     * autorizacao dupla nao pode virar pedagio para o resto do modulo.
     */
    public function test_lancamento_sem_itens_nao_exige_permissao_de_estoque(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Energia',
            'descricao' => 'Conta de luz',
            'valor' => 300.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ])->assertCreated();
    }

    public function test_itens_estoque_em_update_sao_recusados(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-12', 'quantidade_atual' => 4]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 1, 'custo_unitario' => 10],
        ]))->assertCreated();

        $financeiroId = (int) Financeiro::query()->value('id');

        // Recusa explicita, nunca ignorar em silencio: ignorar faria o operador
        // acreditar que salvou.
        $this->putJson("/api/v1/financeiro/{$financeiroId}", [
            'itens_estoque' => [['peca_id' => $peca, 'quantidade' => 9, 'custo_unitario' => 10]],
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['itens_estoque']]]);

        $this->assertEqualsWithDelta(5.0, $this->saldo($peca), 0.0001);
    }

    public function test_soma_dos_itens_maior_que_o_valor_e_recusada(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-13', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra(
            [['peca_id' => $peca, 'quantidade' => 10, 'custo_unitario' => 100]],
            ['valor' => 500.00]
        ))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['itens_estoque']]]);

        $this->assertSame(0, Movimentacao::query()->count());
    }

    /**
     * Soma MENOR passa: a diferenca pode ser frete, imposto ou item que nao e
     * peca. A tela avisa; o servidor nao bloqueia.
     */
    public function test_soma_dos_itens_menor_que_o_valor_e_aceita(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-14', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra(
            [['peca_id' => $peca, 'quantidade' => 2, 'custo_unitario' => 100]],
            ['valor' => 500.00]
        ))->assertCreated();

        $this->assertEqualsWithDelta(2.0, $this->saldo($peca), 0.0001);
    }

    public function test_receber_com_itens_estoque_e_recusado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-15', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra(
            [['peca_id' => $peca, 'quantidade' => 1, 'custo_unitario' => 10]],
            ['tipo' => 'receber', 'categoria' => 'Serviço']
        ))->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['itens_estoque']]]);
    }

    public function test_excluir_lancamento_com_entrada_e_bloqueado(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-16', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 2, 'custo_unitario' => 10],
        ]))->assertCreated();

        $financeiroId = (int) Financeiro::query()->value('id');

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_BLOCKED_ENTRADA_ESTOQUE');

        $this->assertSame(1, Financeiro::query()->count());
    }

    /**
     * O caso que o dono descreveu: lancamento de peca por equivoco, ou peca
     * lancada que nunca chegou. Cancelar devolve o saldo.
     */
    public function test_cancelar_lancamento_estorna_a_entrada(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-17', 'quantidade_atual' => 1]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 4, 'custo_unitario' => 10],
        ]))->assertCreated();

        $this->assertEqualsWithDelta(5.0, $this->saldo($peca), 0.0001);

        $financeiroId = (int) Financeiro::query()->value('id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")
            ->assertOk()
            ->assertJsonPath('data.entradas_estornadas', 1);

        $this->assertEqualsWithDelta(1.0, $this->saldo($peca), 0.0001);

        $estorno = Movimentacao::query()
            ->where('financeiro_id', $financeiroId)
            ->where('tipo', 'saida')
            ->first();

        $this->assertNotNull($estorno);
        $this->assertStringContainsString('Estorno', (string) $estorno->motivo);
    }

    /**
     * A peca comprada ja saiu (aplicada em OS ou vendida). Estornar deixaria o
     * saldo negativo — decisao humana, e o erro nomeia os ofensores.
     */
    public function test_cancelar_com_peca_ja_consumida_exige_confirmacao(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $peca = $this->createPecaRecord(['codigo' => 'PC-18', 'quantidade_atual' => 0]);

        $this->postJson('/api/v1/financeiro', $this->payloadCompra([
            ['peca_id' => $peca, 'quantidade' => 3, 'custo_unitario' => 10],
        ]))->assertCreated();

        $financeiroId = (int) Financeiro::query()->value('id');

        // Simula o consumo das peças por outro caminho (OS, venda).
        DB::table('pecas')->where('id', $peca)->update(['quantidade_atual' => 1]);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('error.details.itens.0.peca_id', $peca);

        // Recusado sem confirmação: o título continua ativo e o saldo intacto.
        $this->assertSame('pendente', (string) Financeiro::query()->value('status'));
        $this->assertEqualsWithDelta(1.0, $this->saldo($peca), 0.0001);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'confirmar_estoque_insuficiente' => true,
        ])->assertOk();

        // Saldo negativo e o sinal honesto de que o inventario precisa de acerto.
        $this->assertEqualsWithDelta(-2.0, $this->saldo($peca), 0.0001);
    }
}
