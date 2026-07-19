<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\OsCobrancaAgendamento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroTest extends TestCase
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
            'os' => ['visualizar'],
        ]);
    }

    public function test_default_dre_catalog_is_seeded_by_the_migration(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/financeiro/catalogo');

        $response->assertOk()
            ->assertJsonPath('data.dre_grupos.0.nome', 'Receita Operacional')
            ->assertJsonCount(5, 'data.dre_grupos')
            ->assertJsonCount(12, 'data.categorias');
    }

    public function test_receivable_without_client_or_order_is_rejected(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Receita avulsa de teste',
            'valor' => 150.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'FINANCEIRO_SAVE_FAILED');
    }

    public function test_standalone_receivable_without_client_is_accepted_when_marked_as_avulso(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Configuração remota simples',
            'valor' => 75.00,
            'data_vencimento' => now()->toDateString(),
            'avulso' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.lancamento.avulso', true)
            ->assertJsonPath('data.lancamento.cliente_id', null)
            ->assertJsonPath('data.lancamento.os_id', null);
    }

    public function test_avulso_with_order_is_rejected(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Vínculo inválido de teste',
            'valor' => 100.00,
            'data_vencimento' => now()->toDateString(),
            'os_id' => $orderId,
            'avulso' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_SAVE_FAILED');
    }

    public function test_lancamento_lifecycle_create_partial_and_full_settlement(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'OS de teste',
            'cliente_id' => $clienteId,
            'avulso' => true,
            'valor' => 200.00,
            'data_vencimento' => now()->addDays(10)->toDateString(),
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.lancamento.status', 'pendente')
            ->assertJsonPath('data.lancamento.avulso', true)
            ->assertJsonPath('data.lancamento.grupo_dre', 'Receita Operacional')
            ->assertJsonPath('data.lancamento.subgrupo_dre', 'Serviços e peças de OS');

        $financeiroId = (int) $store->json('data.lancamento.id');

        $partial = $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 80.00,
            'forma_pagamento' => 'pix',
        ]);

        $partial->assertOk()
            ->assertJsonPath('data.resumo.status_resolvido', 'parcial')
            ->assertJsonPath('data.lancamento.status', 'parcial');

        $blockedTypeChange = $this->putJson("/api/v1/financeiro/{$financeiroId}", [
            'tipo' => 'pagar',
        ]);
        $blockedTypeChange->assertStatus(422);

        $blockedAvulsoChange = $this->putJson("/api/v1/financeiro/{$financeiroId}", [
            'avulso' => false,
        ]);
        $blockedAvulsoChange->assertStatus(422);

        $final = $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 120.00,
            'forma_pagamento' => 'pix',
        ]);

        $final->assertOk()
            ->assertJsonPath('data.resumo.status_resolvido', 'pago')
            ->assertJsonPath('data.lancamento.status', 'pago');

        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
        ]);
        $this->assertDatabaseCount('financeiro_movimentos', 2);
    }

    public function test_index_can_be_filtered_by_cliente_id(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $outroClienteId = $this->createClientRecord(['nome_razao' => 'Outro cliente']);
        Sanctum::actingAs($admin, ['*']);

        $expected = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Recebimento do cliente esperado',
            'cliente_id' => $clienteId,
            'avulso' => true,
            'valor' => 120.00,
            'data_vencimento' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Recebimento de outro cliente',
            'cliente_id' => $outroClienteId,
            'avulso' => true,
            'valor' => 90.00,
            'data_vencimento' => now()->toDateString(),
        ])->assertCreated();

        $response = $this->getJson("/api/v1/financeiro?cliente_id={$clienteId}&tipo=receber");

        $response->assertOk()
            ->assertJsonCount(1, 'data.lancamentos')
            ->assertJsonPath('data.lancamentos.0.id', $expected->json('data.lancamento.id'))
            ->assertJsonPath('data.lancamentos.0.cliente_id', $clienteId);
    }

    public function test_index_orders_by_data_pagamento_descending_with_pending_titles_last(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        // Vencimento não importa para a ordenação: o mais antigo a vencer foi
        // o último a ser efetivamente pago, e deve aparecer primeiro.
        $paidOldestDueOldest = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'cliente_id' => $clienteId,
            'categoria' => 'Serviço',
            'descricao' => 'Pago por último, vencia primeiro',
            'valor' => 100.00,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->subDays(10)->toDateString(),
            'data_pagamento' => now()->toDateString(),
        ]);

        $paidMiddle = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'cliente_id' => $clienteId,
            'categoria' => 'Serviço',
            'descricao' => 'Pago no meio',
            'valor' => 100.00,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->toDateString(),
            'data_pagamento' => now()->subDays(3)->toDateString(),
        ]);

        $pending = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'cliente_id' => $clienteId,
            'categoria' => 'Serviço',
            'descricao' => 'Ainda pendente',
            'valor' => 100.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'data_pagamento' => null,
        ]);

        $response = $this->getJson('/api/v1/financeiro?cliente_id=' . $clienteId);

        $response->assertOk()
            ->assertJsonPath('data.lancamentos.0.id', $paidOldestDueOldest->id)
            ->assertJsonPath('data.lancamentos.1.id', $paidMiddle->id)
            ->assertJsonPath('data.lancamentos.2.id', $pending->id);
    }

    public function test_creating_with_status_pago_registers_full_settlement_automatically(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Venda de peças',
            'descricao' => 'Venda de peça de teste',
            'cliente_id' => $clienteId,
            'valor' => 90.00,
            'status' => 'pago',
            'forma_pagamento' => 'dinheiro',
            'data_vencimento' => now()->toDateString(),
        ]);

        $store->assertCreated()->assertJsonPath('data.lancamento.status', 'pago');

        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
            'valor_movimento' => 90.00,
        ]);
    }

    public function test_creating_paid_title_with_financial_account_records_account_only_on_movement(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $accountId = $this->createFinancialAccount([
            'nome' => 'Conta Inter',
            'instituicao' => 'Banco Inter',
            'cor' => '#FF7A00',
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Venda de peças',
            'descricao' => 'Tela A05',
            'valor' => 100.00,
            'status' => 'pago',
            'forma_pagamento' => 'pix',
            'conta_financeira_id' => $accountId,
            'data_vencimento' => now()->toDateString(),
            'data_pagamento' => now()->toDateString(),
        ]);

        $store->assertCreated()
            ->assertJsonPath('data.lancamento.status', 'pago');
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->assertDatabaseHas('financeiro', [
            'id' => $financeiroId,
            'descricao' => 'Tela A05',
            'status' => 'pago',
        ]);
        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
            'conta_financeira_id' => $accountId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'valor_movimento' => 100.00,
        ]);
    }

    public function test_paid_title_creation_rolls_back_when_account_settlement_fails(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $accountId = $this->createFinancialAccount([
            'nome' => 'Conta com controle iniciado hoje',
        ]);
        $titlesBefore = Financeiro::query()->count();
        $movementsBefore = FinanceiroMovimento::query()->count();

        $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Venda de peças',
            'descricao' => 'Pagamento com data inválida para a conta',
            'valor' => 100.00,
            'status' => 'pago',
            'forma_pagamento' => 'pix',
            'conta_financeira_id' => $accountId,
            'data_vencimento' => now()->subDay()->toDateString(),
            'data_pagamento' => now()->subDay()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_SAVE_FAILED');

        $this->assertSame($titlesBefore, Financeiro::query()->count());
        $this->assertSame($movementsBefore, FinanceiroMovimento::query()->count());
    }

    public function test_updating_title_to_paid_records_selected_financial_account_on_movement(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);
        $accountId = $this->createFinancialAccount([
            'nome' => 'Caixa físico',
            'tipo' => 'caixa',
        ]);
        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'pagar',
            'categoria' => 'Venda de peças',
            'descricao' => 'Título inicialmente pendente',
            'valor' => 80.00,
            'data_vencimento' => now()->toDateString(),
        ])->assertCreated();
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->patchJson('/api/v1/financeiro/'.$financeiroId, [
            'status' => 'pago',
            'forma_pagamento' => 'dinheiro',
            'conta_financeira_id' => $accountId,
            'data_pagamento' => now()->toDateString(),
        ])->assertOk()
            ->assertJsonPath('data.lancamento.status', 'pago');

        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
            'conta_financeira_id' => $accountId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'valor_movimento' => 80.00,
        ]);
    }

    public function test_show_returns_operational_details_for_order_receivable_with_card_fee(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Detalhado']);
        $equipamentoId = $this->createEquipmentRecord($clienteId, [
            'tipo_id' => 2,
            'marca_id' => 1,
            'modelo_id' => 1,
            'numero_serie' => 'SN-DET-001',
            'resumo_tecnico' => 'Notebook Dell Inspiron 15',
        ]);
        $osId = $this->createOrderRecord([
            'numero_os' => 'OS26070099',
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'relato_cliente' => 'Tela sem imagem',
            'diagnostico_tecnico' => 'Flat desconectado',
            'data_entrega' => now(),
            'valor_final' => 100.00,
        ]);
        Sanctum::actingAs($admin, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Stone',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'debito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 1.99,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orcamentoId = $this->createBudgetRecord([
            'numero' => 'ORC-2607-000008',
            'os_id' => $osId,
            'cliente_id' => $clienteId,
            'status' => 'aprovado',
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS OS26070099',
            'os_id' => $osId,
            'valor' => 100.00,
            'data_vencimento' => now()->toDateString(),
        ])->assertCreated();
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 100.00,
            'forma_pagamento' => 'cartao_debito',
            'operadora_id' => $operadoraId,
            'modalidade' => 'debito',
            'parcelas' => 1,
        ])->assertOk();

        $response = $this->getJson("/api/v1/financeiro/{$financeiroId}");

        $response->assertOk()
            ->assertJsonPath('data.detalhes.contraparte.nome', 'Cliente Detalhado')
            ->assertJsonPath('data.detalhes.contraparte.id', $clienteId)
            ->assertJsonPath('data.detalhes.os.orcamento.id', $orcamentoId)
            ->assertJsonPath('data.detalhes.os.orcamento.numero', 'ORC-2607-000008')
            ->assertJsonPath('data.detalhes.origem.tipo', 'os')
            ->assertJsonPath('data.detalhes.os.numero_os', 'OS26070099')
            ->assertJsonPath('data.detalhes.os.equipamento.tipo', 'Notebook')
            ->assertJsonPath('data.detalhes.os.equipamento.marca', 'Dell')
            ->assertJsonPath('data.detalhes.os.equipamento.modelo', 'Inspiron 15')
            ->assertJsonPath('data.detalhes.os.equipamento.serie', 'SN-DET-001')
            ->assertJsonPath('data.detalhes.os.defeito.relato_cliente', 'Tela sem imagem')
            ->assertJsonPath('data.detalhes.os.defeito.diagnostico_tecnico', 'Flat desconectado')
            ->assertJsonPath('data.detalhes.movimentos.0.forma_pagamento_label', 'Cartão de débito')
            ->assertJsonPath('data.detalhes.movimentos.0.cartao.operadora', 'Stone')
            ->assertJsonPath('data.detalhes.movimentos.0.cartao.valor_taxa', 1.99);
    }

    public function test_dre_catalog_crud_for_grupo_subgrupo_and_categoria(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $grupo = $this->postJson('/api/v1/financeiro/dre-grupos', [
            'nome' => 'Investimentos',
            'ordem_exibicao' => 60,
        ])->assertCreated();

        $grupoId = (int) $grupo->json('data.dre_grupo.id');

        $subgrupo = $this->postJson('/api/v1/financeiro/dre-subgrupos', [
            'grupo_id' => $grupoId,
            'nome' => 'Equipamentos novos',
        ])->assertCreated();

        $subgrupoId = (int) $subgrupo->json('data.dre_subgrupo.id');

        $categoria = $this->postJson('/api/v1/financeiro/categorias', [
            'nome' => 'Compra de maquinário',
            'tipo' => 'pagar',
            'dre_grupo_id' => $grupoId,
            'dre_subgrupo_id' => $subgrupoId,
        ])->assertCreated();

        $this->assertDatabaseHas('financeiro_categorias', [
            'id' => $categoria->json('data.categoria.id'),
            'nome' => 'Compra de maquinário',
            'dre_grupo_id' => $grupoId,
        ]);

        $duplicate = $this->postJson('/api/v1/financeiro/dre-grupos', [
            'nome' => 'Investimentos',
        ]);
        $duplicate->assertStatus(422);
    }

    public function test_user_without_permission_cannot_create_lancamento(): void
    {
        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($attendant, ['*']);

        $response = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Serviço de teste',
            'cliente_id' => $clienteId,
            'valor' => 50,
            'data_vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertStatus(403);
    }

    public function test_cancel_marks_pending_lancamento_as_cancelado(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Lançamento a cancelar',
            'cliente_id' => $clienteId,
            'valor' => 90.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ]);
        $store->assertCreated();
        $financeiroId = (int) $store->json('data.lancamento.id');

        $cancel = $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar");

        $cancel->assertOk()
            ->assertJsonPath('data.lancamento.status', 'cancelado')
            ->assertJsonPath('data.lancamento.data_pagamento', null);

        $this->assertDatabaseHas('financeiro', [
            'id' => $financeiroId,
            'status' => 'cancelado',
        ]);
    }

    public function test_cancel_reverses_movements_of_a_partially_paid_lancamento(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Lançamento com baixa parcial',
            'cliente_id' => $clienteId,
            'valor' => 100.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ]);
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 40.00,
            'forma_pagamento' => 'pix',
        ])->assertOk();

        $cancel = $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar");

        $cancel->assertOk()
            ->assertJsonPath('data.lancamento.status', 'cancelado')
            ->assertJsonPath('data.lancamento.data_pagamento', null)
            ->assertJsonPath('data.lancamento.forma_pagamento', null);

        $this->assertDatabaseHas('financeiro', [
            'id' => $financeiroId,
            'status' => 'cancelado',
        ]);
        $this->assertDatabaseMissing('financeiro_movimentos', [
            'financeiro_id' => $financeiroId,
        ]);
    }

    public function test_cancel_is_rejected_when_lancamento_is_already_cancelado(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Lançamento já cancelado',
            'cliente_id' => $clienteId,
            'valor' => 50.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ]);
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")->assertOk();

        $secondCancel = $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar");

        $secondCancel->assertStatus(422)->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_FAILED');
    }

    public function test_baixa_em_cartao_registra_a_taxa_da_operadora_como_despesa_separada(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Stone',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'debito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 1.99,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Serviço pago no débito',
            'cliente_id' => $clienteId,
            'valor' => 100.00,
            'data_vencimento' => now()->toDateString(),
        ]);
        $financeiroId = (int) $store->json('data.lancamento.id');

        $baixa = $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 100.00,
            'forma_pagamento' => 'cartao_debito',
            'operadora_id' => $operadoraId,
            'modalidade' => 'debito',
            'parcelas' => 1,
        ]);
        $baixa->assertOk()->assertJsonPath('data.lancamento.status', 'pago');

        // 1,99% de R$100,00 = R$1,99 de taxa.
        $this->assertDatabaseHas('financeiro', [
            'categoria' => 'Taxa de cartão',
            'valor' => 1.99,
            'status' => 'pago',
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Taxas e impostos',
            'origem_tipo' => 'financeiro_movimento_cartao',
        ]);

        $taxaFinanceiroId = (int) DB::table('financeiro')->where('categoria', 'Taxa de cartão')->value('id');
        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $taxaFinanceiroId,
            'valor_movimento' => 1.99,
        ]);

        $fluxo = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $fluxo->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 100.0)
            ->assertJsonPath('data.fluxo.saidas_realizadas', 1.99)
            ->assertJsonPath('data.fluxo.saldo_final', 98.01);

        $dre = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));
        $dre->assertOk()->assertJsonPath('data.dre.despesas_operacionais.total', 1.99);
    }

    public function test_taxa_de_cartao_e_reconhecida_no_dia_do_pagamento_mesmo_com_repasse_futuro(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Cielo',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'credito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 2.50,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Serviço pago no crédito parcelado',
            'cliente_id' => $clienteId,
            'valor' => 100.00,
            'data_vencimento' => now()->toDateString(),
        ]);
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 100.00,
            'forma_pagamento' => 'cartao_credito',
            'operadora_id' => $operadoraId,
            'modalidade' => 'credito',
            'parcelas' => 1,
        ])->assertOk();

        // O repasse da operadora só cai em 30 dias, mas o fluxo de caixa deve
        // sentir o custo da taxa HOJE (mesmo dia da venda), não daqui a 30
        // dias — senão o saldo de hoje mostra o valor bruto como se a
        // assistência tivesse embolsado o valor cheio.
        $this->assertDatabaseHas('financeiro', [
            'categoria' => 'Taxa de cartão',
            'valor' => 2.50,
            'data_vencimento' => now()->toDateString() . ' 00:00:00',
            'data_pagamento' => now()->toDateString() . ' 00:00:00',
        ]);

        $fluxo = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $fluxo->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 100.0)
            ->assertJsonPath('data.fluxo.saidas_realizadas', 2.50)
            ->assertJsonPath('data.fluxo.saldo_final', 97.50);
    }

    public function test_cancelar_lancamento_pago_em_cartao_tambem_cancela_a_despesa_da_taxa(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Stone',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'debito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 1.99,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Receita avulsa',
            'descricao' => 'Lançamento pago no débito e depois cancelado',
            'cliente_id' => $clienteId,
            'valor' => 50.00,
            'data_vencimento' => now()->toDateString(),
        ]);
        $financeiroId = (int) $store->json('data.lancamento.id');

        $this->postJson("/api/v1/financeiro/{$financeiroId}/baixar", [
            'valor_movimento' => 50.00,
            'forma_pagamento' => 'cartao_debito',
            'operadora_id' => $operadoraId,
            'modalidade' => 'debito',
            'parcelas' => 1,
        ])->assertOk();

        $taxaFinanceiroId = (int) DB::table('financeiro')->where('categoria', 'Taxa de cartão')->value('id');
        $this->assertDatabaseHas('financeiro', ['id' => $taxaFinanceiroId, 'status' => 'pago']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")->assertOk();

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'cancelado']);
        $this->assertDatabaseHas('financeiro', ['id' => $taxaFinanceiroId, 'status' => 'cancelado']);
        $this->assertDatabaseMissing('financeiro_movimentos', ['financeiro_id' => $financeiroId]);
        $this->assertDatabaseMissing('financeiro_movimentos', ['financeiro_id' => $taxaFinanceiroId]);

        $fluxo = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $fluxo->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 0.0)
            ->assertJsonPath('data.fluxo.saidas_realizadas', 0.0)
            ->assertJsonPath('data.fluxo.saldo_final', 0.0);

        $dre = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));
        $dre->assertOk()->assertJsonPath('data.dre.despesas_operacionais.total', 0.0);
    }

    public function test_index_exposes_origin_trail_for_all_lancamento_shapes(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Rastreável']);
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'numero_os' => 'OS26070200',
        ]);
        $fornecedorId = $this->createSupplierRecord(['nome_fantasia' => 'Fornecedor Rastreável']);

        // 1) Serviço ligado a OS: cliente | OS | equipamento (marca+modelo).
        $servicoOsId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clienteId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 200.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        // 2) Serviço avulso com cliente preenchido: cliente | sem OS vinculada.
        $servicoAvulsoComClienteId = (int) Financeiro::query()->create([
            'cliente_id' => $clienteId,
            'avulso' => true,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Serviço avulso com cliente',
            'valor' => 80.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        // 3) Serviço avulso sem cliente: só "sem OS vinculada".
        $servicoAvulsoSemClienteId = (int) Financeiro::query()->create([
            'avulso' => true,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Serviço avulso sem cliente',
            'valor' => 40.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        // 4) Taxa de cartão originada da baixa de uma OS (os_recebimento_cartao):
        // cliente | OS | Título #origem.
        $movimentoOsId = (int) FinanceiroMovimento::query()->create([
            'financeiro_id' => $servicoOsId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 200.00,
        ])->id;
        $taxaOsId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Taxa de cartão',
            'descricao' => 'Taxa da operadora',
            'valor' => 8.00,
            'status' => Financeiro::STATUS_PAGO,
            'origem_tipo' => 'os_recebimento_cartao',
            'origem_id' => $movimentoOsId,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        // 5) Taxa de cartão originada de um título avulso sem cliente/OS
        // (financeiro_movimento_cartao): só "Título #origem" (o mínimo).
        $movimentoAvulsoId = (int) FinanceiroMovimento::query()->create([
            'financeiro_id' => $servicoAvulsoSemClienteId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 40.00,
        ])->id;
        $taxaAvulsaId = (int) Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Taxa de cartão',
            'descricao' => 'Taxa da operadora (avulso)',
            'valor' => 1.60,
            'status' => Financeiro::STATUS_PAGO,
            'origem_tipo' => 'financeiro_movimento_cartao',
            'origem_id' => $movimentoAvulsoId,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        // 6) Despesa fixa mensal com fornecedor: "Fixo mensal | Fornecedor".
        $despesaFixaId = (int) Financeiro::query()->create([
            'fornecedor_id' => $fornecedorId,
            'avulso' => true,
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel da loja',
            'valor' => 1500.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'dre_fixo_mensal' => true,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        $response = $this->getJson('/api/v1/financeiro?per_page=50');
        $response->assertOk();

        $trilhaPorId = collect($response->json('data.lancamentos'))
            ->keyBy('id')
            ->map(fn (array $item): array => $item['origem_trilha'] ?? []);

        $this->assertSame(['Cliente Rastreável', 'OS OS26070200', 'Dell Inspiron 15'], $trilhaPorId[$servicoOsId]);
        $this->assertSame(['Cliente Rastreável', 'sem OS vinculada'], $trilhaPorId[$servicoAvulsoComClienteId]);
        $this->assertSame(['sem OS vinculada'], $trilhaPorId[$servicoAvulsoSemClienteId]);
        $this->assertSame(['Cliente Rastreável', 'OS OS26070200', 'Título #' . $servicoOsId], $trilhaPorId[$taxaOsId]);
        $this->assertSame(['Título #' . $servicoAvulsoSemClienteId], $trilhaPorId[$taxaAvulsaId]);
        $this->assertSame(['Fixo mensal', 'Fornecedor Rastreável'], $trilhaPorId[$despesaFixaId]);
    }

    public function test_cancel_on_open_order_still_works_without_reason_or_admin(): void
    {
        [$actor, , $financeiroId] = $this->createClosedOrderReceivable(['status' => 'aguardando_reparo']);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")
            ->assertOk()
            ->assertJsonPath('data.lancamento.status', 'cancelado');
    }

    public function test_cancel_on_closed_order_is_blocked_without_reason_and_admin(): void
    {
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_REQUIRES_REASON_AND_ADMIN');

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'pago']);
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'entregue_reparado_pago']);
    }

    public function test_cancel_with_sem_reparo_reclassifies_order_as_returned_without_repair(): void
    {
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'sem_reparo',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'cancelado']);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'devolvido_sem_reparo',
            'status_final_pendente_pagamento' => null,
        ]);
        $this->assertDatabaseHas('os_status_historico', [
            'os_id' => $orderId,
            'status_anterior' => 'entregue_reparado_pago',
            'status_novo' => 'devolvido_sem_reparo',
        ]);
    }

    public function test_cancel_with_erro_cobranca_reverts_order_to_pending_payment_and_cancels_collections(): void
    {
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        $clienteId = (int) DB::table('os')->where('id', $orderId)->value('cliente_id');

        foreach ([1, 3, 5] as $prazoDia) {
            OsCobrancaAgendamento::query()->create([
                'os_id' => $orderId,
                'financeiro_id' => $financeiroId,
                'cliente_id' => $clienteId,
                'canal' => 'whatsapp',
                'prazo_dias' => $prazoDia,
                'enviar_em' => now()->addDays($prazoDia),
                'status' => OsCobrancaAgendamento::STATUS_PENDENTE,
            ]);
        }

        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'erro_cobranca',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'cancelado']);
        $this->assertDatabaseHas('os', [
            'id' => $orderId,
            'status' => 'entregue_pagamento_pendente',
            'status_final_pendente_pagamento' => 'entregue_reparado_pago',
        ]);
        $this->assertSame(
            0,
            OsCobrancaAgendamento::query()
                ->where('os_id', $orderId)
                ->where('status', OsCobrancaAgendamento::STATUS_PENDENTE)
                ->count()
        );
        $this->assertSame(
            3,
            OsCobrancaAgendamento::query()
                ->where('os_id', $orderId)
                ->where('status', OsCobrancaAgendamento::STATUS_CANCELADO)
                ->count()
        );
    }

    public function test_closing_again_after_erro_cobranca_cancellation_ignores_cancelled_title_and_creates_a_new_one(): void
    {
        // Reproduz o bug relatado: "erro_cobranca" cancela o título mas
        // mantém o os_id nele (só "fechamento_indevido" apaga o título).
        // Fechar a OS de novo tem que IGNORAR esse título cancelado e criar
        // um novo ativo — antes, ensureReceivableTitle() pegava o cancelado
        // (o único com aquele os_id) e a baixa travava com "Não é possível
        // registrar baixa em título cancelado.".
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable(['valor_final' => 130.00]);
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        $this->grantGroupPermissions(1, ['os' => ['editar']]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'erro_cobranca',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'cancelado']);

        $response = $this->postJson("/api/v1/orders/{$orderId}/closure", [
            'encerrar_como' => 'entregue_reparado_pago',
            'data_entrega' => now()->toDateString(),
            'recebimentos' => [
                ['valor' => 130.00, 'forma_pagamento' => 'pix'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('data.order.status', 'entregue_reparado_pago');

        // O título cancelado continua intocado (auditoria preservada).
        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'cancelado']);

        $novoTitulo = Financeiro::query()
            ->where('os_id', $orderId)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->where('id', '!=', $financeiroId)
            ->first();

        $this->assertNotNull($novoTitulo);
        $this->assertNotSame('cancelado', $novoTitulo->status);
        $this->assertDatabaseHas('financeiro_movimentos', [
            'financeiro_id' => $novoTitulo->id,
            'valor_movimento' => 130.00,
            'forma_pagamento' => 'pix',
        ]);
    }

    public function test_closure_preview_ignores_cancelled_title_even_when_more_recent_than_active_one(): void
    {
        // Reproduz bug relatado em produção: a prévia da baixa (GET
        // /orders/{id}/closure) buscava "o" título a receber mais recente da
        // OS sem filtrar status cancelado — igual ao bug original de
        // ensureReceivableTitle(), mas na tela que MOSTRA o saldo antes de
        // confirmar. Se o título cancelado for mais recente que o ativo, a
        // prévia exibia "saldo em aberto" do título ERRADO (o cancelado);
        // o usuário via um valor que fechava certinho na tela, mas a
        // confirmação falhava com "O valor da baixa não pode ser maior que o
        // saldo em aberto do título" porque close()/processReceipts() usa o
        // título ativo de verdade (ensureReceivableTitle(), que já filtra
        // cancelado corretamente).
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $this->grantGroupPermissions(1, ['os' => ['editar']]);
        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Preview']);
        $equipamentoId = $this->createEquipmentRecord($clienteId);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'numero_os' => 'OS26070301',
            'status' => 'entregue_pagamento_pendente',
            'valor_final' => 486.00,
        ]);

        $tituloAtivoId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clienteId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 486.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
            'created_at' => now(),
        ])->id;

        // Título cancelado criado DEPOIS do ativo (created_at mais recente) —
        // é exatamente essa ordenação que expõe o bug do "orderByDesc" sem
        // filtro de status.
        Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clienteId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança cancelada por erro',
            'valor' => 150.00,
            'status' => Financeiro::STATUS_CANCELADO,
            'data_vencimento' => now()->toDateString(),
            'created_at' => now()->addMinutes(10),
        ]);

        Sanctum::actingAs($actor, ['*']);

        $response = $this->getJson("/api/v1/orders/{$orderId}/closure");

        $response->assertOk()
            ->assertJsonPath('data.financeiro.titulo_id', $tituloAtivoId)
            ->assertJsonPath('data.financeiro.valor_aberto', 486.0);
    }

    public function test_cancel_with_fechamento_indevido_reverts_order_to_pre_closure_status(): void
    {
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable(['data_conclusao' => now()]);
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'fechamento_indevido',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'aguardando_reparo']);
        $this->assertDatabaseMissing('financeiro', ['id' => $financeiroId]);

        // Reversão completa da baixa: a OS deixa de estar "entregue", então a
        // data de entrega tem que voltar a null junto com o status — senão a
        // listagem mostra "Concluída no prazo" com uma OS que nem está mais
        // encerrada, e a data de entrega de um fechamento que foi desfeito.
        $this->assertNull(DB::table('os')->where('id', $orderId)->value('data_entrega'));

        // Prazo (SLA) também precisa sair do congelamento: "aguardando_reparo"
        // não está em OrderStatus::DEADLINE_FREEZE_CODES, então data_conclusao
        // é limpa e um novo prazo padrão (hoje+7) é aplicado, registrado no
        // histórico (ver OrderClosureService::cancelClosure()).
        $order = DB::table('os')->where('id', $orderId)->first();
        $this->assertNull($order->data_conclusao);
        $this->assertSame(now()->addDays(7)->toDateString(), (string) $order->data_previsao);
        $this->assertDatabaseHas('os_eventos', [
            'os_id' => $orderId,
            'categoria' => 'status',
            'tipo' => 'prazo_redefinido',
        ]);
    }

    public function test_cancel_on_closed_order_with_invalid_admin_credentials_is_rejected(): void
    {
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'sem_reparo',
            'admin_email' => $admin->email,
            'admin_password' => 'senha-errada',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_ADMIN_AUTH_INVALID');

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'pago']);
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'entregue_reparado_pago']);
    }

    public function test_destroy_is_blocked_when_order_is_encerrada(): void
    {
        // A exclusão (hard delete) não passava por nenhuma trava — ao
        // contrário do cancelamento, ela nem sequer perguntava motivo/admin,
        // então dava pra apagar o título de uma OS encerrada sem deixar
        // rastro nenhum. Excluir continua bloqueado; quem precisa desfazer
        // o título de uma OS encerrada deve usar "Cancelar" (que já tem toda
        // a trava de motivo+admin+correção de status).
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_BLOCKED_OS_ENCERRADA');

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId, 'status' => 'pago']);
        $this->assertDatabaseHas('os', ['id' => $orderId, 'status' => 'entregue_reparado_pago']);
    }

    public function test_destroy_requires_admin_credentials_even_when_order_is_open(): void
    {
        // Excluir é irreversível (hard delete, sem histórico) — diferente do
        // cancelamento, que só exige admin quando a OS está encerrada, a
        // exclusão exige admin sempre.
        [$actor, , $financeiroId] = $this->createClosedOrderReceivable(['status' => 'aguardando_reparo']);
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_REQUIRES_ADMIN');

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId]);
    }

    public function test_destroy_with_invalid_admin_credentials_is_rejected(): void
    {
        [$actor, , $financeiroId] = $this->createClosedOrderReceivable(['status' => 'aguardando_reparo']);
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}", [
            'admin_email' => $admin->email,
            'admin_password' => 'senha-errada',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'FINANCEIRO_ADMIN_AUTH_INVALID');

        $this->assertDatabaseHas('financeiro', ['id' => $financeiroId]);
    }

    public function test_destroy_still_works_when_order_is_open_with_valid_admin_credentials(): void
    {
        [$actor, , $financeiroId] = $this->createClosedOrderReceivable(['status' => 'aguardando_reparo']);
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}", [
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseMissing('financeiro', ['id' => $financeiroId]);
    }

    public function test_destroy_still_works_for_title_without_order(): void
    {
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($actor, ['*']);

        $financeiroId = (int) Financeiro::query()->create([
            'cliente_id' => $clienteId,
            'avulso' => true,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Lançamento avulso',
            'valor' => 50.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        $this->deleteJson("/api/v1/financeiro/{$financeiroId}", [
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $this->assertDatabaseMissing('financeiro', ['id' => $financeiroId]);
    }

    public function test_card_fee_expense_inherits_order_and_is_gated_when_order_is_closed(): void
    {
        // Bug real reportado: a despesa "Taxa de cartão" (a pagar) gerada por
        // FinanceiroService::registerCardFeeExpense() (baixa avulsa via
        // POST /financeiro/{id}/baixar) nunca herdava o os_id do título pago
        // — diferente da despesa equivalente gerada pelo fechamento da OS
        // (OrderClosureService::registerCardFeeExpense(), que já setava
        // os_id). Sem o os_id, resolveOsIsEncerrada() nunca detectava a OS
        // encerrada e a trava de motivo+admin (cancelar/excluir) era
        // simplesmente pulada para esse título.
        [$actor, $orderId] = $this->createClosedOrderReceivable();
        Sanctum::actingAs($actor, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Stone',
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro_cartao_taxas')->insert([
            'operadora_id' => $operadoraId,
            'bandeira_id' => null,
            'modalidade' => 'debito',
            'parcelas_inicial' => 1,
            'parcelas_final' => 1,
            'taxa_percentual' => 1.99,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 0,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pecaFinanceiroId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Peça',
            'descricao' => 'Peça cobrada à parte',
            'valor' => 50.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        $this->postJson("/api/v1/financeiro/{$pecaFinanceiroId}/baixar", [
            'valor_movimento' => 50.00,
            'forma_pagamento' => 'cartao_debito',
            'operadora_id' => $operadoraId,
            'modalidade' => 'debito',
            'parcelas' => 1,
        ])->assertOk();

        $taxaFinanceiroId = (int) DB::table('financeiro')
            ->where('categoria', 'Taxa de cartão')
            ->where('origem_tipo', 'financeiro_movimento_cartao')
            ->value('id');

        $this->assertDatabaseHas('financeiro', ['id' => $taxaFinanceiroId, 'os_id' => $orderId]);

        $index = $this->getJson('/api/v1/financeiro?tipo=pagar')->assertOk();
        $row = collect($index->json('data.lancamentos'))->firstWhere('id', $taxaFinanceiroId);
        $this->assertNotNull($row, 'Despesa de taxa de cartão não apareceu na listagem "a pagar".');
        $this->assertTrue($row['os_is_encerrada']);

        $this->postJson("/api/v1/financeiro/{$taxaFinanceiroId}/cancelar")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_CANCEL_REQUIRES_REASON_AND_ADMIN');

        $this->deleteJson("/api/v1/financeiro/{$taxaFinanceiroId}")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'FINANCEIRO_DELETE_BLOCKED_OS_ENCERRADA');
    }

    public function test_index_hides_open_balance_from_cancelled_title(): void
    {
        // Reproduz o bug real reportado: listagem de OS não filtrava
        // status='cancelado' ao somar valor_recebido/saldo (diferente do
        // detalhe da OS, que já filtrava corretamente) — ver
        // OrderWorkflowService::resolveReceivableSummaryByOrderId().
        [$actor, $orderId, $financeiroId] = $this->createClosedOrderReceivable();
        $admin = $this->createUserRecord(['perfil' => 'admin', 'grupo_id' => 1]);
        Sanctum::actingAs($actor, ['*']);

        $this->postJson("/api/v1/financeiro/{$financeiroId}/cancelar", [
            'motivo' => 'erro_cobranca',
            'admin_email' => $admin->email,
            'admin_password' => 'Senha@123',
        ])->assertOk();

        $response = $this->getJson('/api/v1/orders?search=' . DB::table('os')->where('id', $orderId)->value('numero_os'));

        $response->assertOk()
            ->assertJsonPath('data.orders.0.valor_recebido', null)
            ->assertJsonPath('data.orders.0.saldo', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createFinancialAccount(array $overrides): int
    {
        return (int) DB::table('financeiro_contas')->insertGetId(array_merge([
            'tipo' => 'banco',
            'instituicao' => null,
            'data_inicio_controle' => now()->toDateString(),
            'considera_disponivel' => true,
            'ativo' => true,
            'cor' => '#3868B0',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * Cria uma OS encerrada como "entregue_reparado_pago" com um título "Serviço"
     * já pago (R$130) — fixture compartilhada pelos testes de cancelamento
     * com motivo. Retorna [ator autenticado, os_id, financeiro_id].
     *
     * @param array<string, mixed> $orderOverrides
     * @return array{0: \App\Models\User, 1: int, 2: int}
     */
    private function createClosedOrderReceivable(array $orderOverrides = []): array
    {
        $actor = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Cancelamento']);
        $equipamentoId = $this->createEquipmentRecord($clienteId);

        $orderId = $this->createOrderRecord(array_merge([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'numero_os' => 'OS26070300',
            'status' => 'entregue_reparado_pago',
            'estado_fluxo' => 'encerrado',
            'data_conclusao' => now()->toDateString(),
            'data_entrega' => now(),
        ], $orderOverrides));

        // Histórico de status: necessário para a opção "fechamento_indevido"
        // (cancelClosure()) resolver o status anterior à baixa.
        DB::table('os_status_historico')->insert([
            'os_id' => $orderId,
            'status_anterior' => 'aguardando_reparo',
            'status_novo' => $orderOverrides['status'] ?? 'entregue_reparado_pago',
            'estado_fluxo' => 'encerrado',
            'usuario_id' => $actor->id,
            'observacao' => 'Baixa da OS.',
            'created_at' => now(),
        ]);

        $financeiroId = (int) Financeiro::query()->create([
            'os_id' => $orderId,
            'cliente_id' => $clienteId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço',
            'descricao' => 'Cobrança da OS',
            'valor' => 130.00,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->toDateString(),
        ])->id;

        FinanceiroMovimento::query()->create([
            'financeiro_id' => $financeiroId,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 130.00,
        ]);

        return [$actor, $orderId, $financeiroId];
    }
}
