<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroReportTest extends TestCase
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
            'financeiro' => ['visualizar', 'criar', 'editar', 'excluir'],
        ]);
    }

    public function test_dre_competencia_reconhece_receita_de_os_pela_data_de_entrega(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado',
            'data_entrega' => now()->startOfMonth()->addDays(2),
            'valor_total' => 500,
            'desconto' => 50,
            'valor_final' => 450,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel do mês',
            'valor' => 100,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(5),
            'data_competencia' => now()->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.modo', 'competencia')
            ->assertJsonPath('data.dre.receita.receita_bruta', 500)
            ->assertJsonPath('data.dre.receita.descontos', 50)
            ->assertJsonPath('data.dre.receita.receita_liquida', 450)
            ->assertJsonPath('data.dre.despesas_operacionais.total', 100)
            ->assertJsonPath('data.dre.despesas_operacionais.por_subgrupo.Aluguel', 100)
            ->assertJsonPath('data.dre.lucro_bruto', 450)
            ->assertJsonPath('data.dre.resultado_liquido', 350);
    }

    public function test_dre_caixa_reconhece_apenas_o_que_foi_baixado_no_periodo(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $receitaAvulsa = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Receita avulsa',
            'descricao' => 'Receita avulsa de teste',
            'cliente_id' => $clienteId,
            'valor' => 200,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Outras Receitas',
            'subgrupo_dre' => 'Receita avulsa',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $receitaAvulsa->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now(),
            'valor_movimento' => 120,
        ]);

        $despesaPendente = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Energia',
            'descricao' => 'Conta de energia',
            'valor' => 80,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(10),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Energia',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre-caixa?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.modo', 'caixa')
            ->assertJsonPath('data.dre.outras_receitas.total', 120)
            ->assertJsonPath('data.dre.despesas_operacionais.total', 0);

        $this->assertDatabaseHas('financeiro', ['id' => $despesaPendente->id, 'status' => 'pendente']);
    }

    public function test_fluxo_de_caixa_separa_realizados_de_previstos(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Serviço de teste',
            'cliente_id' => $clienteId,
            'valor' => 300,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now(),
            'valor_movimento' => 300,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Internet',
            'descricao' => 'Conta de internet',
            'valor' => 90,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(3),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Internet',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 300)
            ->assertJsonPath('data.fluxo.saidas_realizadas', 0)
            ->assertJsonPath('data.fluxo.saidas_previstas', 90)
            ->assertJsonPath('data.fluxo.saldo_final', 300)
            ->assertJsonPath('data.fluxo.saldo_projetado', 210);

        $this->assertNotEmpty($response->json('data.fluxo.linhas_diarias'));
    }

    public function test_user_without_permission_cannot_view_reports(): void
    {
        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        Sanctum::actingAs($attendant, ['*']);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre');

        $response->assertStatus(403);
    }
}
