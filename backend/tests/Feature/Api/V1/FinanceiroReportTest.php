<?php

namespace Tests\Feature\Api\V1;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroCartaoCredito;
use App\Models\FinanceiroMovimentoCartao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class FinanceiroReportTest extends TestCase
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

    /**
     * No caixa, pagar a fatura do cartão é UM evento: saiu um valor único da
     * conta para o banco. As despesas que ela cobre não podem aparecer como
     * saídas separadas do dia — elas ficam dentro da linha da fatura.
     */
    public function test_fluxo_de_caixa_agrupa_as_despesas_da_fatura_numa_linha_so(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $cartao = FinanceiroCartaoCredito::query()->create([
            'nome' => 'Inter',
            'instituicao' => 'Banco Inter',
            'dia_fechamento' => 20,
            'dia_vencimento' => 25,
            'cor' => '#3868B0',
            'ativo' => true,
        ]);

        $hoje = now()->startOfMonth()->addDays(19);
        $vencimento = now()->startOfMonth()->addDays(24)->toDateString();

        // Duas despesas da MESMA fatura, liquidadas no mesmo dia.
        foreach ([['Energia', 25.00], ['Compra de peças', 50.00]] as [$categoria, $valor]) {
            $despesa = Financeiro::create([
                'tipo' => Financeiro::TIPO_PAGAR,
                'avulso' => true,
                'cartao_credito_id' => $cartao->id,
                'cartao_modalidade' => 'credito',
                'categoria' => $categoria,
                'descricao' => $categoria.' no cartão',
                'valor' => $valor,
                'status' => Financeiro::STATUS_PAGO,
                'data_vencimento' => $vencimento,
                'data_competencia' => $hoje->toDateString(),
                'impacta_dre' => true,
                'impacta_fluxo_caixa' => true,
            ]);

            FinanceiroMovimento::create([
                'financeiro_id' => $despesa->id,
                'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
                'data_movimento' => $hoje->toDateString(),
                'valor_movimento' => $valor,
                'forma_pagamento' => 'pix',
            ]);
        }

        // Despesa comum no mesmo dia: continua como linha própria.
        $avulsa = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel do mês',
            'valor' => 10.00,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => $hoje->toDateString(),
            'data_competencia' => $hoje->toDateString(),
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);
        FinanceiroMovimento::create([
            'financeiro_id' => $avulsa->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $hoje->toDateString(),
            'valor_movimento' => 10.00,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'))
            ->assertOk();

        $linhaDoDia = collect($response->json('data.fluxo.linhas_diarias'))
            ->firstWhere('data', $hoje->toDateString());

        $movimentos = collect($linhaDoDia['detalhes']['movimentos']);

        // 2 linhas: a fatura (agrupando as duas despesas) e o aluguel.
        $this->assertCount(2, $movimentos);

        $fatura = $movimentos->firstWhere('categoria', 'Fatura de cartão de crédito');
        $this->assertNotNull($fatura);
        $this->assertSame('Fatura Inter', $fatura['origem']);
        $this->assertSame(75.0, $fatura['valor']);
        $this->assertSame($vencimento, $fatura['fatura']['data_vencimento']);

        // As despesas cobertas ficam dentro da fatura, não soltas no dia.
        $this->assertCount(2, $fatura['itens']);
        $this->assertEqualsCanonicalizing(
            ['Energia', 'Compra de peças'],
            array_column($fatura['itens'], 'categoria')
        );

        $aluguel = $movimentos->firstWhere('categoria', 'Aluguel');
        $this->assertNotNull($aluguel);
        $this->assertArrayNotHasKey('itens', $aluguel);

        // O total do dia não muda: agrupar é só apresentação.
        $this->assertSame(85.0, round((float) $linhaDoDia['saidas_realizadas'], 2));
    }

    public function test_dre_competencia_reconhece_receita_de_os_pela_data_de_entrega(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_entrega' => now()->startOfMonth()->addDays(2),
            'valor_total' => 500,
            'desconto' => 50,
            'valor_final' => 450,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel do mês',
            'valor' => 100,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(5),
            'data_competencia' => now()->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.modo', 'competencia')
            ->assertJsonPath('data.dre.receita.receita_bruta', 500.0)
            ->assertJsonPath('data.dre.receita.descontos', 50.0)
            ->assertJsonPath('data.dre.receita.receita_liquida', 450.0)
            ->assertJsonPath('data.dre.despesas_operacionais.total', 100.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_subgrupo.Aluguel', 100.0)
            ->assertJsonPath('data.dre.lucro_bruto', 450.0)
            ->assertJsonPath('data.dre.resultado_liquido', 350.0);
    }

    public function test_dre_competencia_ignora_lancamento_cancelado(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel cancelado',
            'valor' => 300,
            'status' => Financeiro::STATUS_CANCELADO,
            'data_vencimento' => now()->startOfMonth()->addDays(5),
            'data_competencia' => now()->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.despesas_operacionais.total', 0.0)
            ->assertJsonPath('data.dre.resultado_liquido', 0.0);
    }

    public function test_dre_competencia_separa_despesas_operacionais_em_fixas_e_variaveis(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Internet',
            'descricao' => 'Internet do mês',
            'valor' => 120,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(5),
            'data_competencia' => now()->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Internet',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => true,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Compra de peças',
            'descricao' => 'Peça avulsa',
            'valor' => 45,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(8),
            'data_competencia' => now()->startOfMonth()->addDays(8),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Peças',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.despesas_operacionais.total', 165.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.fixas', 120.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.variaveis', 45.0);

        $this->assertArrayNotHasKey('por_fixo_variavel', $response->json('data.dre.custos_diretos'));
    }

    public function test_dre_competencia_fixa_mensal_reaparecendo_em_mes_futuro_conta_como_fixa(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        // Título antigo, de competência de dois meses atrás, mas marcado como
        // fixo mensal — o mecanismo já existente de groupByCompetencia() faz
        // ele "reaparecer" no resultado do mês corrente (ver
        // FinanceiroReportService::groupByCompetencia(), linhas do bloco
        // $fixosMensais). A nova linha "Despesas fixas" deve refletir esse
        // mesmo comportamento, não silenciosamente ignorá-lo.
        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel antigo, fixo mensal',
            'valor' => 300,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(5),
            'data_competencia' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.despesas_operacionais.total', 300.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.fixas', 300.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.variaveis', 0.0);
    }

    public function test_dre_caixa_reconhece_apenas_o_que_foi_baixado_no_periodo(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $receitaAvulsa = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Receita avulsa',
            'descricao' => 'Receita avulsa de teste',
            'cliente_id' => $clienteId,
            'valor' => 200,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Outras Receitas',
            'subgrupo_dre' => 'Receita avulsa',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $receitaAvulsa->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now(),
            'valor_movimento' => 120,
        ]);

        $despesaPendente = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Energia',
            'descricao' => 'Conta de energia',
            'valor' => 80,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(10),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Energia',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre-caixa?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.modo', 'caixa')
            ->assertJsonPath('data.dre.outras_receitas.total', 120.0)
            ->assertJsonPath('data.dre.despesas_operacionais.total', 0.0);

        $this->assertDatabaseHas('financeiro', ['id' => $despesaPendente->id, 'status' => 'pendente']);
    }

    public function test_dre_caixa_separa_despesas_operacionais_em_fixas_e_variaveis(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $fixa = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Energia',
            'descricao' => 'Conta de energia',
            'valor' => 80,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Energia',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $fixa->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => now(),
            'valor_movimento' => 80,
        ]);

        $variavel = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Compra de peças',
            'descricao' => 'Peça avulsa',
            'valor' => 45,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Peças',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $variavel->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => now(),
            'valor_movimento' => 45,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre-caixa?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.dre.despesas_operacionais.total', 125.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.fixas', 80.0)
            ->assertJsonPath('data.dre.despesas_operacionais.por_fixo_variavel.variaveis', 45.0);
    }

    public function test_fluxo_de_caixa_separa_realizados_de_previstos(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Serviço de teste',
            'cliente_id' => $clienteId,
            'valor' => 300,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now(),
            'valor_movimento' => 300,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Internet',
            'descricao' => 'Conta de internet',
            'valor' => 90,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(3),
            'data_competencia' => now(),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Internet',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));

        $response->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 300.0)
            ->assertJsonPath('data.fluxo.saidas_realizadas', 0.0)
            ->assertJsonPath('data.fluxo.saidas_previstas', 90.0)
            ->assertJsonPath('data.fluxo.saldo_final', 300.0)
            ->assertJsonPath('data.fluxo.saldo_projetado', 210.0);

        $this->assertNotEmpty($response->json('data.fluxo.linhas_diarias'));
    }

    public function test_cancelar_um_lancamento_pago_estorna_seus_valores_do_fluxo_de_caixa_e_do_dre_caixa(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Serviço a estornar',
            'cliente_id' => $clienteId,
            'valor' => 300,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now(),
            'data_competencia' => now(),
            'data_pagamento' => now(),
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now(),
            'valor_movimento' => 300,
        ]);

        $this->postJson("/api/v1/financeiro/{$recebido->id}/cancelar")->assertOk();

        $fluxo = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $fluxo->assertOk()
            ->assertJsonPath('data.fluxo.entradas_realizadas', 0.0)
            ->assertJsonPath('data.fluxo.saldo_final', 0.0);

        $dreCaixa = $this->getJson('/api/v1/financeiro/relatorios/dre-caixa?mes=' . now()->format('Y-m'));
        $dreCaixa->assertOk()
            ->assertJsonPath('data.dre.outras_receitas.total', 0.0);

        $this->assertDatabaseMissing('financeiro_movimentos', [
            'financeiro_id' => $recebido->id,
        ]);
    }

    public function test_entrada_projetada_de_pagamento_imediato_e_igual_a_entrada_realizada_no_mesmo_dia(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $hoje = now()->startOfMonth()->addDays(5);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Pago em dinheiro',
            'cliente_id' => $clienteId,
            'valor' => 150,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => $hoje,
            'data_competencia' => $hoje,
            'data_pagamento' => $hoje,
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $hoje,
            'valor_movimento' => 150,
            'forma_pagamento' => 'dinheiro',
        ]);

        // Título pendente vencendo no mesmo dia: não deve contar em nenhuma
        // das duas colunas (só há FinanceiroMovimento real quando há baixa).
        Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Título pendente',
            'cliente_id' => $clienteId,
            'valor' => 999,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => $hoje,
            'data_competencia' => $hoje,
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $hoje->format('Y-m'));
        $response->assertOk();

        $linha = collect($response->json('data.fluxo.linhas_diarias'))->firstWhere('data', $hoje->toDateString());

        $this->assertEquals(150.0, $linha['entradas_realizadas']);
        $this->assertEquals(150.0, $linha['entrada_projetada']);
        $this->assertEquals(150.0, $linha['saldo_realizado']);
    }

    public function test_entrada_projetada_de_cartao_aparece_no_dia_do_repasse_nao_no_dia_da_venda(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $diaVenda = now()->startOfMonth()->addDays(2);
        $diaRepasse = now()->startOfMonth()->addDays(20);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Pago no crédito',
            'cliente_id' => $clienteId,
            'valor' => 500,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => $diaVenda,
            'data_competencia' => $diaVenda,
            'data_pagamento' => $diaVenda,
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $diaVenda,
            'valor_movimento' => 500,
            'forma_pagamento' => 'cartao_credito',
        ]);

        FinanceiroMovimentoCartao::create([
            'movimento_id' => $movimento->id,
            'modalidade' => 'credito',
            'parcelas' => 1,
            'valor_bruto' => 500,
            'valor_liquido' => 485,
            'valor_taxa' => 15,
            'data_prevista_recebimento' => $diaRepasse,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $diaVenda->format('Y-m'));
        $response->assertOk();

        $linhas = collect($response->json('data.fluxo.linhas_diarias'))->keyBy('data');

        $linhaVenda = $linhas[$diaVenda->toDateString()];
        $this->assertEquals(500.0, $linhaVenda['entradas_realizadas']);
        $this->assertEquals(0.0, $linhaVenda['entrada_projetada']);

        $linhaRepasse = $linhas[$diaRepasse->toDateString()];
        $this->assertEquals(0.0, $linhaRepasse['entradas_realizadas']);
        $this->assertEquals(500.0, $linhaRepasse['entrada_projetada']);
    }

    public function test_entrada_projetada_cruza_para_o_mes_seguinte_quando_repasse_cai_no_proximo_mes(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $diaVenda = now()->startOfMonth()->addDays(25);
        $diaRepasse = $diaVenda->copy()->addMonthNoOverflow();

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Pago no crédito parcelado',
            'cliente_id' => $clienteId,
            'valor' => 400,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => $diaVenda,
            'data_competencia' => $diaVenda,
            'data_pagamento' => $diaVenda,
            'grupo_dre' => 'Receita Operacional',
            'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $diaVenda,
            'valor_movimento' => 400,
            'forma_pagamento' => 'cartao_credito',
        ]);

        FinanceiroMovimentoCartao::create([
            'movimento_id' => $movimento->id,
            'modalidade' => 'credito',
            'parcelas' => 1,
            'valor_bruto' => 400,
            'valor_liquido' => 388,
            'valor_taxa' => 12,
            'data_prevista_recebimento' => $diaRepasse,
        ]);

        $relatorioMesVenda = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $diaVenda->format('Y-m'));
        $relatorioMesVenda->assertOk();
        $linhasMesVenda = collect($relatorioMesVenda->json('data.fluxo.linhas_diarias'))->keyBy('data');
        $this->assertEquals(400.0, $linhasMesVenda[$diaVenda->toDateString()]['entradas_realizadas']);
        $this->assertEquals(0.0, $linhasMesVenda[$diaVenda->toDateString()]['entrada_projetada']);

        // O repasse cai no mês seguinte: o relatório desse outro mês precisa
        // enxergar a entrada projetada mesmo a venda sendo de outro mês —
        // prova de que a query de cartão filtra pelo dia de pouso, não por
        // data_movimento (que é do mês anterior).
        $relatorioMesRepasse = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $diaRepasse->format('Y-m'));
        $relatorioMesRepasse->assertOk();
        $linhasMesRepasse = collect($relatorioMesRepasse->json('data.fluxo.linhas_diarias'))->keyBy('data');
        $this->assertEquals(400.0, $linhasMesRepasse[$diaRepasse->toDateString()]['entrada_projetada']);
        $this->assertEquals(0.0, $linhasMesRepasse[$diaRepasse->toDateString()]['entradas_realizadas']);
    }

    public function test_entrada_projetada_soma_multiplas_vendas_cartao_com_pouso_no_mesmo_dia(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $diaRepasse = now()->startOfMonth()->addDays(20);

        foreach ([['dia' => 1, 'valor' => 100.0], ['dia' => 5, 'valor' => 250.0]] as $venda) {
            $diaVenda = now()->startOfMonth()->addDays($venda['dia']);

            $recebido = Financeiro::create([
                'tipo' => Financeiro::TIPO_RECEBER,
                'avulso' => true,
                'categoria' => 'Serviço',
                'descricao' => 'Venda no cartão ' . $venda['dia'],
                'cliente_id' => $clienteId,
                'valor' => $venda['valor'],
                'status' => Financeiro::STATUS_PAGO,
                'data_vencimento' => $diaVenda,
                'data_competencia' => $diaVenda,
                'data_pagamento' => $diaVenda,
                'grupo_dre' => 'Receita Operacional',
                'subgrupo_dre' => 'Serviços e peças de OS',
                'impacta_dre' => true,
                'impacta_fluxo_caixa' => true,
            ]);

            $movimento = FinanceiroMovimento::create([
                'financeiro_id' => $recebido->id,
                'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
                'data_movimento' => $diaVenda,
                'valor_movimento' => $venda['valor'],
                'forma_pagamento' => 'cartao_credito',
            ]);

            FinanceiroMovimentoCartao::create([
                'movimento_id' => $movimento->id,
                'modalidade' => 'credito',
                'parcelas' => 1,
                'valor_bruto' => $venda['valor'],
                'data_prevista_recebimento' => $diaRepasse,
            ]);
        }

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $diaRepasse->format('Y-m'));
        $response->assertOk();

        $linha = collect($response->json('data.fluxo.linhas_diarias'))->firstWhere('data', $diaRepasse->toDateString());
        $this->assertEquals(350.0, $linha['entrada_projetada']);
    }

    public function test_taxa_de_cartao_nao_aparece_na_entrada_projetada(): void
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
            'descricao' => 'Serviço pago no crédito',
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

        $dataRepasse = now()->copy()->addDays(30);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $dataRepasse->format('Y-m'));
        $response->assertOk();

        $linha = collect($response->json('data.fluxo.linhas_diarias'))->firstWhere('data', $dataRepasse->toDateString());

        // Bruto (100.00), não líquido (97.50) — a taxa é uma saída separada,
        // sem financeiro_movimentos_cartao, e não entra nesta coluna porque
        // a coluna só considera tipo=receber.
        $this->assertEquals(100.0, $linha['entrada_projetada']);

        // No detalhamento do DIA DA VENDA, a taxa não tem seu próprio
        // financeiro_movimentos_cartao, mas sua cobrança acompanha a mesma
        // data prevista de caixa da venda que a gerou — não é "Imediato".
        $relatorioMesVenda = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $linhaVenda = collect($relatorioMesVenda->json('data.fluxo.linhas_diarias'))->firstWhere('data', now()->toDateString());
        $movimentosVenda = collect($linhaVenda['detalhes']['movimentos'])->keyBy('categoria');

        $this->assertEquals($dataRepasse->toDateString(), $movimentosVenda['Serviço']['data_prevista_caixa']);
        $this->assertEquals($dataRepasse->toDateString(), $movimentosVenda['Taxa de cartão']['data_prevista_caixa']);

        // O bloco "cartao" (operadora, modalidade, parcelas, taxa, prazo)
        // aparece tanto na venda quanto na taxa — a taxa não tem seu próprio
        // financeiro_movimentos_cartao, então ela herda o do movimento de
        // origem (mesma lógica de data_prevista_caixa acima).
        $cartaoVenda = $movimentosVenda['Serviço']['cartao'];
        $this->assertEquals('Cielo', $cartaoVenda['operadora']);
        $this->assertNull($cartaoVenda['bandeira']);
        $this->assertEquals('credito', $cartaoVenda['modalidade']);
        $this->assertEquals(1, $cartaoVenda['parcelas']);
        $this->assertEquals(2.5, $cartaoVenda['taxa_percentual']);
        $this->assertEquals(30, $cartaoVenda['prazo_recebimento_dias']);

        $cartaoTaxa = $movimentosVenda['Taxa de cartão']['cartao'];
        $this->assertEquals('Cielo', $cartaoTaxa['operadora']);
        $this->assertEquals(1, $cartaoTaxa['parcelas']);
    }

    public function test_detalhes_do_dia_lista_movimentos_e_previstos_separadamente(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord(['nome_razao' => 'Maria Cliente']);
        $fornecedorId = $this->createSupplierRecord(['nome_fantasia' => 'Fornecedor Peças']);
        Sanctum::actingAs($admin, ['*']);

        $hoje = now()->startOfMonth()->addDays(10);
        $diaAnterior = $hoje->copy()->subDays(5);

        // (a) venda em dinheiro paga hoje.
        $vendaDinheiro = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER, 'avulso' => true, 'categoria' => 'Serviço',
            'descricao' => 'Venda à vista', 'cliente_id' => $clienteId, 'valor' => 80,
            'status' => Financeiro::STATUS_PAGO, 'data_vencimento' => $hoje, 'data_competencia' => $hoje,
            'data_pagamento' => $hoje, 'grupo_dre' => 'Receita Operacional', 'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true, 'impacta_fluxo_caixa' => true,
        ]);
        $movDinheiro = FinanceiroMovimento::create([
            'financeiro_id' => $vendaDinheiro->id, 'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $hoje, 'valor_movimento' => 80, 'forma_pagamento' => 'dinheiro',
        ]);

        // (b) venda em cartão paga hoje, com repasse em outro dia.
        $vendaCartaoHoje = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER, 'avulso' => true, 'categoria' => 'Serviço',
            'descricao' => 'Venda no crédito', 'cliente_id' => $clienteId, 'valor' => 200,
            'status' => Financeiro::STATUS_PAGO, 'data_vencimento' => $hoje, 'data_competencia' => $hoje,
            'data_pagamento' => $hoje, 'grupo_dre' => 'Receita Operacional', 'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true, 'impacta_fluxo_caixa' => true,
        ]);
        $movCartaoHoje = FinanceiroMovimento::create([
            'financeiro_id' => $vendaCartaoHoje->id, 'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $hoje, 'valor_movimento' => 200, 'forma_pagamento' => 'cartao_credito',
        ]);
        FinanceiroMovimentoCartao::create([
            'movimento_id' => $movCartaoHoje->id, 'modalidade' => 'credito', 'parcelas' => 1,
            'valor_bruto' => 200, 'valor_liquido' => 194, 'valor_taxa' => 6,
            'data_prevista_recebimento' => $hoje->copy()->addDays(15),
        ]);

        // (c) venda em cartão de dias atrás, cujo repasse cai hoje.
        $vendaCartaoAntiga = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER, 'avulso' => true, 'categoria' => 'Peças',
            'descricao' => 'Venda antiga no débito', 'cliente_id' => $clienteId, 'valor' => 90,
            'status' => Financeiro::STATUS_PAGO, 'data_vencimento' => $diaAnterior, 'data_competencia' => $diaAnterior,
            'data_pagamento' => $diaAnterior, 'grupo_dre' => 'Receita Operacional', 'subgrupo_dre' => 'Serviços e peças de OS',
            'impacta_dre' => true, 'impacta_fluxo_caixa' => true,
        ]);
        $movCartaoAntigo = FinanceiroMovimento::create([
            'financeiro_id' => $vendaCartaoAntiga->id, 'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $diaAnterior, 'valor_movimento' => 90, 'forma_pagamento' => 'cartao_debito',
        ]);
        FinanceiroMovimentoCartao::create([
            'movimento_id' => $movCartaoAntigo->id, 'modalidade' => 'debito', 'parcelas' => 1,
            'valor_bruto' => 90, 'valor_liquido' => 88, 'valor_taxa' => 2,
            'data_prevista_recebimento' => $hoje,
        ]);

        // (d) pagamento a fornecedor hoje.
        $pagamentoFornecedor = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR, 'avulso' => true, 'categoria' => 'Compra de peças',
            'descricao' => 'Compra de peças', 'fornecedor_id' => $fornecedorId, 'valor' => 50,
            'status' => Financeiro::STATUS_PAGO, 'data_vencimento' => $hoje, 'data_competencia' => $hoje,
            'data_pagamento' => $hoje, 'grupo_dre' => 'Custo Direto (OS)', 'subgrupo_dre' => 'Compra emergencial de peças',
            'impacta_dre' => false, 'impacta_fluxo_caixa' => true,
        ]);
        $movFornecedor = FinanceiroMovimento::create([
            'financeiro_id' => $pagamentoFornecedor->id, 'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $hoje, 'valor_movimento' => 50, 'forma_pagamento' => 'pix',
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $hoje->format('Y-m'));
        $response->assertOk();

        $linhas = collect($response->json('data.fluxo.linhas_diarias'))->keyBy('data');
        $detalhesHoje = $linhas[$hoje->toDateString()]['detalhes'];

        $movimentos = collect($detalhesHoje['movimentos'])->keyBy('movimento_id');
        $this->assertCount(3, $movimentos);

        $this->assertEquals('Maria Cliente', $movimentos[$movDinheiro->id]['contraparte']);
        $this->assertNull($movimentos[$movDinheiro->id]['data_prevista_caixa']);

        $this->assertEquals('Maria Cliente', $movimentos[$movCartaoHoje->id]['contraparte']);
        $this->assertEquals($hoje->copy()->addDays(15)->toDateString(), $movimentos[$movCartaoHoje->id]['data_prevista_caixa']);

        $this->assertEquals('pagar', $movimentos[$movFornecedor->id]['tipo']);
        $this->assertEquals('Fornecedor Peças', $movimentos[$movFornecedor->id]['contraparte']);

        $previstos = collect($detalhesHoje['previstos_para_hoje']);
        $this->assertCount(1, $previstos);
        $this->assertEquals(90.0, $previstos->first()['valor']);
        $this->assertEquals('Maria Cliente', $previstos->first()['contraparte']);
        $this->assertEquals($diaAnterior->toDateString(), $previstos->first()['data_venda']);
    }

    public function test_saldo_inicial_soma_todo_o_historico_nao_so_o_dia_anterior(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $inicioMes = now()->startOfMonth();

        foreach ([$inicioMes->copy()->subDays(10), $inicioMes->copy()->subDay()] as $data) {
            $f = Financeiro::create([
                'tipo' => Financeiro::TIPO_RECEBER,
                'avulso' => true,
                'categoria' => 'Serviço',
                'descricao' => 'Histórico antigo',
                'cliente_id' => $clienteId,
                'valor' => 100,
                'status' => Financeiro::STATUS_PAGO,
                'data_vencimento' => $data,
                'data_competencia' => $data,
                'data_pagamento' => $data,
                'impacta_dre' => true,
                'impacta_fluxo_caixa' => true,
            ]);
            FinanceiroMovimento::create([
                'financeiro_id' => $f->id,
                'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
                'data_movimento' => $data,
                'valor_movimento' => 100,
            ]);
        }

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $inicioMes->format('Y-m'));
        $response->assertOk();

        // Antes da correção, isso retornava 100 (só o dia imediatamente
        // anterior ao início do mês, por causa de `$end ??= $start` em
        // netMovimentos()) — o correto é somar os dois movimentos: 200.
        $this->assertEquals(200.0, $response->json('data.fluxo.saldo_inicial'));
        $this->assertEquals(200.0, $response->json('data.fluxo.saldo_liquido_inicial'));
    }

    public function test_saldo_liquido_reconhece_o_valor_liquido_no_dia_do_pouso_nao_no_dia_da_venda(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Rede',
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
            'taxa_percentual' => 3.0,
            'taxa_fixa' => 0.00,
            'prazo_recebimento_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $store = $this->postJson('/api/v1/financeiro', [
            'tipo' => 'receber',
            'categoria' => 'Serviço',
            'descricao' => 'Venda no crédito',
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

        $dataRepasse = now()->copy()->addDays(30);

        // No mês da venda: entradas_realizadas/saldo_realizado continuam
        // brutos (inalterados), mas saldo_liquido NÃO se move — nada pousou
        // de verdade ainda. Se a taxa (saída separada, mesmo dia da venda)
        // fosse contada aqui também, o saldo_liquido apareceria negativo em
        // vez de zero — é exatamente esse duplo desconto que a exclusão da
        // "Taxa de cartão" em netCashDeltaByLandingDay() evita.
        $relatorioMesVenda = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $linhaVenda = collect($relatorioMesVenda->json('data.fluxo.linhas_diarias'))->firstWhere('data', now()->toDateString());
        $this->assertEquals(100.0, $linhaVenda['entradas_realizadas']);
        $this->assertEquals(0.0, $linhaVenda['saldo_liquido']);

        // No mês do repasse: saldo_liquido sobe pelo valor LÍQUIDO (97,00 —
        // 100 menos 3% de taxa), não pelo bruto (100, que é o que
        // "entrada_projetada" mostra).
        $relatorioMesRepasse = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . $dataRepasse->format('Y-m'));
        $linhaRepasse = collect($relatorioMesRepasse->json('data.fluxo.linhas_diarias'))->firstWhere('data', $dataRepasse->toDateString());
        $this->assertEquals(100.0, $linhaRepasse['entrada_projetada']);
        $this->assertEquals(97.0, $linhaRepasse['saldo_liquido']);
        $this->assertEquals(0.0, $relatorioMesRepasse->json('data.fluxo.saldo_liquido_inicial'));
    }

    public function test_saldo_liquido_inicial_acumula_venda_em_cartao_ja_pousada_no_periodo_anterior(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        Sanctum::actingAs($admin, ['*']);

        $inicioMes = now()->startOfMonth();
        $diaVenda = $inicioMes->copy()->subDays(40);
        $diaPouso = $inicioMes->copy()->subDays(10);

        $recebido = Financeiro::create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço',
            'descricao' => 'Venda antiga no débito',
            'cliente_id' => $clienteId,
            'valor' => 100,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => $diaVenda,
            'data_competencia' => $diaVenda,
            'data_pagamento' => $diaVenda,
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);
        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $recebido->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => $diaVenda,
            'valor_movimento' => 100,
            'forma_pagamento' => 'cartao_debito',
        ]);
        FinanceiroMovimentoCartao::create([
            'movimento_id' => $movimento->id,
            'modalidade' => 'debito',
            'parcelas' => 1,
            'valor_bruto' => 100,
            'valor_liquido' => 98,
            'valor_taxa' => 2,
            'data_prevista_recebimento' => $diaPouso,
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/fluxo-caixa?mes=' . now()->format('Y-m'));
        $response->assertOk();

        // saldo_liquido_inicial reflete o valor LÍQUIDO (98) dessa venda,
        // já pousado antes do período — mesmo a venda em si (data_movimento)
        // sendo de 30 dias antes do pouso.
        $this->assertEquals(98.0, $response->json('data.fluxo.saldo_liquido_inicial'));

        // saldo_inicial (existente, por data_movimento) soma o bruto (100) —
        // as duas colunas divergem de propósito aqui.
        $this->assertEquals(100.0, $response->json('data.fluxo.saldo_inicial'));
    }

    public function test_user_without_permission_cannot_view_reports(): void
    {
        $attendant = $this->createUserRecord(['grupo_id' => 3, 'perfil' => 'atendente']);
        Sanctum::actingAs($attendant, ['*']);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre');

        $response->assertStatus(403);
    }

    /**
     * Monta um cenario fechado de custeio variavel:
     *
     *   Receita              10.000
     *   (-) CMV (pecas)       3.000
     *   (-) Comissoes           500
     *   (-) Desp. variaveis     500
     *   = MC                  6.000  (60%)
     *   (-) Custos fixos      3.000
     *   = Resultado           3.000
     *
     * Numeros redondos de proposito: um erro de sinal ou de fonte aparece na
     * hora, em vez de se esconder num decimal.
     */
    private function seedCenarioGerencial(): void
    {
        $clienteId = $this->createClientRecord();

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $this->createEquipmentRecord($clienteId),
            'status' => 'entregue_reparado_pago',
            'valor_total' => 10000,
            'desconto' => 0,
            'valor_final' => 10000,
            'data_entrega' => now()->startOfMonth()->addDays(3),
        ]);

        DB::table('os_margem')->insert([
            'os_id' => $orderId,
            'receita_liquida' => 10000,
            'custo_pecas' => 3000,
            'custo_comissao' => 500,
            'custo_taxa_recebimento' => 0,
            'custo_imposto' => 0,
            'margem_contribuicao' => 6500,
            'percentual_margem' => 65,
            'calculado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel do mês',
            'valor' => 3000,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(5),
            'data_competencia' => now()->startOfMonth()->addDays(5),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => true,
        ]);

        Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Impostos e taxas',
            'descricao' => 'Taxas sobre venda',
            'valor' => 500,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->startOfMonth()->addDays(6),
            'data_competencia' => now()->startOfMonth()->addDays(6),
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Taxas e impostos',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);
    }

    /**
     * A linha que faltava no DRE: separa os custos pelo COMPORTAMENTO (variam
     * ou nao com o volume) e revela quanto sobra de cada real vendido para
     * pagar o fixo. O lucro bruto legado nao responde isso — nem sequer
     * enxerga o custo das pecas.
     */
    public function test_dre_gerencial_calcula_margem_de_contribuicao_com_cmv_de_estoque(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $this->seedCenarioGerencial();

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'))->assertOk();

        $response
            ->assertJsonPath('data.dre.gerencial.disponivel', true)
            ->assertJsonPath('data.dre.gerencial.custos_variaveis.cmv_pecas', 3000.0)
            ->assertJsonPath('data.dre.gerencial.custos_variaveis.comissoes', 500.0)
            ->assertJsonPath('data.dre.gerencial.custos_variaveis.despesas_variaveis', 500.0)
            ->assertJsonPath('data.dre.gerencial.custos_variaveis.total', 4000.0)
            ->assertJsonPath('data.dre.gerencial.margem_contribuicao', 6000.0)
            ->assertJsonPath('data.dre.gerencial.indice_contribuicao_percentual', 60.0)
            ->assertJsonPath('data.dre.gerencial.custos_fixos', 3000.0)
            ->assertJsonPath('data.dre.gerencial.resultado_operacional', 3000.0);

        // O lucro bruto legado continua ignorando o CMV — por isso ele nao
        // pode ser lido como "quanto sobrou".
        $response->assertJsonPath('data.dre.lucro_bruto', 10000.0);
    }

    /**
     * Ponto de equilibrio, margem de seguranca e GAO derivam da MC. Respondem
     * "quanto preciso faturar para nao ter prejuizo" e "quanto posso cair
     * antes de entrar no vermelho".
     */
    public function test_dre_gerencial_calcula_ponto_de_equilibrio_e_alavancagem(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $this->seedCenarioGerencial();

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'))->assertOk();

        // PE = custo fixo / indice de contribuicao = 3.000 / 0,60 = 5.000
        $response
            ->assertJsonPath('data.dre.gerencial.analise_cvp.ponto_equilibrio_receita', 5000.0)
            ->assertJsonPath('data.dre.gerencial.analise_cvp.ponto_equilibrio_atingido', true)
            // Faturou o dobro do necessario para empatar.
            ->assertJsonPath('data.dre.gerencial.analise_cvp.percentual_do_equilibrio', 200.0)
            // Pode perder metade do faturamento antes de dar prejuizo.
            ->assertJsonPath('data.dre.gerencial.analise_cvp.margem_seguranca_valor', 5000.0)
            ->assertJsonPath('data.dre.gerencial.analise_cvp.margem_seguranca_percentual', 50.0)
            // GAO 2: cada 1% de variacao na receita move 2% o resultado.
            ->assertJsonPath('data.dre.gerencial.analise_cvp.grau_alavancagem_operacional', 2.0);
    }

    /**
     * Sem indice de contribuicao positivo nao existe ponto de equilibrio: se
     * cada venda ja nasce sem cobrir o proprio custo variavel, vender mais
     * afunda mais e nenhum volume zera o resultado. Devolver um numero aqui
     * seria pior que devolver nada.
     */
    public function test_dre_gerencial_nao_inventa_ponto_de_equilibrio_com_margem_negativa(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $clienteId = $this->createClientRecord();

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $this->createEquipmentRecord($clienteId),
            'status' => 'entregue_reparado_pago',
            'valor_total' => 1000,
            'desconto' => 0,
            'valor_final' => 1000,
            'data_entrega' => now()->startOfMonth()->addDays(3),
        ]);

        // Peca custou mais que o preco cobrado: MC negativa.
        DB::table('os_margem')->insert([
            'os_id' => $orderId,
            'receita_liquida' => 1000,
            'custo_pecas' => 1400,
            'custo_comissao' => 0,
            'custo_taxa_recebimento' => 0,
            'custo_imposto' => 0,
            'margem_contribuicao' => -400,
            'percentual_margem' => -40,
            'calculado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre?mes=' . now()->format('Y-m'))->assertOk();

        $this->assertEquals(-400.0, $response->json('data.dre.gerencial.margem_contribuicao'));
        $this->assertNull($response->json('data.dre.gerencial.analise_cvp.ponto_equilibrio_receita'));
        $this->assertNull($response->json('data.dre.gerencial.analise_cvp.margem_seguranca_valor'));
        $this->assertNull($response->json('data.dre.gerencial.analise_cvp.grau_alavancagem_operacional'));
    }

    /**
     * MC e um conceito de CONFRONTACAO: o custo da peca pertence ao mes da
     * entrega, nao ao mes em que o cliente pagou. No regime de caixa o bloco
     * sai marcado como indisponivel, com o motivo — em vez de exibir um numero
     * que nao corresponde a periodo nenhum.
     */
    public function test_dre_caixa_nao_apresenta_margem_de_contribuicao(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $this->seedCenarioGerencial();

        $response = $this->getJson('/api/v1/financeiro/relatorios/dre-caixa?mes=' . now()->format('Y-m'))->assertOk();

        $response->assertJsonPath('data.dre.gerencial.disponivel', false);
        $this->assertNotEmpty($response->json('data.dre.gerencial.motivo'));
        $this->assertNull($response->json('data.dre.gerencial.margem_contribuicao'));
    }
}
