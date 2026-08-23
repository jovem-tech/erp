<?php

namespace Tests\Feature\Api\V1;

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
            'os' => ['visualizar', 'editar', 'encerrar'],
        ]);
    }

    /**
     * Encerra a OS pelo unico caminho que a regra de negocio permite.
     *
     * Os 5 status de encerramento so' podem ser aplicados por
     * OrderClosureService::close() — nao por updateStatus() nem por edicao
     * direta (.agents/skills/sistema-erp-os-fluxo-fechamento). A baixa nao e'
     * uma troca de status: e' a rotina que cria o titulo financeiro, registra
     * os recebimentos e dispara o calculo de margem/comissao. Chamar
     * updateStatus() aqui deixava a margem sem ser calculada, que era
     * exatamente o que estes testes viam como `null`.
     */
    /**
     * Compara valor numerico do JSON sem prender o TIPO.
     *
     * assertJsonPath() usa comparacao identica (===), e os campos de margem
     * chegam como float (150.0) enquanto o teste escrevia int (150). O
     * contrato aqui e' o VALOR; o tipo depende do driver (SQLite devolve
     * float, MySQL devolve DECIMAL como string) e nao e' o que se quer travar.
     */
    private function assertJsonNumero(\Illuminate\Testing\TestResponse $response, string $path, float $esperado): void
    {
        $this->assertEqualsWithDelta($esperado, (float) $response->json($path), 0.01, $path);
    }

    private function encerrarComoPago(int $orderId, float $valorRecebido): void
    {
        $this->postJson("/api/v1/orders/{$orderId}/closure", [
            'encerrar_como' => 'entregue_reparado_pago',
            'data_entrega' => now()->toDateString(),
            'recebimentos' => [
                ['valor' => $valorRecebido, 'forma_pagamento' => 'pix'],
            ],
        ])->assertOk();
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

        $this->encerrarComoPago($orderId, 300.00);

        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId);

        // receita 300, custo pecas = 2 * 40 = 80, comissao = 10% de 300 = 30
        // margem = 300 - 80 - 30 = 190 -> 63.33%
        $response->assertOk();

        $this->assertJsonNumero($response, 'data.margem.receita_liquida', 300);
        $this->assertJsonNumero($response, 'data.margem.custo_pecas', 80);
        $this->assertJsonNumero($response, 'data.margem.custo_comissao', 30);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 190);
        $this->assertJsonNumero($response, 'data.margem.percentual_margem', 63.33);
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

        $this->encerrarComoPago($orderId, 200.00);

        $mes = now()->format('Y-m');
        $response = $this->getJson('/api/v1/financeiro/margem?mes=' . $mes);

        $response->assertOk();

        $this->assertJsonNumero($response, 'data.margem.total_os', 1);
        $this->assertJsonNumero($response, 'data.margem.ticket_medio', 200);
        $this->assertJsonNumero($response, 'data.margem.margem_media_percentual', 100);
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

        $response->assertOk();
        $this->assertJsonNumero($response, 'data.margem.receita_liquida', 150);
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

        $comissaoResponse = $this->putJson('/api/v1/financeiro/comissoes/' . $comissaoId, [
            'tecnico_id' => $tecnico->id,
            'percentual_padrao' => 12,
        ])->assertOk();

        $this->assertJsonNumero($comissaoResponse, 'data.comissao.percentual_padrao', 12);

        $padraoResponse = $this->putJson('/api/v1/financeiro/comissoes-padrao', ['percentual_padrao' => 5])
            ->assertOk();

        $this->assertJsonNumero($padraoResponse, 'data.comissao_percentual_padrao', 5);

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
