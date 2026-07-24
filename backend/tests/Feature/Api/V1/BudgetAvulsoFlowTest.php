<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Cobre a divisão avulso × OS: derivação de tipo, ações de decisão do técnico
 * (aprovar/rejeitar/cancelar por outros meios) e a geração de OS a partir de um
 * orçamento avulso aprovado.
 */
class BudgetAvulsoFlowTest extends TestCase
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
            'clientes' => ['visualizar', 'criar'],
            'equipamentos' => ['visualizar'],
            'os' => ['visualizar', 'criar', 'editar'],
            'servicos' => ['visualizar'],
            'estoque' => ['visualizar'],
        ]);
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();
    }

    private function admin(): User
    {
        return $this->createUserRecord([
            'nome' => 'Administrador',
            'email' => 'admin.avulso@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    private function loginAndGetToken(string $email): string
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'Senha@123',
            'device_name' => 'desktop-avulso',
        ]);

        return (string) $response->json('data.access_token');
    }

    public function test_budget_without_os_is_created_as_previo_even_if_assistencia_requested(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Avulso']);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                // Pede assistencia/os de propósito: sem os_id deve virar previo/manual.
                'tipo_orcamento' => 'assistencia',
                'origem' => 'os',
                'status' => 'rascunho',
                'cliente_id' => $clientId,
                'titulo' => 'Orçamento avulso',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Diagnóstico', 'quantidade' => 1, 'valor_unitario' => 100],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.tipo_orcamento', 'previo')
            ->assertJsonPath('data.budget.origem', 'manual');

        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'tipo_orcamento' => 'previo',
            'origem' => 'manual',
            'os_id' => null,
        ]);
    }

    public function test_budget_with_os_is_created_as_assistencia(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente OS']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook']);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070300',
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'tipo_orcamento' => 'previo', // pede previo, mas com OS vira assistencia
                'cliente_id' => $clientId,
                'os_id' => $orderId,
                'equipamento_id' => $equipmentId,
                'titulo' => 'Orçamento na assistência',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Reparo', 'quantidade' => 1, 'valor_unitario' => 200],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.tipo_orcamento', 'assistencia')
            ->assertJsonPath('data.budget.origem', 'os');
    }

    public function test_staff_approval_of_avulso_budget_moves_to_pending_os(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Aprovação']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
            'origem' => 'manual',
            'os_id' => null,
            'subtotal' => 150.00,
            'total' => 150.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Serviço', 'valor_unitario' => 150, 'total' => 150]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/aprovar', [
                'observacao' => 'Cliente aprovou pelo telefone.',
            ]);

        $response->assertOk()->assertJsonPath('data.budget.status', 'pendente_abertura_os');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'pendente_abertura_os']);
        $this->assertDatabaseHas('orcamento_aprovacoes', [
            'orcamento_id' => $budgetId,
            'acao' => 'aprovado',
            'origem' => 'painel',
        ]);
    }

    public function test_staff_approval_of_assistencia_budget_sets_aprovado(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente OS Aprov']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Celular']);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'numero_os' => 'OS26070301',
        ]);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'tipo_orcamento' => 'assistencia',
            'origem' => 'os',
            'status' => 'aguardando_resposta',
            'subtotal' => 300.00,
            'total' => 300.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Reparo', 'valor_unitario' => 300, 'total' => 300]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$budgetId.'/aprovar', [])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'aprovado');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => 'aprovado']);
    }

    public function test_staff_can_reject_and_cancel_budget(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Recusa']);
        $token = $this->loginAndGetToken($admin->email);

        $rejectId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
            'total' => 90.00,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$rejectId.'/rejeitar', ['motivo' => 'Cliente achou caro.'])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'rejeitado');
        $this->assertDatabaseHas('orcamentos', [
            'id' => $rejectId,
            'status' => 'rejeitado',
            'motivo_rejeicao' => 'Cliente achou caro.',
        ]);

        $cancelId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
            'numero' => 'ORC-'.now()->format('ym').'-000099',
            'total' => 90.00,
        ]);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos/'.$cancelId.'/cancelar', ['motivo' => 'Sem resposta há 15 dias.'])
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'cancelado');
        $this->assertDatabaseHas('orcamentos', ['id' => $cancelId, 'status' => 'cancelado']);
        $this->assertNotNull(DB::table('orcamentos')->where('id', $cancelId)->value('cancelado_em'));
    }

    public function test_generate_os_from_approved_avulso_links_and_converts(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Conversão']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Tablet']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'pendente_abertura_os',
            'tipo_orcamento' => 'previo',
            'origem' => 'manual',
            'os_id' => null,
            'subtotal' => 250.00,
            'total' => 250.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'servico',
            'descricao' => 'Troca de conector',
            'valor_unitario' => 250,
            'total' => 250,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Equipamento trazido para reparo já orçado.',
                'garantia_dias' => 90,
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $this->assertGreaterThan(0, $orderId);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'os_id' => $orderId,
            'status' => 'convertido',
            'convertido_tipo' => 'os',
            'convertido_id' => $orderId,
        ]);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'valor_final' => 250.00,
        ]);
    }

    public function test_generate_os_rejects_budget_from_a_different_client(): void
    {
        $admin = $this->admin();
        $clientA = $this->createClientRecord(['nome_razao' => 'Cliente A']);
        $clientB = $this->createClientRecord(['nome_razao' => 'Cliente B', 'cpf_cnpj' => '99.888.777/0001-66']);
        $equipmentB = $this->createEquipmentRecord($clientB, ['resumo_tecnico' => 'Equip B']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientA,
            'status' => 'pendente_abertura_os',
            'tipo_orcamento' => 'previo',
            'os_id' => null,
            'total' => 120.00,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientB,
                'equipamento_id' => $equipmentB,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tentativa de vínculo com cliente divergente.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_BUDGET_LINK_INVALID');

        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'os_id' => null, 'status' => 'pendente_abertura_os']);
    }

    public function test_avulso_budget_stores_eventual_equipment(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_nome_avulso' => 'Paulo Eventual',
                'telefone_contato' => '22999990000',
                'envolve_equipamento' => true,
                'equipamento_tipo_avulso' => 'Smartphone',
                'equipamento_marca_avulso' => 'Apple',
                'equipamento_modelo_avulso' => 'iPhone 16',
                'equipamento_cor' => 'Preto',
                'titulo' => 'Tela quebrada',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Troca de tela', 'quantidade' => 1, 'valor_unitario' => 500],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.envolve_equipamento', true)
            ->assertJsonPath('data.budget.equipamento_modelo_avulso', 'iPhone 16');

        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Paulo Eventual',
            'equipamento_id' => null,
            'equipamento_tipo_avulso' => 'Smartphone',
            'equipamento_marca_avulso' => 'Apple',
            'equipamento_modelo_avulso' => 'iPhone 16',
            'equipamento_cor' => 'Preto',
        ]);
    }

    public function test_registered_equipment_clears_eventual_fields(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Cadastrado Equip']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook cadastrado']);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'envolve_equipamento' => true,
                // Enviado por engano — deve ser descartado pela exclusividade.
                'equipamento_modelo_avulso' => 'iPhone 16',
                'titulo' => 'Reparo',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Reparo', 'quantidade' => 1, 'valor_unitario' => 200],
                ],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'equipamento_id' => $equipmentId,
            'equipamento_modelo_avulso' => null,
            'equipamento_tipo_avulso' => null,
        ]);
    }

    public function test_service_without_equipment_clears_all_equipment_fields(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Serviço']);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                // Serviço puro (visita técnica): não envolve equipamento.
                'envolve_equipamento' => false,
                'equipamento_modelo_avulso' => 'iPhone 16',
                'titulo' => 'Visita técnica',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Visita técnica', 'quantidade' => 1, 'valor_unitario' => 80],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.envolve_equipamento', false);
        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'equipamento_id' => null,
            'equipamento_modelo_avulso' => null,
            'equipamento_cor' => null,
        ]);
    }

    public function test_create_order_defers_new_client_and_equipment_until_save(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'novo_cliente' => [
                    'nome_razao' => 'Cliente Diferido',
                    'telefone1' => '22999998888',
                    'email' => 'diferido@example.com',
                ],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'numero_serie_visual' => 'SN-DIFERIDO',
                    'cor' => 'Preto',
                ],
                'relato_cliente' => 'Aparelho não liga após queda.',
                'garantia_dias' => 90,
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $this->assertGreaterThan(0, $orderId);

        $this->assertDatabaseHas('clientes', ['nome_razao' => 'Cliente Diferido', 'telefone1' => '22999998888']);
        $clientId = (int) DB::table('clientes')->where('nome_razao', 'Cliente Diferido')->value('id');
        $this->assertGreaterThan(0, $clientId);
        $this->assertDatabaseHas('equipamentos', ['cliente_id' => $clientId, 'tipo_id' => 1]);
        $equipmentId = (int) DB::table('equipamentos')->where('cliente_id', $clientId)->value('id');
        $this->assertDatabaseHas('os', ['id' => $orderId, 'cliente_id' => $clientId, 'equipamento_id' => $equipmentId]);
    }

    public function test_create_order_persists_deferred_equipment_photos_atomically(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/orders', [
                'novo_cliente' => [
                    'nome_razao' => 'Cliente Com Foto',
                    'telefone1' => '22999990000',
                ],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'cor' => 'Prata',
                    'foto_principal_index' => 1,
                ],
                'novo_equipamento_fotos' => [
                    UploadedFile::fake()->image('frente.jpg'),
                    UploadedFile::fake()->image('verso.jpg'),
                ],
                'relato_cliente' => 'Aparelho com tela trincada.',
                'garantia_dias' => 90,
            ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $this->assertGreaterThan(0, $orderId);

        $clientId = (int) DB::table('clientes')->where('nome_razao', 'Cliente Com Foto')->value('id');
        $equipmentId = (int) DB::table('equipamentos')->where('cliente_id', $clientId)->value('id');
        $this->assertGreaterThan(0, $equipmentId);

        // As duas fotos capturadas no navegador nasceram junto com a OS (atômico),
        // e a foto principal respeita o índice enviado (a segunda).
        $this->assertSame(2, (int) DB::table('equipamentos_fotos')->where('equipamento_id', $equipmentId)->count());
        $this->assertSame(1, (int) DB::table('equipamentos_fotos')
            ->where('equipamento_id', $equipmentId)
            ->where('is_principal', 1)
            ->count());
    }

    public function test_create_order_without_client_or_new_client_is_rejected(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'relato_cliente' => 'Sem cliente informado.',
                'garantia_dias' => 90,
            ])
            ->assertStatus(422);
    }

    public function test_generate_os_from_avulso_defers_client_and_equipment(): void
    {
        $admin = $this->admin();
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Otavio Eventual',
            'telefone_contato' => '2299990000',
            'status' => 'pendente_abertura_os',
            'tipo_orcamento' => 'previo',
            'origem' => 'manual',
            'os_id' => null,
            'equipamento_modelo_avulso' => 'iPhone 16',
            'subtotal' => 300.00,
            'total' => 300.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Troca de tela', 'valor_unitario' => 300, 'total' => 300]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'novo_cliente' => ['nome_razao' => 'Otavio Eventual', 'telefone1' => '2299990000'],
                'novo_equipamento' => ['tipo_id' => 1, 'numero_serie_visual' => 'SN-9'],
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tela quebrada.',
                'garantia_dias' => 90,
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $clientId = (int) DB::table('clientes')->where('nome_razao', 'Otavio Eventual')->value('id');
        $this->assertGreaterThan(0, $clientId);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'os_id' => $orderId,
            'status' => 'convertido',
            'cliente_id' => $clientId,
        ]);
    }

    public function test_avulso_budget_stores_client_report(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_nome_avulso' => 'Cliente Relato',
                'envolve_equipamento' => true,
                'equipamento_modelo_avulso' => 'iPhone 16',
                'relato_cliente' => 'Cliente relatou que a tela trincou após queda.',
                'titulo' => 'Tela quebrada',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Troca de tela', 'quantidade' => 1, 'valor_unitario' => 500],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.budget.relato_cliente', 'Cliente relatou que a tela trincou após queda.');

        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'relato_cliente' => 'Cliente relatou que a tela trincou após queda.',
        ]);
    }

    public function test_registered_client_clears_eventual_client_name(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Prioritário']);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                // Nome eventual enviado por engano — cadastrado tem prioridade.
                'cliente_nome_avulso' => 'Deveria ser descartado',
                'envolve_equipamento' => false,
                'titulo' => 'Serviço',
                'itens' => [
                    ['tipo_item' => 'servico', 'descricao' => 'Serviço', 'quantidade' => 1, 'valor_unitario' => 50],
                ],
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('orcamentos', [
            'id' => (int) $response->json('data.budget.id'),
            'cliente_id' => $clientId,
            'cliente_nome_avulso' => null,
        ]);
    }
}
