<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Models\EstoqueReserva;
use App\Services\Estoque\EstoqueReservaService;
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

    /**
     * Cria um orcamento APROVADO da OS com a peca dentro, e reconcilia a
     * reserva — o mesmo caminho que dispatchForApproval/finalizeApproval usam.
     */
    private function orcamentoAprovadoComPeca(int $osId, int $pecaId, float $quantidade): int
    {
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-RES-'.$osId.'-'.$pecaId,
            'status' => Budget::STATUS_APPROVED,
            'os_id' => $osId,
            'aprovado_em' => now(),
        ]);

        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'quantidade' => $quantidade,
        ]);

        app(EstoqueReservaService::class)->sincronizar(Budget::query()->findOrFail($budgetId));

        return $budgetId;
    }

    /**
     * specs/040 — o caso que decide se a reserva presta.
     *
     * Sem somar de volta a reserva do PROPRIO orcamento, a peca guardada para
     * este aparelho bloquearia exatamente a baixa que ela existia para proteger.
     */
    public function test_reserva_do_proprio_orcamento_nao_bloqueia_a_propria_baixa(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-RES-PROPRIA',
            'quantidade_atual' => 2,
        ]);

        $this->orcamentoAprovadoComPeca($osId, $pecaId, 2);

        // Reservou o saldo inteiro: para terceiros nao sobra nada.
        $this->assertEqualsWithDelta(
            2.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'),
            0.0001
        );

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 2]],
        ])->assertOk()->assertJsonPath('data.aplicacao.divergente', false);

        // Peca saiu, e a reserva foi consumida junto — nao pode sobrar promessa
        // pendurada sobre um saldo que ja foi embora.
        $this->assertEqualsWithDelta(
            0.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
            0.0001
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'),
            0.0001
        );
        $this->assertDatabaseHas('estoque_reservas', [
            'peca_id' => $pecaId,
            'status' => EstoqueReserva::STATUS_CONSUMIDA,
        ]);
    }

    /**
     * specs/040 — reserva de OUTRO orcamento bloqueia, e a mensagem nomeia o
     * ofensor (mesma regra da 038: erro que nao diz qual peca faltou obriga o
     * tecnico a cacar linha por linha).
     */
    public function test_reserva_de_outro_orcamento_bloqueia_a_baixa(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-RES-TERCEIRO',
            'nome' => 'Tela LCD',
            'quantidade_atual' => 1,
        ]);

        // Orcamento de OUTRO aparelho ja prometeu a unica peca ao cliente.
        $outroBudget = $this->createBudgetRecord([
            'numero' => 'ORC-OUTRO',
            'status' => Budget::STATUS_WAITING_REPLY,
        ]);
        $this->createBudgetItemRecord($outroBudget, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'quantidade' => 1,
        ]);
        app(EstoqueReservaService::class)->sincronizar(Budget::query()->findOrFail($outroBudget));

        $resposta = $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 1]],
        ]);

        // Mesmo contrato do saldo insuficiente comum (422 + OS_ESTOQUE_INSUFICIENTE):
        // para quem esta na tela, "a peca nao esta disponivel" e um caso so,
        // esteja ela vendida ou prometida a outro aparelho.
        $resposta
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'OS_ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('error.details.itens.0.codigo', 'PC-RES-TERCEIRO')
            ->assertJsonPath('error.details.itens.0.disponivel', 0.0);

        // Transacao inteira voltou: nem movimento, nem baixa de saldo.
        $this->assertDatabaseCount('movimentacoes', 0);
        $this->assertEqualsWithDelta(
            1.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual'),
            0.0001
        );
    }

    /**
     * specs/040 — baixa parcial nao pode zerar a promessa do que ainda falta
     * aplicar, nem contar o consumo duas vezes.
     */
    public function test_baixa_parcial_deixa_a_reserva_remanescente(): void
    {
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $osId = $this->criarOs();
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-RES-PARCIAL',
            'quantidade_atual' => 10,
        ]);

        $this->orcamentoAprovadoComPeca($osId, $pecaId, 3);

        $this->postJson("/api/v1/orders/{$osId}/estoque/aplicar", [
            'itens' => [['peca_id' => $pecaId, 'quantidade' => 1]],
        ])->assertOk();

        $this->assertEqualsWithDelta(
            2.0,
            (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada'),
            0.0001,
            'Sobraram 2 por aplicar, e elas continuam prometidas.'
        );
        $this->assertDatabaseHas('estoque_reservas', [
            'peca_id' => $pecaId,
            'status' => EstoqueReserva::STATUS_ATIVA,
            'quantidade_consumida' => 1,
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
