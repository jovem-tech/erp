<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $legacyPublicRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();

        $this->grantGroupPermissions(1, [
            'os' => ['visualizar', 'criar', 'editar', 'excluir'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(2, [
            'os' => ['visualizar', 'editar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);
        $this->grantGroupPermissions(4, [
            'os' => ['visualizar', 'criar', 'editar'],
            'clientes' => ['visualizar'],
            'equipamentos' => ['visualizar'],
        ]);

        $this->legacyPublicRoot = storage_path('framework/testing/legacy-public-' . str_replace('.', '-', uniqid('', true)));
        mkdir($this->legacyPublicRoot, 0777, true);

        config([
            'filesystems.disks.legacy_public.root' => $this->legacyPublicRoot,
        ]);
    }

    public function test_index_returns_only_orders_assigned_to_authenticated_technician(): void
    {
        [$user, $assignedOrder, $unassignedOrder] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.orders.0.id', $assignedOrder)
            ->assertJsonPath('meta.pagination.total', 1);

        $orders = collect($response->json('data.orders', []));
        $this->assertFalse(
            $orders->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $unassignedOrder),
            'A listagem não deve incluir OS não atribuída ao técnico autenticado.'
        );
    }

    public function test_admin_index_returns_general_listing_with_filters(): void
    {
        [$manager, $techA, $techB, $clientA, $clientB, $equipmentA, $equipmentB] = $this->seedAdminOrderActors();

        $firstOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060011',
            'cliente_id' => $clientA,
            'equipamento_id' => $equipmentA,
            'tecnico_id' => $techA->id,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
            'relato_cliente' => 'Tela escura',
        ]);

        $secondOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060012',
            'cliente_id' => $clientB,
            'equipamento_id' => $equipmentB,
            'tecnico_id' => $techB->id,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
            'relato_cliente' => 'Não liga',
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?status=aguardando_reparo&technician_id=' . $techB->id . '&search=Não liga');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.orders.0.id', $secondOrder);

        $orders = collect($response->json('data.orders'));
        $this->assertFalse($orders->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $firstOrder));
    }

    public function test_index_open_status_scope_excludes_orders_with_encerrado_flow_state(): void
    {
        [$manager, $techA, $techB, $clientA, $clientB, $equipmentA, $equipmentB] = $this->seedAdminOrderActors();

        $openOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060013',
            'cliente_id' => $clientA,
            'equipamento_id' => $equipmentA,
            'tecnico_id' => $techA->id,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
        ]);

        $pendingPaymentOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060014',
            'cliente_id' => $clientB,
            'equipamento_id' => $equipmentB,
            'tecnico_id' => $techB->id,
            'status' => 'entregue_pagamento_pendente',
            'estado_fluxo' => 'pausado',
        ]);

        $closedOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060015',
            'cliente_id' => $clientB,
            'equipamento_id' => $equipmentB,
            'tecnico_id' => $techB->id,
            'status' => 'entregue_reparado',
            'estado_fluxo' => 'encerrado',
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?status_scope=open');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);

        $orders = collect($response->json('data.orders', []));

        $this->assertTrue($orders->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $openOrder));
        $this->assertTrue($orders->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $pendingPaymentOrder));
        $this->assertFalse($orders->contains(fn (array $item): bool => (int) ($item['id'] ?? 0) === $closedOrder));
    }

    public function test_index_summary_exposes_photo_whatsapp_deadline_budget_and_receivable_breakdown(): void
    {
        [$manager] = $this->seedAdminOrderActors();

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Com Telefone',
            'cpf_cnpj' => '44.444.444/0001-44',
            'telefone1' => '11999998888',
        ]);

        $equipmentId = $this->createEquipmentRecord($clientId, [
            'tipo_id' => 2,
            'marca_id' => 1,
            'modelo_id' => 1,
            'resumo_tecnico' => 'Notebook Dell Inspiron 15 com specs longas demais para a listagem',
        ]);

        $photoId = (int) DB::table('equipamentos_fotos')->insertGetId([
            'equipamento_id' => $equipmentId,
            'arquivo' => 'principal.jpg',
            'is_principal' => true,
            'created_at' => now(),
        ]);

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26060031',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'triagem',
            'data_previsao' => now()->subDays(2)->toDateString(),
            'valor_final' => 300.00,
        ]);

        $this->createBudgetRecord([
            'numero' => 'ORC-2606-000031',
            'os_id' => $orderId,
            'status' => 'aguardando_resposta',
        ]);

        $financeiroId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clientId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 300.00,
            'status' => Financeiro::STATUS_PARCIAL,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        FinanceiroMovimento::query()->create([
            'financeiro_id' => $financeiroId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 100.00,
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?search=OS26060031');

        $response->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderId)
            ->assertJsonPath('data.orders.0.cliente_telefone', '11999998888')
            ->assertJsonPath('data.orders.0.equipamento_resumo_curto', 'Notebook Dell Inspiron 15')
            ->assertJsonPath('data.orders.0.equipamento_foto_id', $photoId)
            ->assertJsonPath('data.orders.0.equipamento_foto_url', "/api/v1/equipments/{$equipmentId}/photos/{$photoId}")
            ->assertJsonPath('data.orders.0.prazo.estado', 'atrasado')
            ->assertJsonPath('data.orders.0.prazo.dias', 2)
            ->assertJsonPath('data.orders.0.orcamento.status', 'aguardando_resposta')
            ->assertJsonPath('data.orders.0.valor_recebido', '100.00')
            ->assertJsonPath('data.orders.0.saldo', '200.00');
    }

    public function test_index_summary_handles_orders_without_photo_budget_or_receivable(): void
    {
        [$manager] = $this->seedAdminOrderActors();

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Sem Vinculos',
            'cpf_cnpj' => '55.555.555/0001-55',
            'telefone1' => '',
            'telefone_contato' => '',
        ]);

        $equipmentId = $this->createEquipmentRecord($clientId, [
            'tipo_id' => null,
            'marca_id' => null,
            'modelo_id' => null,
            'resumo_tecnico' => '',
        ]);

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26060032',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'triagem',
            'data_previsao' => null,
            'valor_final' => 150.00,
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?search=OS26060032');

        $response->assertOk()
            ->assertJsonPath('data.orders.0.id', $orderId)
            ->assertJsonPath('data.orders.0.cliente_telefone', '')
            ->assertJsonPath('data.orders.0.equipamento_resumo_curto', 'Sem resumo técnico')
            ->assertJsonPath('data.orders.0.equipamento_foto_id', 0)
            ->assertJsonPath('data.orders.0.equipamento_foto_url', null)
            ->assertJsonPath('data.orders.0.prazo.estado', 'sem_previsao')
            ->assertJsonPath('data.orders.0.orcamento', null)
            ->assertJsonPath('data.orders.0.valor_recebido', null)
            ->assertJsonPath('data.orders.0.saldo', null);
    }

    public function test_index_filters_by_macro_group_opening_date_range_and_value_range(): void
    {
        [$manager, $techA, , $clientA, $clientB, $equipmentA, $equipmentB] = $this->seedAdminOrderActors();

        $matchedOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060041',
            'cliente_id' => $clientA,
            'equipamento_id' => $equipmentA,
            'tecnico_id' => $techA->id,
            'status' => 'triagem',
            'data_abertura' => now()->subDays(5),
            'valor_final' => 500.00,
        ]);

        $this->createOrderRecord([
            'numero_os' => 'OS26060042',
            'cliente_id' => $clientB,
            'equipamento_id' => $equipmentB,
            'status' => 'aguardando_reparo',
            'data_abertura' => now()->subDays(20),
            'valor_final' => 5000.00,
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?' . http_build_query([
                'grupo_macro' => 'recepcao',
                'data_abertura_de' => now()->subDays(10)->toDateString(),
                'data_abertura_ate' => now()->toDateString(),
                'valor_min' => 100,
                'valor_max' => 1000,
            ]));

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.orders.0.id', $matchedOrder);
    }

    public function test_index_query_count_does_not_grow_with_number_of_orders_on_page(): void
    {
        [$manager, $techA, , $clientA, , $equipmentA] = $this->seedAdminOrderActors();
        $token = $this->loginAndGetToken($manager->email);

        // Aquece qualquer cache ligado ao usuario (ex.: permissoes RBAC) antes de medir,
        // para que a comparacao de queries entre as duas chamadas isole apenas o efeito
        // da quantidade de OS exibidas, sem ruido de cache frio na primeira chamada.
        $this->withHeader('Authorization', 'Bearer ' . $token)->getJson('/api/v1/orders');

        for ($i = 1; $i <= 5; $i++) {
            $orderId = $this->createOrderRecord([
                'numero_os' => 'OS2606005' . $i,
                'cliente_id' => $clientA,
                'equipamento_id' => $equipmentA,
                'tecnico_id' => $techA->id,
                'status' => 'triagem',
            ]);

            $this->createBudgetRecord([
                'numero' => 'ORC-2606-00005' . $i,
                'os_id' => $orderId,
            ]);

            $financeiroId = (int) Financeiro::query()->create([
                'os_id' => $orderId,
                'cliente_id' => $clientA,
                'tipo' => Financeiro::TIPO_RECEBER,
                'categoria' => 'Serviço',
                'descricao' => 'Cobrança da OS',
                'valor' => 100.00,
                'status' => Financeiro::STATUS_PENDENTE,
                'data_vencimento' => now()->toDateString(),
            ])->id;

            FinanceiroMovimento::query()->create([
                'financeiro_id' => $financeiroId,
                'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
                'data_movimento' => now()->toDateString(),
                'valor_movimento' => 50.00,
            ]);
        }

        DB::enableQueryLog();
        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?per_page=50');
        $queryCountForFiveOrders = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $response->assertOk()->assertJsonPath('meta.pagination.total', 5);

        $secondOrderId = $this->createOrderRecord([
            'numero_os' => 'OS26060099',
            'cliente_id' => $clientA,
            'equipamento_id' => $equipmentA,
            'tecnico_id' => $techA->id,
            'status' => 'triagem',
        ]);
        $this->createBudgetRecord(['numero' => 'ORC-2606-000099', 'os_id' => $secondOrderId]);
        Financeiro::query()->create([
            'os_id' => $secondOrderId,
            'cliente_id' => $clientA,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 100.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ]);

        DB::enableQueryLog();
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?per_page=50')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 6);
        $queryCountForSixOrders = count(DB::getQueryLog());
        DB::flushQueryLog();
        DB::disableQueryLog();

        $this->assertSame(
            $queryCountForFiveOrders,
            $queryCountForSixOrders,
            'O numero de consultas nao deve crescer com a quantidade de OS exibidas na pagina.'
        );
    }

    public function test_admin_search_matches_budget_and_text_free_operational_fields(): void
    {
        [$manager, $techA, $techB, $clientA, $clientB, $equipmentA, $equipmentB] = $this->seedAdminOrderActors();

        $matchedOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060021',
            'cliente_id' => $clientA,
            'equipamento_id' => $equipmentA,
            'tecnico_id' => $techA->id,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
            'relato_cliente' => 'Tela azul ao ligar',
            'diagnostico_tecnico' => 'Falha no circuito de energia',
            'solucao_aplicada' => 'Troca do circuito de alimentacao',
            'procedimentos_executados' => 'Limpeza geral e troca do modulo',
            'observacoes_cliente' => 'Cliente aprovou o orcamento por WhatsApp',
            'orcamento_pdf' => 'uploads/orcamentos/OS26060021-orcamento.pdf',
        ]);

        $this->createOrderRecord([
            'numero_os' => 'OS26060022',
            'cliente_id' => $clientB,
            'equipamento_id' => $equipmentB,
            'tecnico_id' => $techB->id,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
            'relato_cliente' => 'Sem conexao com a internet',
        ]);

        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?search=orcamento');

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.orders.0.id', $matchedOrder);
    }

    public function test_show_returns_detail_with_recent_history_and_attachment_urls(): void
    {
        [$user, $assignedOrder, , $photoId, $documentId] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/orders/{$assignedOrder}");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.id', $assignedOrder)
            ->assertJsonPath('data.order.cliente.nome_razao', 'Cliente Teste')
            ->assertJsonPath('data.order.equipamento.numero_serie', 'ABC123')
            ->assertJsonPath('data.order.historico.0.status_novo', 'status_6')
            ->assertJsonPath('data.order.status_disponiveis.0.codigo', 'triagem')
            ->assertJsonPath('data.order.status_disponiveis.1.codigo', 'aguardando_reparo')
            ->assertJsonPath('data.order.fotos.0.url', "/api/v1/orders/{$assignedOrder}/photos/{$photoId}")
            ->assertJsonPath('data.order.documentos.0.url', "/api/v1/orders/{$assignedOrder}/documents/{$documentId}");

        $historico = collect($response->json('data.order.historico', []));
        $this->assertCount(5, $historico);
        $this->assertSame('status_6', (string) ($historico->first()['status_novo'] ?? ''));
        $this->assertSame('status_2', (string) ($historico->last()['status_novo'] ?? ''));
    }

    public function test_show_returns_403_when_order_is_not_assigned_to_technician(): void
    {
        [$user, , $unassignedOrder] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/orders/{$unassignedOrder}");

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ORDER_FORBIDDEN');
    }

    public function test_photo_endpoint_serves_legacy_file_through_controlled_route(): void
    {
        [$user, $assignedOrder, , $photoId] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get("/api/v1/orders/{$assignedOrder}/photos/{$photoId}");

        $response->assertOk();
        $this->assertStringContainsString('image/jpeg', (string) $response->headers->get('Content-Type'));
    }

    public function test_document_endpoint_serves_legacy_file_through_controlled_route(): void
    {
        [$user, $assignedOrder, , , $documentId] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get("/api/v1/orders/{$assignedOrder}/documents/{$documentId}");

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_patch_status_updates_status_estado_fluxo_and_history(): void
    {
        [$user, $assignedOrder] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/orders/{$assignedOrder}/status", [
                'status' => 'aguardando_reparo',
                'observacao' => 'Liberado para execução.',
            ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.id', $assignedOrder)
            ->assertJsonPath('data.status_anterior', 'triagem')
            ->assertJsonPath('data.status_novo', 'aguardando_reparo')
            ->assertJsonPath('data.estado_fluxo', 'em_execucao');

        $this->assertDatabaseHas('os', [
            'id' => $assignedOrder,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
        ]);

        $this->assertDatabaseHas('os_status_historico', [
            'os_id' => $assignedOrder,
            'status_anterior' => 'triagem',
            'status_novo' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
            'usuario_id' => $user->id,
        ]);
    }

    public function test_patch_status_returns_403_when_order_is_not_assigned_to_technician(): void
    {
        [$user, , $unassignedOrder] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/orders/{$unassignedOrder}/status", [
                'status' => 'aguardando_reparo',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ORDER_FORBIDDEN');
    }

    public function test_patch_status_rejects_status_not_present_in_runtime_catalog(): void
    {
        [$user, $assignedOrder] = $this->seedTechnicianOrders();
        $token = $this->loginAndGetToken($user->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/orders/{$assignedOrder}/status", [
                'status' => 'status_inexistente',
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertNotEmpty($response->json('error.details.status'));
    }

    public function test_admin_can_create_a_new_order(): void
    {
        [$manager, $technician, $clientId, $equipmentId] = $this->seedManagerCreateContext();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'tecnico_id' => $technician->id,
                'status' => 'triagem',
                'relato_cliente' => 'Cliente informa superaquecimento.',
                'diagnostico_tecnico' => 'Aguardando análise inicial.',
                'garantia_dias' => 120,
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.order.tecnico_id', $technician->id)
            ->assertJsonPath('data.order.relato_cliente', 'Cliente informa superaquecimento.');

        $numeroOs = (string) $response->json('data.order.numero_os');
        $this->assertMatchesRegularExpression('/^OS\d{8}$/', $numeroOs);

        $this->assertDatabaseHas('os', [
            'numero_os' => $numeroOs,
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $technician->id,
        ]);
    }

    public function test_admin_can_update_an_order_and_cache_remains_valid_for_next_requests(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->patchJson("/api/v1/orders/{$orderId}", [
                'status' => 'aguardando_reparo',
                'estado_fluxo' => 'em_execucao',
                'prioridade' => 'alta',
                'relato_cliente' => 'Novo relato do cliente',
                'observacoes_internas' => 'Peça aprovada para compra',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonPath('data.order.status', 'aguardando_reparo')
            ->assertJsonPath('data.order.prioridade', 'alta');

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_reparo',
            'prioridade' => 'alta',
            'relato_cliente' => 'Novo relato do cliente',
        ]);

        Cache::forget('rbac_user_' . $manager->id);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'aguardando_reparo');
    }

    public function test_closure_metadata_returns_closure_options_and_financial_summary(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson("/api/v1/orders/{$orderId}/closure");

        $response->assertOk()
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonPath('data.financeiro.titulo_id', null)
            ->assertJsonPath('data.cliente_telefone', '(11) 3333-4444');

        $codigos = collect($response->json('data.opcoes_encerramento', []))->pluck('codigo');
        $this->assertTrue($codigos->contains('entregue_reparado'));
    }

    public function test_close_executes_status_change_and_registers_full_payment(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        DB::table('os')->where('id', $orderId)->update(['valor_final' => 150.00]);
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [
                    ['valor' => 150.00, 'forma_pagamento' => 'pix'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.id', $orderId)
            ->assertJsonPath('data.order.status', 'entregue_reparado')
            ->assertJsonPath('data.order.estado_fluxo', 'encerrado')
            ->assertJsonPath('data.notificacao_enviada', null);

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'entregue_reparado',
            'estado_fluxo' => 'encerrado',
        ]);

        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertNotNull($order->data_entrega);
        $this->assertNotNull($order->baixa_tecnica_em);
        $this->assertSame((int) $manager->id, (int) $order->baixa_tecnica_por);

        $this->assertDatabaseHas('financeiro', [
            'os_id' => $orderId,
            'tipo' => 'receber',
            'valor' => 150.00,
        ]);

        $titulo = DB::table('financeiro')->where('os_id', $orderId)->where('tipo', 'receber')->first();
        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => 'entrada',
            'valor_movimento' => 150.00,
            'forma_pagamento' => 'pix',
        ]);
    }

    public function test_close_allows_closing_without_any_payment(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.status', 'entregue_reparado');

        $this->assertDatabaseHas('financeiro', [
            'os_id' => $orderId,
            'tipo' => 'receber',
            'status' => 'pendente',
        ]);
        $this->assertDatabaseMissing('financeiro_movimentos', [
            'financeiro_id' => DB::table('financeiro')->where('os_id', $orderId)->value('id'),
        ]);
    }

    public function test_close_rejects_a_status_that_is_not_a_closure_status(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'aguardando_reparo',
                'data_entrega' => now()->toDateString(),
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'triagem']);
    }

    public function test_close_returns_403_when_order_is_not_assigned_to_technician(): void
    {
        [, , $unassignedOrder] = $this->seedTechnicianOrders();
        $technician = $this->createUserRecord([
            'nome' => 'Outro Tecnico',
            'email' => 'outro.tecnico@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);
        $token = $this->loginAndGetToken($technician->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$unassignedOrder}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
            ]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'ORDER_FORBIDDEN');
    }

    public function test_close_notification_failure_does_not_revert_the_closure(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);

        $this->mock(\App\Services\Channels\Whatsapp\WhatsappMessagingService::class, function ($mock): void {
            $mock->shouldReceive('sendSystemMessage')->andThrow(new \RuntimeException('Falha simulada de integração.'));
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'notificar_cliente' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.status', 'entregue_reparado')
            ->assertJsonPath('data.notificacao_enviada', false);

        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'entregue_reparado']);
    }

    public function test_close_with_card_payment_registers_fee_metadata_and_expense(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        DB::table('os')->where('id', $orderId)->update(['valor_final' => 200.00]);
        $card = $this->seedCardRate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [
                    [
                        'valor' => 200.00,
                        'forma_pagamento' => 'cartao_credito',
                        'operadora_id' => $card['operadora_id'],
                        'modalidade' => 'credito',
                        'parcelas' => 1,
                    ],
                ],
            ]);

        $response->assertOk()->assertJsonPath('data.order.status', 'entregue_reparado');

        $titulo = DB::table('financeiro')->where('os_id', $orderId)->where('tipo', 'receber')->first();
        $movimento = DB::table('financeiro_movimentos')->where('financeiro_id', $titulo->id)->first();

        $this->assertDatabaseHas('financeiro_movimentos_cartao', [
            'movimento_id' => $movimento->id,
            'operadora_id' => $card['operadora_id'],
            'taxa_id' => $card['taxa_id'],
            'parcelas' => 1,
            'valor_bruto' => 200.00,
            'valor_taxa' => 10.50,
            'valor_liquido' => 189.50,
        ]);

        $this->assertDatabaseHas('financeiro', [
            'os_id' => $orderId,
            'tipo' => 'pagar',
            'categoria' => 'Taxa de cartão',
            'valor' => 10.50,
            'status' => 'pago',
            'origem_tipo' => 'os_recebimento_cartao',
            'origem_id' => $movimento->id,
        ]);
    }

    public function test_close_rejects_card_payment_without_matching_rate(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $card = $this->seedCardRate();
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [
                    [
                        'valor' => 100.00,
                        'forma_pagamento' => 'cartao_debito',
                        'operadora_id' => $card['operadora_id'],
                        'modalidade' => 'debito',
                        'parcelas' => 1,
                    ],
                ],
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'ORDER_CLOSURE_CARD_PAYMENT_INVALID');

        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'triagem']);
        $this->assertDatabaseMissing('financeiro', ['os_id' => $orderId]);
    }

    public function test_close_with_open_balance_applies_pending_payment_status_and_schedules_three_collections(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        DB::table('os')->where('id', $orderId)->update(['valor_final' => 300.00]);
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [
                    ['valor' => 100.00, 'forma_pagamento' => 'pix'],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.order.status', 'entregue_pagamento_pendente')
            ->assertJsonPath('data.order.status_final_pendente_pagamento', 'entregue_reparado');

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'entregue_pagamento_pendente',
            'status_final_pendente_pagamento' => 'entregue_reparado',
        ]);

        $agendamentos = DB::table('os_cobranca_agendamentos')->where('os_id', $orderId)->orderBy('prazo_dias')->get();
        $this->assertCount(3, $agendamentos);
        $this->assertSame([1, 3, 5], $agendamentos->pluck('prazo_dias')->map(fn ($v) => (int) $v)->all());

        foreach ($agendamentos as $agendamento) {
            $this->assertSame('pendente', $agendamento->status);
        }
    }

    public function test_close_again_after_full_payment_cancels_previous_collections(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        DB::table('os')->where('id', $orderId)->update(['valor_final' => 300.00]);
        $token = $this->loginAndGetToken($manager->email);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [['valor' => 100.00, 'forma_pagamento' => 'pix']],
            ])->assertOk();

        $this->assertSame(
            3,
            DB::table('os_cobranca_agendamentos')->where('os_id', $orderId)->where('status', 'pendente')->count()
        );

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'recebimentos' => [['valor' => 200.00, 'forma_pagamento' => 'pix']],
            ]);

        $response->assertOk()->assertJsonPath('data.order.status', 'entregue_reparado');

        $this->assertSame(
            0,
            DB::table('os_cobranca_agendamentos')->where('os_id', $orderId)->where('status', 'pendente')->count()
        );
        $this->assertSame(
            3,
            DB::table('os_cobranca_agendamentos')->where('os_id', $orderId)->where('status', 'cancelado')->count()
        );
    }

    public function test_close_with_no_repair_status_ignores_open_balance_and_skips_collections(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        DB::table('os')->where('id', $orderId)->update(['valor_final' => 150.00]);
        $token = $this->loginAndGetToken($manager->email);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'devolvido_sem_reparo',
                'data_entrega' => now()->toDateString(),
            ]);

        $response->assertOk()->assertJsonPath('data.order.status', 'devolvido_sem_reparo');

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'devolvido_sem_reparo',
            'status_final_pendente_pagamento' => null,
        ]);
        $this->assertSame(0, DB::table('os_cobranca_agendamentos')->where('os_id', $orderId)->count());
    }

    public function test_close_with_agendar_retorno_creates_crm_followup_and_dedups(): void
    {
        [$manager, $orderId] = $this->seedManagerOrderForUpdate();
        $token = $this->loginAndGetToken($manager->email);
        $retornoData = now()->addDays(180)->toDateString();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/orders/{$orderId}/closure", [
                'encerrar_como' => 'entregue_reparado',
                'data_entrega' => now()->toDateString(),
                'agendar_retorno' => true,
                'retorno_data' => $retornoData,
            ]);

        $response->assertOk();

        $this->assertSame(1, DB::table('crm_followups')->where('os_id', $orderId)->count());

        $followup = DB::table('crm_followups')->where('os_id', $orderId)->first();
        $this->assertSame('pendente', $followup->status);
        $this->assertStringContainsString('os_retorno_agendado_' . $orderId . '_', (string) $followup->origem_evento);

        $duplicateId = app(\App\Services\Orders\OrderClosureService::class)
            ->createReturnFollowup($orderId, $retornoData, (int) $manager->id);

        $this->assertNull($duplicateId);
        $this->assertSame(1, DB::table('crm_followups')->where('os_id', $orderId)->count());
    }

    public function test_process_pending_os_collections_command_sends_due_charges(): void
    {
        [, $orderId] = $this->seedManagerOrderForUpdate();
        $clientId = (int) DB::table('os')->where('id', $orderId)->value('cliente_id');

        DB::table('os')->where('id', $orderId)->update([
            'status' => 'entregue_pagamento_pendente',
            'status_final_pendente_pagamento' => 'entregue_reparado',
        ]);

        $tituloId = (int) DB::table('financeiro')->insertGetId([
            'os_id' => $orderId,
            'cliente_id' => $clientId,
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 300.00,
            'status' => 'pendente',
            'data_vencimento' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('os_cobranca_agendamentos')->insert([
            'os_id' => $orderId,
            'financeiro_id' => $tituloId,
            'cliente_id' => $clientId,
            'canal' => 'whatsapp',
            'prazo_dias' => 1,
            'enviar_em' => now()->subHour(),
            'status' => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(\App\Services\Channels\Whatsapp\WhatsappMessagingService::class, function ($mock): void {
            $mock->shouldReceive('sendSystemMessage')->once()->andReturn(['ok' => true]);
        });

        $this->artisan('app:process-pending-os-collections')->assertExitCode(0);

        $this->assertDatabaseHas('os_cobranca_agendamentos', [
            'os_id' => $orderId,
            'status' => 'enviado',
        ]);
    }

    public function test_process_pending_os_collections_cancels_when_order_left_pending_status(): void
    {
        [, $orderId] = $this->seedManagerOrderForUpdate();

        DB::table('os_cobranca_agendamentos')->insert([
            'os_id' => $orderId,
            'financeiro_id' => null,
            'cliente_id' => null,
            'canal' => 'whatsapp',
            'prazo_dias' => 1,
            'enviar_em' => now()->subHour(),
            'status' => 'pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:process-pending-os-collections')->assertExitCode(0);

        $this->assertDatabaseHas('os_cobranca_agendamentos', [
            'os_id' => $orderId,
            'status' => 'cancelado',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array{operadora_id: int, taxa_id: int}
     */
    private function seedCardRate(array $overrides = []): array
    {
        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId(array_merge([
            'nome' => 'Operadora Teste',
            'descricao' => null,
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides['operadora'] ?? []));

        $taxaId = (int) DB::table('financeiro_cartao_taxas')->insertGetId(array_merge([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 12,
            'taxa_percentual' => 5,
            'taxa_fixa' => 0.50,
            'prazo_recebimento_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides['taxa'] ?? []));

        return ['operadora_id' => $operadoraId, 'taxa_id' => $taxaId];
    }

    /**
     * @return array{0: User, 1: int, 2: int, 3: int, 4: int}
     */
    private function seedTechnicianOrders(): array
    {
        $user = $this->createUserRecord([
            'nome' => 'Técnico PWA',
            'email' => 'tecnico.pwa@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);

        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId, [
            'resumo_tecnico' => 'Notebook Acer Aspire',
            'numero_serie' => 'ABC123',
            'imei' => 'IMEI123456789',
        ]);

        $assignedOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060001',
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => $user->id,
            'relato_cliente' => 'Sem relato',
            'diagnostico_tecnico' => 'Diagnóstico inicial',
        ]);

        for ($i = 1; $i <= 6; $i++) {
            DB::table('os_status_historico')->insert([
                'os_id' => $assignedOrder,
                'status_anterior' => $i === 1 ? 'triagem' : 'status_' . ($i - 1),
                'status_novo' => 'status_' . $i,
                'estado_fluxo' => 'em_execucao',
                'usuario_id' => $user->id,
                'observacao' => 'Histórico ' . $i,
                'created_at' => now()->addMinutes($i),
            ]);
        }

        $photoRelativePath = 'uploads/os_anormalidades/os0001_recepcao_1.jpg';
        $photoAbsolutePath = $this->writeLegacyFile($photoRelativePath, 'foto-teste');
        $photoId = (int) DB::table('os_fotos')->insertGetId([
            'os_id' => $assignedOrder,
            'tipo' => 'recepcao',
            'arquivo' => basename($photoAbsolutePath),
            'created_at' => now(),
        ]);

        $documentRelativePath = 'uploads/os_documentos/OS0001/abertura_v1.pdf';
        $documentAbsolutePath = $this->writeLegacyFile($documentRelativePath, 'pdf-teste');
        $documentId = (int) DB::table('os_documentos')->insertGetId([
            'os_id' => $assignedOrder,
            'tipo_documento' => 'abertura',
            'arquivo' => $documentRelativePath,
            'versao' => 1,
            'hash_sha1' => sha1('pdf-teste'),
            'gerado_por' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unassignedOrder = $this->createOrderRecord([
            'numero_os' => 'OS26060002',
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => null,
            'relato_cliente' => 'Sem relato 2',
        ]);

        return [$user, $assignedOrder, $unassignedOrder, $photoId, $documentId];
    }

    /**
     * @return array{0: User, 1: User, 2: User, 3: int, 4: int, 5: int, 6: int}
     */
    private function seedAdminOrderActors(): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Gerente',
            'email' => 'gerente@example.com',
            'perfil' => 'admin',
            'grupo_id' => 4,
        ]);

        $techA = $this->createUserRecord([
            'nome' => 'Técnico A',
            'email' => 'tecnico.a@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);

        $techB = $this->createUserRecord([
            'nome' => 'Técnico B',
            'email' => 'tecnico.b@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);

        $clientA = $this->createClientRecord([
            'nome_razao' => 'Cliente Alpha',
            'cpf_cnpj' => '11.111.111/0001-11',
            'email' => 'alpha@example.com',
        ]);
        $clientB = $this->createClientRecord([
            'nome_razao' => 'Cliente Beta',
            'cpf_cnpj' => '22.222.222/0001-22',
            'email' => 'beta@example.com',
        ]);

        $equipmentA = $this->createEquipmentRecord($clientA, [
            'resumo_tecnico' => 'Desktop Gamer',
            'numero_serie' => 'SER-ALPHA',
        ]);
        $equipmentB = $this->createEquipmentRecord($clientB, [
            'resumo_tecnico' => 'MacBook Pro',
            'numero_serie' => 'SER-BETA',
        ]);

        return [$manager, $techA, $techB, $clientA, $clientB, $equipmentA, $equipmentB];
    }

    /**
     * @return array{0: User, 1: User, 2: int, 3: int}
     */
    private function seedManagerCreateContext(): array
    {
        $manager = $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);

        $technician = $this->createUserRecord([
            'nome' => 'Técnico Campo',
            'email' => 'tecnico.campo@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);

        $clientId = $this->createClientRecord([
            'nome_razao' => 'Cliente Criação',
            'cpf_cnpj' => '33.333.333/0001-33',
        ]);
        $equipmentId = $this->createEquipmentRecord($clientId, [
            'resumo_tecnico' => 'All-in-One Lenovo',
        ]);

        return [$manager, $technician, $clientId, $equipmentId];
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function seedManagerOrderForUpdate(): array
    {
        [$manager, $technician, $clientId, $equipmentId] = $this->seedManagerCreateContext();

        $orderId = $this->createOrderRecord([
            'numero_os' => 'OS26060021',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tecnico_id' => $technician->id,
            'status' => 'triagem',
            'estado_fluxo' => 'em_atendimento',
            'relato_cliente' => 'Relato inicial',
        ]);

        return [$manager, $orderId];
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'pwa-mobile',
        ]);

        return (string) $response->json('data.access_token');
    }

    private function writeLegacyFile(string $relativePath, string $contents): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $absolutePath = $this->legacyPublicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($absolutePath, $contents);

        return $absolutePath;
    }
}
