<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
use App\Services\Estoque\EstoqueReservaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Os furos de escrita de saldo — specs/040, fase 4.
 *
 * A reserva so vale se o saldo nao puder ser reescrito por fora do motor. Ate
 * esta entrega havia QUATRO caminhos que gravavam `pecas.quantidade_atual`
 * direto. Estes testes cobrem os dois criticos, que sao os que podem atropelar
 * uma reserva ativa:
 *
 *  - `PATCH /estoque/{id}` (cadastro da peca);
 *  - `POST /estoque/{id}/movimentacoes` (movimentacao manual).
 *
 * `store()` e `importCsv()` ficam para a 036 Bloco B: peca recem-criada ou
 * importada nao tem reserva, entao o risco la e saldo errado, nao reserva
 * furada.
 */
class EstoqueFurosDeEscritaTest extends TestCase
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
        ]);

        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);
    }

    /**
     * `estoque_subcategoria_id` virou obrigatorio no cadastro de peca com a
     * taxonomia de 2026_09_03. O trait cria as tabelas mas nao semeia a arvore.
     */
    private function subcategoriaId(): int
    {
        $tipoId = (int) DB::table('equipamentos_tipos')->value('id');

        $categoriaId = (int) DB::table('estoque_categorias')->insertGetId([
            'tipo_equipamento_id' => $tipoId,
            'nome' => 'Energia',
            'ativo' => 1,
            'ordem' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('estoque_subcategorias')->insertGetId([
            'categoria_id' => $categoriaId,
            'nome' => 'Baterias',
            'ativo' => 1,
            'ordem' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pecaComReserva(float $saldo, float $reservada): int
    {
        $pecaId = $this->createPecaRecord([
            'codigo' => 'PC-FURO',
            'nome' => 'Bateria',
            'quantidade_atual' => $saldo,
        ]);

        if ($reservada > 0) {
            $budgetId = $this->createBudgetRecord(['status' => Budget::STATUS_WAITING_REPLY]);
            $this->createBudgetItemRecord($budgetId, [
                'tipo_item' => 'peca',
                'referencia_id' => $pecaId,
                'quantidade' => $reservada,
            ]);
            app(EstoqueReservaService::class)->sincronizar(Budget::query()->findOrFail($budgetId));
        }

        return $pecaId;
    }

    private function saldo(int $pecaId): float
    {
        return (float) DB::table('pecas')->where('id', $pecaId)->value('quantidade_atual');
    }

    public function test_editar_a_peca_nao_pode_mais_reescrever_o_saldo(): void
    {
        $pecaId = $this->pecaComReserva(10, 4);

        $this->patchJson("/api/v1/estoque/{$pecaId}", [
            'nome' => 'Bateria premium',
            'estoque_subcategoria_id' => $this->subcategoriaId(),
            'quantidade_atual' => 999,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PART_QUANTITY_IMMUTABLE');

        $this->assertEqualsWithDelta(10.0, $this->saldo($pecaId), 0.0001);
    }

    /**
     * O bug que estava escondido atras do furo: a regra era `nullable` com
     * `?? 0`, entao um PATCH que apenas OMITISSE o campo zerava o saldo. O
     * formulario do desktop sempre enviava, o que escondia isso de todo mundo.
     */
    public function test_editar_sem_enviar_a_quantidade_nao_zera_o_saldo(): void
    {
        $pecaId = $this->pecaComReserva(7, 0);

        $this->patchJson("/api/v1/estoque/{$pecaId}", [
            'nome' => 'Bateria premium',
            'estoque_subcategoria_id' => $this->subcategoriaId(),
        ])->assertOk();

        $this->assertEqualsWithDelta(7.0, $this->saldo($pecaId), 0.0001);
        $this->assertSame('Bateria premium', DB::table('pecas')->where('id', $pecaId)->value('nome'));
    }

    public function test_saida_manual_respeita_a_reserva_e_nomeia_o_ofensor(): void
    {
        // 5 no estoque, 4 prometidas a um orçamento enviado: só 1 disponível.
        $pecaId = $this->pecaComReserva(5, 4);

        $resposta = $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'saida',
            'quantidade' => 3,
            'motivo' => 'Uso interno',
        ]);

        $resposta
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ESTOQUE_INSUFICIENTE')
            ->assertJsonPath('error.details.itens.0.codigo', 'PC-FURO')
            ->assertJsonPath('error.details.itens.0.disponivel', 1.0);

        $this->assertEqualsWithDelta(5.0, $this->saldo($pecaId), 0.0001, 'Nada pode ter saído.');
        $this->assertDatabaseCount('movimentacoes', 0);
    }

    public function test_saida_manual_dentro_do_disponivel_passa_pelo_motor(): void
    {
        $pecaId = $this->pecaComReserva(5, 4);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'saida',
            'quantidade' => 1,
            'motivo' => 'Uso interno',
        ])->assertOk();

        $this->assertEqualsWithDelta(4.0, $this->saldo($pecaId), 0.0001);
        $this->assertDatabaseHas('movimentacoes', ['peca_id' => $pecaId, 'tipo' => 'saida']);
    }

    public function test_confirmacao_explicita_permite_furar_a_reserva(): void
    {
        // Mesma decisão do PDV e da 038: recusar sem saída faria o operador
        // contornar por fora do sistema, e aí some o registro inteiro.
        $pecaId = $this->pecaComReserva(5, 4);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'saida',
            'quantidade' => 3,
            'motivo' => 'Urgência',
            'confirmar_saldo_negativo' => true,
        ])->assertOk();

        $this->assertEqualsWithDelta(2.0, $this->saldo($pecaId), 0.0001);
    }

    public function test_entrada_manual_continua_funcionando(): void
    {
        $pecaId = $this->pecaComReserva(5, 4);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'entrada',
            'quantidade' => 2.5,
            'motivo' => 'Compra avulsa',
        ])->assertOk();

        $this->assertEqualsWithDelta(7.5, $this->saldo($pecaId), 0.0001);
    }

    public function test_ajuste_abaixo_do_reservado_exige_confirmacao(): void
    {
        $pecaId = $this->pecaComReserva(5, 4);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'ajuste',
            'quantidade' => 2,
            'motivo' => 'Contagem',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ESTOQUE_AJUSTE_ABAIXO_DA_RESERVA');

        $this->assertEqualsWithDelta(5.0, $this->saldo($pecaId), 0.0001);

        $this->postJson("/api/v1/estoque/{$pecaId}/movimentacoes", [
            'tipo' => 'ajuste',
            'quantidade' => 2,
            'motivo' => 'Contagem conferida',
            'confirmar_saldo_negativo' => true,
        ])->assertOk();

        $this->assertEqualsWithDelta(2.0, $this->saldo($pecaId), 0.0001);
    }
}
