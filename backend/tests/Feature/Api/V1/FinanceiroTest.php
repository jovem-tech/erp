<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
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
}
