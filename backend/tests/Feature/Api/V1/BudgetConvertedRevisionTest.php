<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Edição limitada de orçamento convertido + ciclo de revisão/reaprovação.
 * Ver BudgetWorkflowService::updateConvertedBudget()/BudgetRevisionService.
 */
class BudgetConvertedRevisionTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'orcamentos' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
            'os' => ['visualizar'],
            'servicos' => ['visualizar'],
            'estoque' => ['visualizar'],
        ]);
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();
    }

    /**
     * @return array{0: int, 1: int, 2: int} [orderId, budgetId, clientId]
     */
    private function createConvertedBudgetOnOpenOrder(array $orderOverrides = [], array $budgetOverrides = []): array
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Orçamento Convertido',
            'cpf_cnpj' => '55.666.777/0001-'.random_int(10, 99),
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Smartphone Convertido',
        ]);
        $orderId = $this->createOrderRecord(array_merge([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_atendimento',
        ], $orderOverrides));

        $budgetId = $this->createBudgetRecord(array_merge([
            'os_id' => $orderId,
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'convertido',
            'origem' => 'os',
            'tipo_orcamento' => 'assistencia',
            'telefone_contato' => '11999990000',
            'validade_dias' => 10,
            'validade_data' => now()->addDays(10)->toDateString(),
            'garantia_dias' => 90,
            'subtotal' => 100.00,
            'total' => 100.00,
        ], $budgetOverrides));
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Troca de tela',
            'quantidade' => 1,
            'valor_unitario' => 100.00,
            'total' => 100.00,
        ]);

        return [$orderId, $budgetId, $clientId];
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-budgets-revision',
        ]);

        return (string) $response->json('data.access_token');
    }

    public function test_whitelist_field_can_be_updated_on_converted_budget_without_approval(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.whitelist@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [$orderId, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'telefone_contato' => '11988887777',
                'relato_cliente' => 'Tela trincada, cliente confirmou por telefone.',
                'validade_dias' => 15,
                'validade_data' => now()->addDays(15)->toDateString(),
                'garantia_dias' => 180,
            ])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'convertido')
            ->assertJsonPath('data.budget.telefone_contato', '11988887777')
            ->assertJsonPath('data.budget.garantia_dias', 180);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'convertido',
            'telefone_contato' => '11988887777',
            'garantia_dias' => 180,
            'total' => 100.00,
        ]);
        // Correção de garantia direto no orçamento convertido propaga para a
        // OS (é o que vai para o termo de garantia impresso na entrega).
        $this->assertDatabaseHas('os', ['id' => $orderId, 'garantia_dias' => 180, 'status' => 'aguardando_reparo']);
        $this->assertDatabaseMissing('orcamentos', ['orcamento_revisao_de_id' => $budgetId]);
    }

    public function test_financial_change_without_propor_revisao_flag_requires_confirmation(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.confirm-required@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 150.00,
                'subtotal' => 150.00,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_REVISION_CONFIRMATION_REQUIRED');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'total' => 100.00]);
        $this->assertDatabaseMissing('orcamentos', ['orcamento_revisao_de_id' => $budgetId]);
    }

    public function test_financial_change_with_propor_revisao_spawns_pending_revision_without_touching_base(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.spawn-revision@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [$orderId, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'itens' => [[
                    'tipo_item' => 'servico',
                    'descricao' => 'Troca de tela (peça premium)',
                    'quantidade' => 1,
                    'valor_unitario' => 180.00,
                ]],
                'propor_revisao' => true,
            ])
            ->assertOk();

        $revisionId = (int) $response->json('data.revision.id');
        $this->assertGreaterThan(0, $revisionId);
        $this->assertNotSame($budgetId, $revisionId);

        // Base permanece convertido, intocado.
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'convertido',
            'total' => 100.00,
        ]);
        // Revisão nasce como uma linha própria, pendente de aprovação.
        $this->assertDatabaseHas('orcamentos', [
            'id' => $revisionId,
            'orcamento_revisao_de_id' => $budgetId,
            'status' => 'reenviar_orcamento',
            'os_id' => $orderId,
            'total' => 180.00,
        ]);
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'aguardando_reparo']);
    }

    public function test_approving_revision_applies_changes_to_base_and_does_not_change_order_status(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.approve-revision@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [$orderId, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'itens' => [[
                    'tipo_item' => 'servico',
                    'descricao' => 'Troca de tela (peça premium)',
                    'quantidade' => 1,
                    'valor_unitario' => 180.00,
                ]],
                'propor_revisao' => true,
            ])
            ->assertOk();
        $revisionId = (int) $response->json('data.revision.id');

        // Regressão crítica a vigiar: aprovar a revisão NUNCA pode mexer no
        // status da OS real (ver guard em BudgetOrderSyncService::
        // syncFromBudget() e o uso de syncFinancialsFromBudget() em
        // BudgetRevisionService::applyApprovedRevision()).
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$revisionId.'/aprovar', [
                'observacao' => 'Cliente aprovou a troca de peça por telefone.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'convertido',
            'total' => 180.00,
            'versao' => 2,
        ]);
        $this->assertDatabaseHas('orcamento_itens', [
            'orcamento_id' => $budgetId,
            'descricao' => 'Troca de tela (peça premium)',
            'valor_unitario' => 180.00,
        ]);
        $revisionRow = DB::table('orcamentos')->where('id', $revisionId)->first();
        $this->assertSame('aprovado', $revisionRow->status);
        $this->assertNotNull($revisionRow->aplicada_em);

        // A OS não regrediu de status por causa do ciclo de aprovação da revisão.
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'aguardando_reparo', 'estado_fluxo' => 'em_atendimento']);
        $this->assertDatabaseHas('os_eventos', [
            'os_id' => $orderId,
            'categoria' => 'orcamento',
            'tipo' => 'orcamento_revisao_aplicada',
        ]);
    }

    public function test_approving_revision_on_closed_order_corrects_financeiro_title(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.revision-closed-os@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.revision-closed-os@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        [$orderId, $budgetId, $clientId] = $this->createConvertedBudgetOnOpenOrder([
            'status' => 'entregue_reparado_pago',
            'estado_fluxo' => 'encerrado',
            'data_conclusao' => now()->toDateString(),
            'data_entrega' => now(),
        ]);

        $financeiroId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clientId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 100.00,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->toDateString(),
        ])->id;
        FinanceiroMovimento::query()->create([
            'financeiro_id' => $financeiroId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 100.00,
        ]);

        $token = $this->loginAndGetToken($actor->email);

        // OS encerrada: mesmo a proposta de revisão exige confirmação de administrador.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 130.00,
                'subtotal' => 130.00,
                'itens' => [[
                    'tipo_item' => 'servico',
                    'descricao' => 'Troca de tela + ajuste',
                    'quantidade' => 1,
                    'valor_unitario' => 130.00,
                ]],
                'propor_revisao' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_CONVERTED_OS_SETTLED_ADMIN_REQUIRED');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 130.00,
                'subtotal' => 130.00,
                'itens' => [[
                    'tipo_item' => 'servico',
                    'descricao' => 'Troca de tela + ajuste',
                    'quantidade' => 1,
                    'valor_unitario' => 130.00,
                ]],
                'propor_revisao' => true,
                'admin_email' => $admin->email,
                'admin_password' => 'Senha@123',
            ])
            ->assertOk();
        $revisionId = (int) $response->json('data.revision.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$revisionId.'/aprovar')
            ->assertOk();

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'total' => 130.00]);
        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'valor' => 130.00]);
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'entregue_reparado_pago']);
    }

    public function test_second_revision_cannot_be_created_while_one_is_pending(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.revision-conflict@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 150.00,
                'subtotal' => 150.00,
                'propor_revisao' => true,
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 200.00,
                'subtotal' => 200.00,
                'propor_revisao' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_REVISION_ALREADY_PENDING');
    }

    public function test_out_of_scope_field_change_on_converted_budget_is_rejected(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.out-of-scope@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'titulo' => 'Título trocado indevidamente',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUDGET_CONVERTED_FIELD_NOT_EDITABLE');

        $this->assertDatabaseMissing('orcamentos', ['id' => $budgetId, 'titulo' => 'Título trocado indevidamente']);
    }

    public function test_validity_can_only_be_postponed_not_shortened(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.validity@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder([], [
            'validade_data' => now()->addDays(10)->toDateString(),
        ]);
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'validade_dias' => 1,
                'validade_data' => now()->subDays(1)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'BUDGET_CONVERTED_FIELD_NOT_EDITABLE');
    }

    public function test_settled_order_macro_group_blocks_edit_without_admin_confirmation(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.macro-settled@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        // Cancelado: fora do subconjunto financeiro estreito (isOrderClosed()),
        // mas dentro do gate mais amplo usado para orçamento convertido
        // (BudgetApprovalService::orderIsSettled()).
        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder([
            'status' => 'cancelado',
            'estado_fluxo' => 'cancelado',
        ]);
        $token = $this->loginAndGetToken($actor->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'telefone_contato' => '11977776666',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_CONVERTED_OS_SETTLED_ADMIN_REQUIRED');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'telefone_contato' => '11999990000']);
    }

    public function test_rejecting_revision_leaves_base_budget_untouched(): void
    {
        $actor = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.reject-revision@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 1,
        ]);

        [, $budgetId] = $this->createConvertedBudgetOnOpenOrder();
        $token = $this->loginAndGetToken($actor->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 150.00,
                'subtotal' => 150.00,
                'propor_revisao' => true,
            ])
            ->assertOk();
        $revisionId = (int) $response->json('data.revision.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$revisionId.'/rejeitar', [
                'motivo' => 'Cliente recusou o valor novo.',
            ])
            ->assertOk();

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'convertido', 'total' => 100.00]);
        $this->assertDatabaseHas('orcamentos', ['id' => $revisionId, 'status' => 'rejeitado']);

        // Com a revisão resolvida (rejeitada), uma nova pode ser proposta.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'total' => 160.00,
                'subtotal' => 160.00,
                'propor_revisao' => true,
            ])
            ->assertOk();
    }
}
