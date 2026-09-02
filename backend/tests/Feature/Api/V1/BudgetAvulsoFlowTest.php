<?php

namespace Tests\Feature\Api\V1;

use App\Models\Budget;
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
 * orçamento avulso disponível.
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
            'orcamentos' => ['visualizar', 'criar', 'editar', 'excluir', 'converter_os'],
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
        Storage::fake('local');

        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/orders', [
                'novo_cliente' => [
                    'nome_razao' => 'Cliente Diferido',
                    'telefone1' => '22999998888',
                    'email' => 'diferido@example.com',
                ],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'marca_id' => 2,
                    'modelo_id' => 2,
                    'numero_serie_visual' => 'SN-DIFERIDO',
                    'cor' => 'Preto',
                ],
                'novo_equipamento_fotos' => [
                    UploadedFile::fake()->image('equipamento-diferido.jpg'),
                ],
                'relato_cliente' => 'Aparelho não liga após queda.',
                'garantia_dias' => 90,
            ], ['Accept' => 'application/json']);

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
                    'marca_id' => 2,
                    'modelo_id' => 2,
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

    public function test_create_order_requires_brand_and_model_for_deferred_equipment(): void
    {
        Storage::fake('local');

        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/orders', [
                'novo_cliente' => [
                    'nome_razao' => 'Cliente Sem Catálogo',
                    'telefone1' => '22999996666',
                ],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'numero_serie_visual' => 'SN-SEM-CATALOGO',
                ],
                'novo_equipamento_fotos' => [
                    UploadedFile::fake()->image('equipamento-sem-catalogo.jpg'),
                ],
                'relato_cliente' => 'Equipamento sem marca e modelo.',
                'garantia_dias' => 90,
            ], ['Accept' => 'application/json']);

        $response->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $details = $response->json('error.details');

        $this->assertIsArray($details);
        $this->assertSame(
            ['Selecione uma marca para o equipamento.'],
            $details['novo_equipamento.marca_id'] ?? null
        );
        $this->assertSame(
            ['Selecione um modelo para o equipamento.'],
            $details['novo_equipamento.modelo_id'] ?? null
        );
    }

    public function test_create_order_with_deferred_equipment_without_photo_is_rejected_without_persisting_records(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $clientsBefore = DB::table('clientes')->count();
        $equipmentsBefore = DB::table('equipamentos')->count();
        $ordersBefore = DB::table('os')->count();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'novo_cliente' => [
                    'nome_razao' => 'Cliente Sem Foto',
                    'telefone1' => '22999997777',
                ],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'marca_id' => 2,
                    'modelo_id' => 2,
                    'numero_serie_visual' => 'SN-SEM-FOTO',
                ],
                'relato_cliente' => 'Equipamento sem imagem obrigatória.',
                'garantia_dias' => 90,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'novo_equipamento_fotos',
                    ],
                ],
            ]);

        $this->assertSame($clientsBefore, DB::table('clientes')->count());
        $this->assertSame($equipmentsBefore, DB::table('equipamentos')->count());
        $this->assertSame($ordersBefore, DB::table('os')->count());
    }

    public function test_generate_os_from_avulso_defers_client_and_equipment(): void
    {
        Storage::fake('local');

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
            ->post('/api/v1/orders', [
                'novo_cliente' => ['nome_razao' => 'Otavio Eventual', 'telefone1' => '2299990000'],
                'novo_equipamento' => [
                    'tipo_id' => 1,
                    'marca_id' => 2,
                    'modelo_id' => 2,
                    'numero_serie_visual' => 'SN-9',
                ],
                'novo_equipamento_fotos' => [
                    UploadedFile::fake()->image('iphone-eventual.jpg'),
                ],
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tela quebrada.',
                'garantia_dias' => 90,
            ], ['Accept' => 'application/json']);

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

    public function test_order_creation_with_budget_requires_specific_conversion_permission(): void
    {
        $this->grantGroupPermissions(2, [
            'os' => ['criar'],
        ]);
        $technician = $this->createUserRecord([
            'nome' => 'Técnico sem conversão',
            'email' => 'tecnico.sem.conversao@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente protegido']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tipo_orcamento' => 'previo',
            'status' => 'pendente_abertura_os',
            'os_id' => null,
        ]);
        $token = $this->loginAndGetToken($technician->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/vinculaveis-os')
            ->assertForbidden();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tentativa sem autorização específica.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('os', ['relato_cliente' => 'Tentativa sem autorização específica.']);
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'pendente_abertura_os',
            'os_id' => null,
        ]);

        $this->grantGroupPermissions(3, [
            'orcamentos' => ['converter_os'],
        ]);
        $converterWithoutOrderCreate = $this->createUserRecord([
            'nome' => 'Conversor sem criar OS',
            'email' => 'conversor.sem.os@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);
        $converterToken = $this->loginAndGetToken($converterWithoutOrderCreate->email);

        $this->withHeader('Authorization', 'Bearer '.$converterToken)
            ->getJson('/api/v1/orcamentos/vinculaveis-os')
            ->assertForbidden();
    }

    public function test_linkable_budget_catalog_returns_every_available_status_and_excludes_terminal_candidates(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Catálogo Seguro']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook catálogo']);
        $linkableIds = [];
        $detailId = 0;

        foreach (Budget::linkableToOrderStatuses() as $index => $status) {
            $budgetId = $this->createBudgetRecord([
                'numero' => sprintf('ORC-LINK-%02d', $index + 1),
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'tipo_orcamento' => 'previo',
                'status' => $status,
                'os_id' => null,
                'aprovado_em' => $status === Budget::STATUS_PENDING_OS ? now() : null,
                'total' => 345.67,
            ]);
            $linkableIds[] = $budgetId;

            if ($status === Budget::STATUS_PENDING_OS) {
                $detailId = $budgetId;
            }
        }

        foreach ([Budget::STATUS_CANCELLED, Budget::STATUS_REJECTED, Budget::STATUS_CONVERTED] as $index => $status) {
            $this->createBudgetRecord([
                'numero' => sprintf('ORC-TERMINAL-%02d', $index + 1),
                'cliente_id' => $clientId,
                'tipo_orcamento' => 'previo',
                'status' => $status,
                'os_id' => null,
            ]);
        }

        $this->createBudgetRecord([
            'numero' => 'ORC-TIPO-ERRADO',
            'cliente_id' => $clientId,
            'tipo_orcamento' => 'assistencia',
            'status' => 'pendente_abertura_os',
            'os_id' => null,
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/vinculaveis-os?q=Cat%C3%A1logo&per_page=30');

        $response->assertOk()->assertJsonCount(count($linkableIds), 'data.budgets');
        $this->assertEqualsCanonicalizing(
            $linkableIds,
            array_map('intval', (array) $response->json('data.budgets.*.id'))
        );
        $response
            ->assertJsonFragment(['status' => Budget::STATUS_SENT, 'status_label' => 'Enviado'])
            ->assertJsonFragment(['equipamento_resumo' => 'Notebook catálogo', 'total_formatado' => '345,67']);

        $detailResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/vinculaveis-os/'.$detailId)
            ->assertOk()
            ->assertJsonPath('data.budget.status', 'pendente_abertura_os')
            ->assertJsonPath('data.budget.equipamento.id', $equipmentId);

        $this->assertArrayNotHasKey(
            'cpf_cnpj',
            (array) $detailResponse->json('data.budget.cliente'),
            'O endpoint de conversão não deve expor documento pessoal do cliente.'
        );
        $this->assertArrayNotHasKey(
            'telefone1',
            (array) $detailResponse->json('data.budget.cliente'),
            'O endpoint de conversão não deve duplicar contatos do cadastro do cliente.'
        );
        $this->assertArrayNotHasKey(
            'email',
            (array) $detailResponse->json('data.budget.cliente'),
            'O endpoint de conversão deve retornar apenas o contexto mínimo.'
        );
        $this->assertArrayNotHasKey(
            'numero_serie',
            (array) $detailResponse->json('data.budget.equipamento'),
            'O endpoint de conversão não deve expor o número de série do equipamento.'
        );
        $this->assertArrayNotHasKey(
            'imei',
            (array) $detailResponse->json('data.budget.equipamento'),
            'O endpoint de conversão não deve expor o IMEI do equipamento.'
        );

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/vinculaveis-os?q=%25&per_page=10')
            ->assertOk()
            ->assertJsonCount(0, 'data.budgets');
    }

    public function test_generate_os_rejects_equipment_different_from_registered_budget_equipment(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente dois equipamentos']);
        $budgetEquipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Equipamento orçado']);
        $otherEquipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Equipamento indevido']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $budgetEquipmentId,
            'tipo_orcamento' => 'previo',
            'status' => 'pendente_abertura_os',
            'os_id' => null,
        ]);
        $ordersBefore = DB::table('os')->count();
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $otherEquipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tentativa com equipamento divergente.',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ORDER_BUDGET_LINK_INVALID');

        $this->assertSame($ordersBefore, DB::table('os')->count());
        $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'os_id' => null]);
    }

    public function test_second_conversion_of_same_budget_is_a_conflict_and_does_not_duplicate_order(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente concorrência']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tipo_orcamento' => 'previo',
            'status' => 'pendente_abertura_os',
            'os_id' => null,
        ]);
        $ordersBefore = DB::table('os')->count();
        $token = $this->loginAndGetToken($admin->email);
        $payload = [
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'orcamento_id' => $budgetId,
            'relato_cliente' => 'Conversão protegida contra duplicidade.',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_BUDGET_LINK_CONFLICT');

        $this->assertSame($ordersBefore + 1, DB::table('os')->count());
        $this->assertSame(1, DB::table('os')->where('relato_cliente', $payload['relato_cliente'])->count());
    }

    public function test_assistance_or_generically_approved_budget_cannot_use_conversion_flow(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente estado inválido']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tipo_orcamento' => 'assistencia',
            'status' => 'aprovado',
            'os_id' => null,
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Tentativa fora do fluxo canônico.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ORDER_BUDGET_LINK_CONFLICT');
    }

    public function test_converted_budget_is_immutable_and_active_budget_cannot_be_hard_deleted(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente snapshot']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
        ]);
        $convertedId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'os_id' => $orderId,
            'tipo_orcamento' => 'previo',
            'status' => 'convertido',
            'convertido_tipo' => 'os',
            'convertido_id' => $orderId,
        ]);
        $activeId = $this->createBudgetRecord([
            'numero' => 'ORC-ATIVO-NAO-EXCLUIR',
            'cliente_id' => $clientId,
            'tipo_orcamento' => 'previo',
            'status' => 'aprovado',
            'os_id' => null,
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$convertedId, ['titulo' => 'Tentativa de alteração'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_IMMUTABLE');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/orcamentos/'.$convertedId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_IMMUTABLE');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/v1/orcamentos/'.$activeId)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BUDGET_NOT_DELETABLE');

        $this->assertDatabaseHas('orcamentos', ['id' => $convertedId, 'status' => 'convertido']);
        $this->assertDatabaseHas('orcamentos', ['id' => $activeId, 'status' => 'aprovado']);
    }

    public function test_generic_budget_endpoint_cannot_forge_conversion_lifecycle_status(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente ciclo protegido']);
        $budgetId = $this->createBudgetRecord([
            'cliente_id' => $clientId,
            'tipo_orcamento' => 'previo',
            'status' => 'rascunho',
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_id' => $clientId,
                'envolve_equipamento' => false,
                'titulo' => 'Tentativa de criar orçamento já aprovado',
                'status' => 'pendente_abertura_os',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/v1/orcamentos/'.$budgetId, [
                'titulo' => 'Tentativa de transição genérica',
                'status' => 'pendente_abertura_os',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'rascunho',
        ]);
    }

    public function test_generic_budget_request_rejects_server_managed_lifecycle_fields(): void
    {
        $admin = $this->admin();
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orcamentos', [
                'cliente_nome_avulso' => 'Cliente malicioso',
                'envolve_equipamento' => false,
                'titulo' => 'Tentativa de controlar ciclo de vida',
                'token_publico' => str_repeat('a', 64),
                'aprovado_em' => now()->toDateTimeString(),
                'convertido_tipo' => 'os',
                'convertido_id' => 999,
            ])
            ->assertStatus(422)
            ->assertJsonStructure([
                'error' => [
                    'details' => [
                        'token_publico',
                        'aprovado_em',
                        'convertido_tipo',
                        'convertido_id',
                    ],
                ],
            ]);
    }

    public function test_avulso_contacts_endpoint_finds_by_name_or_phone_and_excludes_ineligible(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Cadastrado Avulso']);

        $openId = $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-ABERTO',
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Marcia Contato Avulso',
            'telefone_contato' => '22988887777',
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
        ]);
        // Cliente já cadastrado: não é avulso, não deve aparecer na busca.
        $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-CADASTRADO',
            'cliente_id' => $clientId,
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
        ]);
        // Estado terminal: não representa mais atendimento em aberto.
        $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-CANCELADO',
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Marcia Cancelada',
            'status' => 'cancelado',
            'tipo_orcamento' => 'previo',
        ]);
        $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-RASCUNHO',
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Marcia Rascunho',
            'status' => 'rascunho',
            'tipo_orcamento' => 'previo',
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $byName = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/contatos-avulsos?q=Marcia+Contato');
        $byName->assertOk()
            ->assertJsonCount(1, 'data.budgets')
            ->assertJsonPath('data.budgets.0.id', $openId)
            // aguardando_resposta agora é vinculável: a OS nasce em
            // aguardando_autorizacao e o orçamento continua aberto até o
            // cliente responder de fato.
            ->assertJsonPath('data.budgets.0.linkable', true);

        $byPhone = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/contatos-avulsos?q=988887777');
        $byPhone->assertOk()
            ->assertJsonCount(1, 'data.budgets')
            ->assertJsonPath('data.budgets.0.id', $openId);
    }

    public function test_avulso_contacts_endpoint_flags_pending_os_budget_as_linkable(): void
    {
        $admin = $this->admin();
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-LINKAVEL',
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Renato Pronto Pra OS',
            'telefone_contato' => '22977776666',
            'status' => 'pendente_abertura_os',
            'tipo_orcamento' => 'previo',
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/contatos-avulsos?q=Renato')
            ->assertOk()
            ->assertJsonCount(1, 'data.budgets')
            ->assertJsonPath('data.budgets.0.id', $budgetId)
            ->assertJsonPath('data.budgets.0.linkable', true);
    }

    public function test_avulso_contacts_endpoint_requires_conversion_permission(): void
    {
        $this->grantGroupPermissions(2, [
            'os' => ['criar'],
        ]);
        $technician = $this->createUserRecord([
            'nome' => 'Técnico sem conversão avulso',
            'email' => 'tecnico.sem.conversao.avulso@example.com',
            'perfil' => 'tecnico',
            'grupo_id' => 2,
        ]);
        $this->createBudgetRecord([
            'numero' => 'ORC-AVULSO-PROTEGIDO',
            'cliente_id' => null,
            'cliente_nome_avulso' => 'Contato Protegido',
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
        ]);
        $token = $this->loginAndGetToken($technician->email);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/orcamentos/contatos-avulsos?q=Contato')
            ->assertForbidden();
    }

    public function test_generate_os_from_budget_awaiting_client_reply_sets_awaiting_authorization_status_and_keeps_budget_open(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente Aguardando Resposta']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Celular aguardando resposta']);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-AGUARDANDO-RESPOSTA',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'aguardando_resposta',
            'tipo_orcamento' => 'previo',
            'os_id' => null,
            'subtotal' => 180.00,
            'total' => 180.00,
        ]);
        $this->createBudgetItemRecord($budgetId, ['descricao' => 'Troca de bateria', 'valor_unitario' => 180, 'total' => 180]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Cliente ainda decidindo sobre o orçamento.',
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $this->assertGreaterThan(0, $orderId);

        // A OS nasce aguardando a decisão do cliente, independente do status
        // padrão de abertura (triagem).
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_autorizacao',
        ]);

        // O orçamento fica vinculado (os_id preenchido) mas continua aberto:
        // não vira "convertido", não fica imutável, mantém o status original.
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'os_id' => $orderId,
            'status' => 'aguardando_resposta',
            'convertido_tipo' => null,
            'convertido_id' => null,
        ]);
    }

    public function test_generate_os_from_sent_budget_keeps_it_open_and_waits_for_authorization(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente orçamento enviado']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-ENVIADO-VINCULAVEL',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'status' => 'enviado',
            'tipo_orcamento' => 'previo',
            'os_id' => null,
        ]);
        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                'relato_cliente' => 'Orçamento enviado, cliente ainda não respondeu.',
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'aguardando_autorizacao']);
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'status' => 'enviado',
            'os_id' => $orderId,
            'convertido_tipo' => null,
            'convertido_id' => null,
        ]);
    }

    public function test_generate_os_rejects_cancelled_and_rejected_budgets(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente orçamento terminal']);
        $equipmentId = $this->createEquipmentRecord($clientId);
        $token = $this->loginAndGetToken($admin->email);

        foreach (['cancelado', 'rejeitado'] as $index => $status) {
            $budgetId = $this->createBudgetRecord([
                'numero' => sprintf('ORC-TERMINAL-OS-%02d', $index + 1),
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'status' => $status,
                'tipo_orcamento' => 'previo',
                'os_id' => null,
            ]);

            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/orders', [
                    'cliente_id' => $clientId,
                    'equipamento_id' => $equipmentId,
                    'orcamento_id' => $budgetId,
                    'relato_cliente' => 'Tentativa com orçamento em estado terminal.',
                ])
                ->assertStatus(409)
                ->assertJsonPath('error.code', 'ORDER_BUDGET_LINK_CONFLICT');

            $this->assertDatabaseHas('orcamentos', ['id' => $budgetId, 'status' => $status, 'os_id' => null]);
        }
    }

    public function test_linkable_budget_catalog_filters_by_client_and_approved_status(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente com orçamento aprovado']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Notebook aprovado']);
        $otherClientId = $this->createClientRecord([
            'nome_razao' => 'Outro cliente',
            'cpf_cnpj' => '55.444.333/0001-22',
        ]);

        $approvedIds = [];
        foreach (Budget::approvedForOrderLinkStatuses() as $index => $status) {
            $approvedIds[] = $this->createBudgetRecord([
                'numero' => sprintf('ORC-APROVADO-%02d', $index + 1),
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'tipo_orcamento' => 'previo',
                'status' => $status,
                'os_id' => null,
                'aprovado_em' => now(),
                'total' => 199.90,
            ]);
        }

        // Mesmo cliente, mas ainda sem aprovação: fica fora do filtro.
        $this->createBudgetRecord([
            'numero' => 'ORC-SEM-APROVACAO',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tipo_orcamento' => 'previo',
            'status' => Budget::STATUS_SENT,
            'os_id' => null,
        ]);

        // Aprovado, porém de outro cliente: não pode aparecer para este.
        $this->createBudgetRecord([
            'numero' => 'ORC-OUTRO-CLIENTE',
            'cliente_id' => $otherClientId,
            'tipo_orcamento' => 'previo',
            'status' => Budget::STATUS_APPROVED,
            'os_id' => null,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(sprintf('/api/v1/orcamentos/vinculaveis-os?cliente_id=%d&somente_aprovados=1', $clientId))
            ->assertOk()
            ->assertJsonCount(count($approvedIds), 'data.budgets');

        $this->assertEqualsCanonicalizing(
            $approvedIds,
            array_map('intval', (array) $response->json('data.budgets.*.id'))
        );

        // O app precisa do equipamento do orçamento para já selecioná-lo na OS.
        $response->assertJsonFragment([
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'equipamento_resumo' => 'Notebook aprovado',
        ]);

        // Sem o filtro de aprovação, o orçamento apenas enviado volta à lista.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(sprintf('/api/v1/orcamentos/vinculaveis-os?cliente_id=%d', $clientId))
            ->assertOk()
            ->assertJsonCount(count($approvedIds) + 1, 'data.budgets');
    }

    public function test_generate_os_from_approved_budget_opens_in_awaiting_repair(): void
    {
        $admin = $this->admin();
        $clientId = $this->createClientRecord(['nome_razao' => 'Cliente reparo autorizado']);
        $equipmentId = $this->createEquipmentRecord($clientId, ['resumo_tecnico' => 'Celular autorizado']);
        $budgetId = $this->createBudgetRecord([
            'numero' => 'ORC-APROVADO-PARA-OS',
            'cliente_id' => $clientId,
            'equipamento_id' => $equipmentId,
            'tipo_orcamento' => 'previo',
            'status' => Budget::STATUS_APPROVED,
            'os_id' => null,
            'aprovado_em' => now(),
            'subtotal' => 320.00,
            'total' => 320.00,
        ]);
        $this->createBudgetItemRecord($budgetId, [
            'tipo_item' => 'servico',
            'descricao' => 'Troca de tela',
            'valor_unitario' => 320,
            'total' => 320,
        ]);

        $token = $this->loginAndGetToken($admin->email);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/orders', [
                'cliente_id' => $clientId,
                'equipamento_id' => $equipmentId,
                'orcamento_id' => $budgetId,
                // O status pedido é ignorado: quem manda é o orçamento aprovado.
                'status' => 'triagem',
                'relato_cliente' => 'Cliente já aprovou o orçamento e trouxe o aparelho.',
            ]);

        $response->assertCreated();
        $orderId = (int) $response->json('data.order.id');

        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'aguardando_reparo',
            'estado_fluxo' => 'em_execucao',
            'valor_final' => 320.00,
        ]);
        $this->assertDatabaseHas('orcamentos', [
            'id' => $budgetId,
            'os_id' => $orderId,
            'status' => 'convertido',
            'convertido_tipo' => 'os',
            'convertido_id' => $orderId,
        ]);
    }
}
