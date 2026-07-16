<?php

namespace Tests\Feature\Api\V1;

use App\Services\Orders\OrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroMargemTest extends TestCase
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

    public function test_concluir_os_calcula_margem_automaticamente_usando_estoque_e_comissao(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $tecnico = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $pecaId = $this->createPecaRecord(['preco_custo' => 40.00, 'preco_venda' => 80.00]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => $tecnico->id,
            'status' => 'aguardando_reparo',
            'valor_total' => 300,
            'desconto' => 0,
            'valor_final' => 300,
        ]);

        $this->createMovimentacaoRecord([
            'peca_id' => $pecaId,
            'os_id' => $orderId,
            'tipo' => 'saida',
            'quantidade' => 2,
            'responsavel_id' => $admin->id,
        ]);

        $this->postJson('/api/v1/financeiro/comissoes', [
            'tecnico_id' => $tecnico->id,
            'percentual_padrao' => 10,
        ])->assertCreated();

        app(OrderWorkflowService::class)->updateStatus($orderId, $admin, 'entregue_reparado_pago');

        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId);

        // receita 300, custo pecas = 2 * 40 = 80, comissao = 10% de 300 = 30
        // margem = 300 - 80 - 30 = 190 -> 63.33%
        $response->assertOk()
            ->assertJsonPath('data.margem.receita_liquida', 300)
            ->assertJsonPath('data.margem.custo_pecas', 80)
            ->assertJsonPath('data.margem.custo_comissao', 30)
            ->assertJsonPath('data.margem.margem_contribuicao', 190)
            ->assertJsonPath('data.margem.percentual_margem', 63.33);
    }

    public function test_relatorio_por_periodo_agrega_ticket_medio_e_margem_media(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $tecnico = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => $tecnico->id,
            'status' => 'aguardando_reparo',
            'valor_total' => 200,
            'valor_final' => 200,
            'data_entrega' => now(),
        ]);

        app(OrderWorkflowService::class)->updateStatus($orderId, $admin, 'entregue_reparado_pago');

        $mes = now()->format('Y-m');
        $response = $this->getJson('/api/v1/financeiro/margem?mes=' . $mes);

        $response->assertOk()
            ->assertJsonPath('data.margem.total_os', 1)
            ->assertJsonPath('data.margem.ticket_medio', 200)
            ->assertJsonPath('data.margem.margem_media_percentual', 100);
    }

    public function test_recalcular_manualmente_atualiza_registro(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_final' => 150,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular');

        $response->assertOk()->assertJsonPath('data.margem.receita_liquida', 150);
    }

    public function test_crud_de_comissoes_e_percentual_padrao(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $tecnico = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro/comissoes', [
            'tecnico_id' => $tecnico->id,
            'percentual_padrao' => 8.5,
        ])->assertCreated();

        $comissaoId = $store->json('data.comissao.id');

        $this->putJson('/api/v1/financeiro/comissoes/' . $comissaoId, [
            'tecnico_id' => $tecnico->id,
            'percentual_padrao' => 12,
        ])->assertOk()->assertJsonPath('data.comissao.percentual_padrao', 12);

        $this->putJson('/api/v1/financeiro/comissoes-padrao', ['percentual_padrao' => 5])
            ->assertOk()
            ->assertJsonPath('data.comissao_percentual_padrao', 5);

        $this->deleteJson('/api/v1/financeiro/comissoes/' . $comissaoId)->assertOk();

        $this->assertDatabaseMissing('comissoes_tecnicos', ['id' => $comissaoId]);
    }

    public function test_usuario_sem_permissao_nao_acessa_margem(): void
    {
        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        Sanctum::actingAs($attendant, ['*']);

        $this->getJson('/api/v1/financeiro/margem')->assertStatus(403);
    }
}
