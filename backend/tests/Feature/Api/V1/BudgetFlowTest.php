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
        $this->assertNotEmpty((string) $createResponse->json('data.budget.link_publico'));

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'tipo_orcamento' => 'assistencia',
        ]);
        $this->assertNotEmpty((string) \Illuminate\Support\Facades\DB::table('orcamentos')->where('id', $budgetId)->value('token_publico'));

        $this->assertDatabaseHas('orcamento_itens', [
            'orcamento_id' => $budgetId,
            'descricao' => 'Limpeza interna',
        ]);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'valor_mao_obra' => 120.50,
            'valor_pecas' => 450.00,
            'valor_total' => 570.50,
            'valor_final' => 570.50,
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

    public function test_admin_can_create_budget_with_null_numero_and_backend_generates_identifier(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.null-number@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Numero Automatico',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'numero' => null,
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_id' => $clientId,
                'titulo' => 'Orcamento com numero automatico',
                'itens' => [],
            ]);

        $response->assertCreated();

        $budgetId = (int) $response->json('data.budget.id');
        $budgetNumero = (string) $response->json('data.budget.numero');

        $this->assertNotSame('', trim($budgetNumero));
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'numero' => $budgetNumero,
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
            'status' => 'pendente_envio',
            'origem' => 'manual',
            'token_publico' => null,
            'token_expira_em' => null,
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

        $this->assertNotEmpty((string) $response->json('data.budget.link_publico'));
        $this->assertNotEmpty((string) \Illuminate\Support\Facades\DB::table('orcamentos')->where('id', $budgetId)->value('token_publico'));

        $historico = collect($response->json('data.budget.historico', []));
        $this->assertNotEmpty($historico);
    }

    public function test_budget_supports_percentual_and_monetary_adjustments(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.percent@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Percentual',
            'cpf_cnpj' => '66.777.888/0001-99',
        ]);
        $serviceId = $this->createServiceRecord([
            'nome' => 'Servico percentual',
            'descricao' => 'Servico com ajuste percentual',
            'valor' => 100.00,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'previo',
                'status' => 'rascunho',
                'origem' => 'manual',
                'cliente_id' => $clientId,
                'titulo' => 'Orcamento com ajustes percentuais',
                'desconto_tipo' => 'percentual',
                'desconto_percentual' => 10,
                'acrescimo_tipo' => 'percentual',
                'acrescimo_percentual' => 5,
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'referencia_id' => $serviceId,
                        'descricao' => 'Servico percentual',
                        'quantidade' => 2,
                        'valor_unitario' => 100,
                        'desconto_tipo' => 'percentual',
                        'desconto_percentual' => 10,
                        'acrescimo_tipo' => 'valor',
                        'acrescimo' => 5,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.subtotal', 185.0)
            ->assertJsonPath('data.budget.desconto', 18.5)
            ->assertJsonPath('data.budget.desconto_tipo', 'percentual')
            ->assertJsonPath('data.budget.desconto_percentual', 10.0)
            ->assertJsonPath('data.budget.acrescimo', 9.25)
            ->assertJsonPath('data.budget.acrescimo_tipo', 'percentual')
            ->assertJsonPath('data.budget.acrescimo_percentual', 5.0)
            ->assertJsonPath('data.budget.total', 175.75)
            ->assertJsonPath('data.budget.itens.0.desconto', 20.0)
            ->assertJsonPath('data.budget.itens.0.desconto_tipo', 'percentual')
            ->assertJsonPath('data.budget.itens.0.desconto_percentual', 10.0)
            ->assertJsonPath('data.budget.itens.0.acrescimo', 5.0)
            ->assertJsonPath('data.budget.itens.0.acrescimo_tipo', 'valor')
            ->assertJsonPath('data.budget.itens.0.acrescimo_percentual', null)
            ->assertJsonPath('data.budget.itens.0.total', 185.0);

        $budgetId = (int) $response->json('data.budget.id');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'desconto' => 18.50,
            'desconto_tipo' => 'percentual',
            'desconto_percentual' => 10.0000,
            'acrescimo' => 9.25,
            'acrescimo_tipo' => 'percentual',
            'acrescimo_percentual' => 5.0000,
            'total' => 175.75,
        ]);

        $this->assertDatabaseHas('orcamento_itens', [
            'orcamento_id' => $budgetId,
            'descricao' => 'Servico percentual',
            'desconto' => 20.00,
            'desconto_tipo' => 'percentual',
            'desconto_percentual' => 10.0000,
            'acrescimo' => 5.00,
            'acrescimo_tipo' => 'valor',
            'acrescimo_percentual' => null,
            'total' => 185.00,
        ]);
    }

    public function test_admin_can_send_budget_for_customer_approval(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.approval@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Aprovacao',
            'telefone1' => '(11) 99999-9999',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Notebook de aprovacao',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070001',
        ]);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'telefone_contato' => '(11) 99999-9999',
            'os_id' => $orderId,
            'equipamento_id' => $equipmentId,
            'token_publico' => null,
            'subtotal' => 330.00,
            'total' => 330.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Troca de display',
            'valor_unitario' => 330.00,
            'total' => 330.00,
        ]);

        $budgetPdfPath = storage_path('app/private/testing/orcamento.pdf');
        if (! is_dir(dirname($budgetPdfPath))) {
            mkdir(dirname($budgetPdfPath), 0777, true);
        }
        file_put_contents($budgetPdfPath, '%PDF-1.4 orçamento de teste');

        $this->mock(\App\Services\Budgets\BudgetPdfService::class, function ($mock): void {
            $mock->shouldReceive('generate')
                ->once()
                ->andReturn([
                    'ok' => true,
                    'absolute_path' => storage_path('app/private/testing/orcamento.pdf'),
                    'relative_path' => 'private/testing/orcamento.pdf',
                    'file_name' => 'Orcamento-ORC.pdf',
                ]);
        });
        $this->mock(\App\Services\Integrations\IntegrationSettingsService::class, function ($mock): void {
            $mock->shouldReceive('sendDirectMedia')
                ->once()
                ->andReturn([
                    'ok' => true,
                    'provider' => 'evolution',
                    'message' => 'Proposta enviada para aprovacao.',
                ]);
        });
        $this->mock(\App\Services\Company\CompanyProfileService::class, function ($mock): void {
            $mock->shouldReceive('payload')
                ->andReturn([
                    'settings' => [
                        'empresa_nome_fantasia' => 'Sistema ERP',
                    ],
                ]);
        });

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos/' . $budgetId . '/send-approval');

        $response->assertOk()
            ->assertJsonPath('data.dispatch.canal', 'whatsapp')
            ->assertJsonPath('data.dispatch.status', 'enviado');

        $budgetRecord = \Illuminate\Support\Facades\DB::table('orcamentos')->where('id', $budgetId)->first();

        $this->assertNotNull($budgetRecord);
        $this->assertNotEmpty($budgetRecord->token_publico);
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'aguardando_resposta',
        ]);
        $this->assertDatabaseHas('orcamento_envios', [
            'orcamento_id' => $budgetId,
            'canal' => 'whatsapp',
            'status' => 'enviado',
            'documento_path' => 'private/testing/orcamento.pdf',
        ]);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'orcamento_pdf' => 'private/testing/orcamento.pdf',
            'status' => 'aguardando_autorizacao',
            'estado_fluxo' => 'pausado',
        ]);
        $this->assertDatabaseHas('os_status_historico', [
            'os_id' => $orderId,
            'status_anterior' => 'triagem',
            'status_novo' => 'aguardando_autorizacao',
        ]);

        $documentId = (int) \Illuminate\Support\Facades\DB::table('os_documentos')
            ->where('os_id', $orderId)
            ->where('tipo_documento', 'orcamento')
            ->max('id');

        $this->assertGreaterThan(0, $documentId);
        $this->assertDatabaseHas('os_documento_arquivos', [
            'documento_id' => $documentId,
            'formato' => 'a4',
        ]);
    }

    public function test_public_budget_route_allows_client_approval(): void
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Publico',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Equipamento publico',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070002',
            'orcamento_aprovado' => 0,
        ]);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-public-approve',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 220.00,
            'total' => 220.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Servico publico',
            'valor_unitario' => 220.00,
            'total' => 220.00,
        ]);

        $this->get('/orcamento/token-public-approve')
            ->assertOk()
            ->assertSee('Aprovar proposta');

        $response = $this->post('/orcamento/token-public-approve/aprovar', [
            'resposta_cliente' => 'Pode executar o servico.',
        ]);

        $response
            ->assertRedirect(route('budgets.public.show', ['token' => 'token-public-approve']))
            ->assertSessionHas('success', 'Orçamento aprovado com sucesso.');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'aprovado',
        ]);
        $this->assertDatabaseHas('orcamento_aprovacoes', [
            'orcamento_id' => $budgetId,
            'acao' => 'aprovado',
            'origem' => 'link_publico',
        ]);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'orcamento_aprovado' => 1,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
        ]);
    }

    public function test_public_budget_route_streams_pdf_download(): void
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente PDF',
        ]);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-public-pdf',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 150.00,
            'total' => 150.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Servico com PDF',
            'valor_unitario' => 150.00,
            'total' => 150.00,
        ]);

        $response = $this->get('/orcamento/token-public-pdf/pdf');

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_public_budget_route_allows_client_rejection(): void
    {
        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Rejeicao',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Equipamento rejeitado',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070003',
            'orcamento_aprovado' => 0,
        ]);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'status' => 'aguardando_resposta',
            'token_publico' => 'token-public-reject',
            'token_expira_em' => now()->addDays(5),
            'subtotal' => 180.00,
            'total' => 180.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'descricao' => 'Servico rejeitado',
            'valor_unitario' => 180.00,
            'total' => 180.00,
        ]);

        $response = $this->post('/orcamento/token-public-reject/rejeitar', [
            'motivo_rejeicao' => 'Valor fora do esperado.',
        ]);

        $response
            ->assertRedirect(route('budgets.public.show', ['token' => 'token-public-reject']))
            ->assertSessionHas('success', 'Rejeição registrada com sucesso.');

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'rejeitado',
            'motivo_rejeicao' => 'Valor fora do esperado.',
        ]);
        $this->assertDatabaseHas('orcamento_aprovacoes', [
            'orcamento_id' => $budgetId,
            'acao' => 'rejeitado',
            'origem' => 'link_publico',
        ]);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'cancelado',
            'estado_fluxo' => 'cancelado',
        ]);
    }

    public function test_budget_status_changes_sync_linked_order_status(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.sync@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Sync',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Equipamento sync',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070099',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        // Criação com status rascunho: OS deve aguardar orçamento.
        $createResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'assistencia',
                'status' => 'rascunho',
                'origem' => 'os',
                'cliente_id' => $clientId,
                'os_id' => $orderId,
                'equipamento_id' => $equipmentId,
                'titulo' => 'Orçamento sync',
                'validade_dias' => 10,
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Servico sync',
                        'quantidade' => 1,
                        'valor_unitario' => 100,
                        'desconto' => 0,
                        'acrescimo' => 0,
                    ],
                ],
            ]);

        $createResponse->assertCreated();
        $budgetId = (int) $createResponse->json('data.budget.id');

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_orcamento',
            'estado_fluxo' => 'em_atendimento',
            'valor_mao_obra' => 100.0,
            'valor_pecas' => 0.0,
            'valor_total' => 100.0,
            'valor_final' => 100.0,
        ]);

        $statusTransitions = [
            ['enviado', 'aguardando_autorizacao', 'pausado'],
            ['aguardando_resposta', 'aguardando_autorizacao', 'pausado'],
            ['aguardando_pacote', 'aguardando_autorizacao', 'pausado'],
            ['reenviar_orcamento', 'aguardando_orcamento', 'em_atendimento'],
            ['pendente', 'aguardando_orcamento', 'em_atendimento'],
            ['aprovado', 'aguardando_reparo', 'em_execucao'],
            ['rejeitado', 'cancelado', 'cancelado'],
            ['cancelado', 'cancelado', 'cancelado'],
        ];

        foreach ($statusTransitions as [$budgetStatus, $expectedOrderStatus, $expectedFlowState]) {
            $this->withHeader('Authorization', 'Bearer ' . $token)
                ->patchJson('/api/v1/orcamentos/' . $budgetId, [
                    'tipo_orcamento' => 'assistencia',
                    'status' => $budgetStatus,
                    'os_id' => $orderId,
                ])
                ->assertOk();

            $this->assertDatabaseHas('os', [
                'id' => $orderId,
                'status' => $expectedOrderStatus,
                'estado_fluxo' => $expectedFlowState,
            ]);
        }

        // OS cancelada volta ao fluxo quando o orçamento é reativado.
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson('/api/v1/orcamentos/' . $budgetId, [
                'tipo_orcamento' => 'assistencia',
                'status' => 'pendente_envio',
                'os_id' => $orderId,
            ])
            ->assertOk();

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_orcamento',
            'estado_fluxo' => 'em_atendimento',
        ]);
    }

    public function test_budget_creation_syncs_status_and_financial_value_from_existing_order_status(): void
    {
        $admin = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.budgets.protected-status@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Irreparavel',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'Smartphone protegido',
        ]);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070123',
            'status' => 'irreparavel',
            'estado_fluxo' => 'em_atendimento',
            'valor_final' => 0,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'assistencia',
                'status' => 'pendente_envio',
                'origem' => 'os',
                'cliente_id' => $clientId,
                'os_id' => $orderId,
                'equipamento_id' => $equipmentId,
                'titulo' => 'Orçamento em OS irreparável',
                'validade_dias' => 10,
                'itens' => [
                    [
                        'tipo_item' => 'servico',
                        'descricao' => 'Diagnóstico e taxa técnica',
                        'quantidade' => 1,
                        'valor_unitario' => 200,
                        'desconto' => 0,
                        'acrescimo' => 0,
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.total', 200.0);

        $this->assertNotEmpty((string) $response->json('data.budget.link_publico'));
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_orcamento',
            'estado_fluxo' => 'em_atendimento',
            'valor_mao_obra' => 200.00,
            'valor_pecas' => 0.00,
            'valor_total' => 200.00,
            'valor_final' => 200.00,
        ]);
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
