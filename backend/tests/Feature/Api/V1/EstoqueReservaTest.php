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
 * Reserva de peca presa ao orcamento — specs/040.
 *
 * Ate esta entrega `orcamento_itens` apontava para `pecas` sem FK e sem
 * nenhuma validacao de saldo: dois orcamentos podiam prometer a mesma peca
 * unica ao cliente e ninguem descobria ate o tecnico abrir a gaveta.
 */
class EstoqueReservaTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private EstoqueReservaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        $this->service = app(EstoqueReservaService::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function orcamentoComPeca(int $pecaId, float $quantidade = 1, array $overrides = []): Budget
    {
        $budgetId = $this->createBudgetRecord($overrides);

        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'quantidade' => $quantidade,
        ]);

        return Budget::query()->findOrFail($budgetId);
    }

    private function reservado(int $pecaId): float
    {
        return (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_reservada');
    }

    public function test_rascunho_nao_reserva_e_envio_reserva(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_DRAFT]);

        $this->service->sincronizar($budget);

        $this->assertSame(0.0, $this->reservado($pecaId), 'Rascunho não pode segurar peça.');
        $this->assertDatabaseCount('estoque_reservas', 0);

        // O gatilho da regra do dono: a reserva nasce quando o orçamento é
        // ENVIADO ao cliente.
        $budget->forceFill(['status' => Budget::STATUS_WAITING_REPLY])->save();
        $this->service->sincronizar($budget->fresh());

        $this->assertSame(2.0, $this->reservado($pecaId));
        $this->assertDatabaseHas('estoque_reservas', [
            'orcamento_id' => $budget->id,
            'peca_id' => $pecaId,
            'quantidade' => 2,
            'status' => EstoqueReserva::STATUS_ATIVA,
        ]);
    }

    public function test_falha_de_disparo_nao_reserva(): void
    {
        // `pendente_envio` é o estado de FALHA de envio: o orçamento não chegou
        // ao cliente, então não há promessa a honrar.
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_PENDING_SEND]);

        $this->service->sincronizar($budget);

        $this->assertSame(0.0, $this->reservado($pecaId));
    }

    public function test_duas_linhas_da_mesma_peca_viram_uma_reserva(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 10]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_WAITING_REPLY]);

        // Segunda linha da MESMA peça: a UNIQUE(orcamento_id, peca_id) exige que
        // as duas somem numa reserva só.
        $this->createBudgetItemRecord((int) $budget->id, [
            'tipo_item' => 'peca',
            'referencia_id' => $pecaId,
            'quantidade' => 3,
            'ordem' => 2,
        ]);

        $this->service->sincronizar($budget);

        $this->assertDatabaseCount('estoque_reservas', 1);
        $this->assertSame(5.0, $this->reservado($pecaId));
    }

    public function test_rejeitado_cancelado_e_vencido_liberam(): void
    {
        foreach ([Budget::STATUS_REJECTED, Budget::STATUS_CANCELLED, Budget::STATUS_EXPIRED] as $indice => $status) {
            $pecaId = $this->createPecaRecord([
                'codigo' => 'PC9000'.$indice,
                'quantidade_atual' => 5,
            ]);
            $budget = $this->orcamentoComPeca($pecaId, 2, [
                'numero' => 'ORC-LIB-'.$indice,
                'status' => Budget::STATUS_WAITING_REPLY,
            ]);

            $this->service->sincronizar($budget);
            $this->assertSame(2.0, $this->reservado($pecaId), "Reserva devia existir antes de $status.");

            $budget->forceFill(['status' => $status])->save();
            $this->service->sincronizar($budget->fresh());

            $this->assertSame(0.0, $this->reservado($pecaId), "Status $status devia liberar a reserva.");
            $this->assertDatabaseHas('estoque_reservas', [
                'orcamento_id' => $budget->id,
                'peca_id' => $pecaId,
                'status' => EstoqueReserva::STATUS_LIBERADA,
            ]);
        }
    }

    public function test_editar_itens_do_orcamento_enviado_ajusta_a_reserva(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 10]);
        $outraId = $this->createPecaRecord(['codigo' => 'PC00002', 'quantidade_atual' => 10]);
        $budget = $this->orcamentoComPeca($pecaId, 4, ['status' => Budget::STATUS_WAITING_REPLY]);

        $this->service->sincronizar($budget);
        $this->assertSame(4.0, $this->reservado($pecaId));

        // Reproduz o delete-all + insert de BudgetWorkflowService::syncItems():
        // os ids de `orcamento_itens` NÃO sobrevivem a uma edição, e é por isso
        // que a reserva é chaveada por (orcamento_id, peca_id).
        DB::table('orcamento_itens')->where('orcamento_id', $budget->id)->delete();
        $this->createBudgetItemRecord((int) $budget->id, [
            'tipo_item' => 'peca',
            'referencia_id' => $outraId,
            'quantidade' => 1,
        ]);

        $this->service->sincronizar($budget->fresh());

        $this->assertSame(0.0, $this->reservado($pecaId), 'Peça removida do orçamento devia soltar.');
        $this->assertSame(1.0, $this->reservado($outraId), 'Peça nova devia reservar.');
        $this->assertDatabaseCount('estoque_reservas', 2);
    }

    public function test_liberacao_manual_nao_e_desfeita_pelo_reenvio(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_WAITING_REPLY]);

        $this->service->sincronizar($budget);
        $this->assertTrue($this->service->liberarManual((int) $budget->id, $pecaId, 1, 'Urgência noutro aparelho'));
        $this->assertSame(0.0, $this->reservado($pecaId));

        // Reenviar o orçamento não pode ressuscitar a reserva: senão o botão
        // "liberar" seria mentira.
        $this->service->sincronizar($budget->fresh());

        $this->assertSame(0.0, $this->reservado($pecaId));
        $this->assertDatabaseHas('estoque_reservas', [
            'orcamento_id' => $budget->id,
            'peca_id' => $pecaId,
            'status' => EstoqueReserva::STATUS_LIBERADA,
            'liberacao_manual' => 1,
        ]);
    }

    public function test_mudar_a_quantidade_ressuscita_a_reserva_liberada_na_mao(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 5]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_WAITING_REPLY]);

        $this->service->sincronizar($budget);
        $this->service->liberarManual((int) $budget->id, $pecaId, 1, 'Soltou');

        // Intenção nova do operador: editou a quantidade do item.
        DB::table('orcamento_itens')->where('orcamento_id', $budget->id)->update(['quantidade' => 3]);
        $this->service->sincronizar($budget->fresh());

        $this->assertSame(3.0, $this->reservado($pecaId));
    }

    public function test_reserva_acima_do_saldo_e_permitida(): void
    {
        // É exatamente isso que produz a marcação "A encomendar": a promessa
        // fica registrada, e a compra é que fecha a conta.
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 1]);
        $budget = $this->orcamentoComPeca($pecaId, 4, ['status' => Budget::STATUS_WAITING_REPLY]);

        $this->service->sincronizar($budget);

        $this->assertSame(4.0, $this->reservado($pecaId));
        $disponibilidade = $this->service->disponibilidade([$pecaId]);
        $this->assertSame(-3.0, $disponibilidade[$pecaId]['disponivel']);
    }

    public function test_consumo_parcial_deixa_remanescente_e_total_encerra(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 10]);
        $budget = $this->orcamentoComPeca($pecaId, 3, ['status' => Budget::STATUS_APPROVED]);

        $this->service->sincronizar($budget);
        $this->assertSame(3.0, $this->reservado($pecaId));

        $this->service->consumir((int) $budget->id, [['peca_id' => $pecaId, 'quantidade' => 1]]);

        $this->assertSame(2.0, $this->reservado($pecaId), 'Baixa parcial deixa o remanescente prometido.');
        $this->assertDatabaseHas('estoque_reservas', [
            'orcamento_id' => $budget->id,
            'status' => EstoqueReserva::STATUS_ATIVA,
            'quantidade_consumida' => 1,
        ]);

        $this->service->consumir((int) $budget->id, [['peca_id' => $pecaId, 'quantidade' => 2]]);

        $this->assertSame(0.0, $this->reservado($pecaId));
        $this->assertDatabaseHas('estoque_reservas', [
            'orcamento_id' => $budget->id,
            'status' => EstoqueReserva::STATUS_CONSUMIDA,
        ]);
    }

    public function test_baixa_maior_que_a_reserva_nao_gera_consumo_negativo(): void
    {
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 10]);
        $budget = $this->orcamentoComPeca($pecaId, 2, ['status' => Budget::STATUS_APPROVED]);

        $this->service->sincronizar($budget);
        // Técnico aplicou mais peça do que o orçamento previa.
        $this->service->consumir((int) $budget->id, [['peca_id' => $pecaId, 'quantidade' => 5]]);

        $this->assertSame(0.0, $this->reservado($pecaId));
        $this->assertSame(
            2.0,
            (float) DB::table('estoque_reservas')->where('orcamento_id', $budget->id)->value('quantidade_consumida'),
            'Consumo nunca pode passar do que foi reservado.'
        );
    }

    public function test_disponivel_soma_de_volta_a_reserva_do_proprio_orcamento(): void
    {
        // O detalhe que decide se a feature presta: a reserva não pode bloquear
        // justamente a baixa que ela protegia.
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 3]);
        $budget = $this->orcamentoComPeca($pecaId, 3, ['status' => Budget::STATUS_APPROVED]);

        $this->service->sincronizar($budget);

        $deTerceiros = $this->service->disponibilidade([$pecaId]);
        $this->assertSame(0.0, $deTerceiros[$pecaId]['disponivel'], 'Para os outros, não sobra nada.');

        $doDono = $this->service->disponibilidade([$pecaId], (int) $budget->id);
        $this->assertSame(3.0, $doDono[$pecaId]['disponivel'], 'Para o próprio orçamento, a peça está lá.');
    }

    public function test_recalcular_repara_cache_divergente(): void
    {
        // A soma absoluta dentro do lock é auto-reparável de propósito: qualquer
        // deriva some na próxima sincronização.
        $pecaId = $this->createPecaRecord(['quantidade_atual' => 10, 'quantidade_reservada' => 99]);
        $budgetId = $this->createBudgetRecord(['status' => Budget::STATUS_WAITING_REPLY]);
        $this->createEstoqueReservaRecord([
            'peca_id' => $pecaId,
            'orcamento_id' => $budgetId,
            'quantidade' => 2,
        ]);

        $this->service->recalcular([$pecaId]);

        $this->assertSame(2.0, $this->reservado($pecaId));
    }

    /**
     * specs/040 — a lista de compra e a outra metade do pedido do dono: saber
     * se a peca esta na gaveta ou precisa ser encomendada.
     */
    public function test_pecas_a_comprar_soma_a_falta_de_todos_os_orcamentos(): void
    {
        $this->grantGroupPermissions(1, ['estoque' => ['visualizar']]);
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        // Peça com 1 na gaveta e 4 prometidas em DOIS orçamentos diferentes.
        $faltante = $this->createPecaRecord([
            'codigo' => 'PC-FALTA',
            'nome' => 'Tela LCD',
            'quantidade_atual' => 1,
            'preco_custo' => 90.00,
        ]);
        foreach ([3, 1] as $indice => $quantidade) {
            $budget = $this->orcamentoComPeca($faltante, $quantidade, [
                'numero' => 'ORC-COMPRA-'.$indice,
                'status' => Budget::STATUS_WAITING_REPLY,
            ]);
            $this->service->sincronizar($budget);
        }

        // Peça prometida DENTRO do que existe: não é compra.
        $suficiente = $this->createPecaRecord([
            'codigo' => 'PC-SOBRA',
            'quantidade_atual' => 10,
        ]);
        $this->service->sincronizar(
            $this->orcamentoComPeca($suficiente, 2, [
                'numero' => 'ORC-SOBRA',
                'status' => Budget::STATUS_WAITING_REPLY,
            ])
        );

        $resposta = $this->getJson('/api/v1/estoque/a-comprar')->assertOk();

        $resposta
            ->assertJsonPath('data.total_itens', 1)
            ->assertJsonPath('data.pecas.0.codigo', 'PC-FALTA')
            ->assertJsonPath('data.pecas.0.reservado', 4.0)
            ->assertJsonPath('data.pecas.0.quantidade_atual', 1.0)
            ->assertJsonPath('data.pecas.0.falta', 3.0)
            ->assertJsonPath('data.pecas.0.custo_estimado', 270.0);

        // Drill-down: quem segura a peça.
        $this->assertCount(2, $resposta->json('data.pecas.0.orcamentos'));
    }

    public function test_pecas_a_comprar_exige_permissao_de_estoque(): void
    {
        $this->grantGroupPermissions(3, ['os' => ['visualizar']]);
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 3]), ['*']);

        $this->getJson('/api/v1/estoque/a-comprar')->assertStatus(403);
    }

    public function test_item_apontando_para_peca_inexistente_nao_quebra(): void
    {
        // `referencia_id` não tem FK: item órfão é possível e não pode travar o
        // envio do orçamento.
        $budget = $this->orcamentoComPeca(987654, 2, ['status' => Budget::STATUS_WAITING_REPLY]);

        $resultado = $this->service->sincronizar($budget);

        $this->assertSame(0, $resultado['reservadas']);
        $this->assertDatabaseCount('estoque_reservas', 0);
    }
}
