<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class BudgetFlowTest extends TestCase
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
        $this->grantGroupPermissions(3, [
            'orcamentos' => ['visualizar'],
        ]);
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();
    }

    public function test_admin_can_list_budgets_with_summary_and_search(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Orçamento',
            'cpf_cnpj' => '11.222.333/0001-44',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook Inspiron 15',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26060021',
        ]);

        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2606-000012',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'titulo' => 'Orçamento principal',
            'status' => 'aprovado',
            'origem' => 'os',
            'subtotal' => 230.00,
            'total' => 230.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Troca de tela',
            'valor_unitario' => 230.00,
            'total' => 230.00,
        ]);
        $this->createBudgetHistoryRecord($budgetId, [
            'status_anterior' => 'rascunho',
            'status_novo' => 'aprovado',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orcamentos?search=ORC-2606');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.budgets.0.id', $budgetId)
            ->assertJsonPath('data.budgets.0.numero', 'ORC-2606-000012')
            ->assertJsonPath('data.status_options.0.value', 'rascunho');

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orcamentos?status=aprovado')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_admin_can_create_update_and_delete_budget_with_items(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.write@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Comercial',
            'cpf_cnpj' => '22.333.444/0001-55',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Desktop i7',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26060031',
        ]);
        $serviceId = $this->createServiceRecord([
            'nome' => 'Limpeza interna',
            'descricao' => 'Limpeza e higienização de componentes',
            'valor' => 120.50,
        ]);
        $partId = $this->createPecaRecord([
            'codigo' => 'PEC-100',
            'nome' => 'Tela LCD',
            'preco_custo' => 150.00,
            'preco_venda' => 230.00,
            'quantidade_atual' => 5,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'assistencia',
                'status' => 'rascunho',
                'origem' => 'os',
                'cliente_id' => $clientId,
                'os_id' => $orderId,
                'equipamento_id' => $equipmentId,
                'titulo' => 'Orçamento de assistência',
                'validade_dias' => 10,
                'prazo_execucao' => '3 dias úteis',
                'observacoes' => 'Teste de criação',
                'condicoes' => 'Pagamento em até 2x',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'referencia_id' => $serviceId,
                        'descricao' => 'Limpeza interna',
                        'quantidade' => 1,
                        'valor_unitario' => 0,
                        'desconto' => 0,
                        'acrescimo' => 0,
                        'observacoes' => 'Item de serviço',
                    ],
                    [
                        'tipo_item' => 'peca',
                        'referencia_id' => $partId,
                        'descricao' => 'Tela LCD',
                        'quantidade' => 2,
                        'valor_unitario' => 0,
                        'desconto' => 10,
                        'acrescimo' => 0,
                        'observacoes' => 'Item de peça',
                    ],
                ],
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.budget.tipo_orcamento', 'assistencia')
            ->assertJsonPath('data.budget.os.id', $orderId)
            ->assertJsonPath('data.budget.itens.0.descricao', 'Limpeza interna')
            ->assertJsonPath('data.budget.itens.1.descricao', 'Tela LCD');

        $budgetId = (int) $createResponse->json('data.budget.id');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'tipo_orcamento' => 'assistencia',
        ]);

        $this->assertDatabaseHas('orcamento_itens', [
            'orcamento_id' => $budgetId,
            'descricao' => 'Limpeza interna',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/orcamentos/' . $budgetId, [
                'tipo_orcamento' => 'assistencia',
                'status' => 'enviado',
                'origem' => 'os',
                'cliente_id' => $clientId,
                'os_id' => $orderId,
                'equipamento_id' => $equipmentId,
                'titulo' => 'Orçamento atualizado',
                'validade_dias' => 10,
                'desconto' => 50,
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'referencia_id' => $serviceId,
                        'descricao' => 'Limpeza interna atualizada',
                        'quantidade' => 1,
                        'valor_unitario' => 0,
                        'desconto' => 0,
                        'acrescimo' => 0,
                        'observacoes' => 'Item ajustado',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'enviado')
            ->assertJsonPath('data.budget.titulo', 'Orçamento atualizado');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'enviado',
            'titulo' => 'Orçamento atualizado',
        ]);

        $this->assertDatabaseHas('orcamento_status_historico', [
            'orcamento_id' => $budgetId,
            'status_anterior' => 'rascunho',
            'status_novo' => 'enviado',
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->deleteJson('/api/v1/orcamentos/' . $budgetId)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('orcamentos', [
            'id' => $budgetId,
        ]);
    }

    public function test_budget_show_returns_hierarchy_and_recent_history(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.show@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-2606-000099',
            'status' => 'aprovado',
            'origem' => 'manual',
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Limpeza interna',
            'total' => 120.50,
        ]);
        $this->createBudgetHistoryRecord($budgetId, [
            'status_anterior' => 'rascunho',
            'status_novo' => 'enviado',
        ]);
        $this->createBudgetHistoryRecord($budgetId, [
            'status_anterior' => 'enviado',
            'status_novo' => 'aprovado',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orcamentos/' . $budgetId);

        $response->assertOk()
            ->assertJsonPath('data.budget.id', $budgetId)
            ->assertJsonPath('data.budget.numero', 'ORC-2606-000099')
            ->assertJsonPath('data.budget.itens.0.descricao', 'Limpeza interna')
            ->assertJsonPath('data.budget.historico.0.status_novo', 'enviado');

        $historico = collect($response->json('data.budget.historico', []));
        $this->assertNotEmpty($historico);
    }

    public function test_viewer_without_create_permission_receives_403_on_store(): void
    {
        $viewer = $this->createUserRecord([
            'nome' => 'Leitura',
            'email' => 'viewer.budgets@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        $token = $this->loginAndGetToken($viewer->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'previo',
                'cliente_nome_avulso' => 'Cliente sem permissão',
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Teste',
                        'quantidade' => 1,
                        'valor_unitario' => 10,
                    ],
                ],
            ])
            ->assertForbidden();
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-budgets',
        ]);

        return (string) $response->json('data.access_token');
    }
}
