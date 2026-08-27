<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    /**
     * `reparo_concluido` nao esta no catalogo minimo de seedOrderCatalog(),
     * mas e o momento em que o tecnico apontaria as horas na operacao real:
     * pertence a OrderStatus::DEADLINE_FREEZE_CODES, entao entrar nele carimba
     * data_conclusao. Semeado localmente para exercitar esse caminho sem
     * inflar o catalogo compartilhado por toda a suite.
     */
    private function seedStatusReparoConcluido(): void
    {
        DB::table('os_status')->insert([
            'codigo' => 'reparo_concluido',
            'nome' => 'Reparo Concluído',
            'grupo_macro' => 'execucao',
            'icone' => null,
            'cor' => 'success',
            'ordem_fluxo' => 25,
            'status_final' => 0,
            'status_pausa' => 0,
            'gera_evento_crm' => 1,
            'estado_fluxo_padrao' => 'em_execucao',
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // activeCodes() e memoizado em cache; sem limpar, a validacao do
        // FormRequest continuaria recusando o codigo recem-inserido.
        Cache::flush();
    }

    /**
     * @return array{operadora_id: int, taxa_id: int}
     */
    private function seedCardRate(): array
    {
        $operadoraId = (int) DB::table('financeiro_cartao_operadoras')->insertGetId([
            'nome' => 'Operadora Teste',
            'descricao' => null,
            'ordem_exibicao' => 1,
            'prazo_padrao_dias' => 30,
            'ativo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $taxaId = (int) DB::table('financeiro_cartao_taxas')->insertGetId([
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
        ]);

        return ['operadora_id' => $operadoraId, 'taxa_id' => $taxaId];
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

    /**
     * Taxa de recebimento e imposto sao custos VARIAVEIS: variam com a venda e
     * portanto entram na margem de contribuicao. Sem eles a margem reportada
     * fica inflada e todo desconto concedido em cima dela parte de um numero
     * que nao existe.
     *
     * A taxa usada e a REAL — o titulo que a baixa gerou (origem_tipo
     * 'os_recebimento_cartao') —, nao uma reestimativa por percentual, para
     * que piso/teto da operadora sejam respeitados.
     */
    public function test_margem_desconta_taxa_de_recebimento_real_e_imposto_configurado(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        // Regime com imposto proporcional ao faturamento: no MEI (padrão) o
        // DAS é fixo e não desconta da margem — ver
        // test_regime_mei_nao_desconta_imposto_da_margem().
        DB::table('configuracoes')->insert([
            ['chave' => 'regime_tributario', 'valor' => 'simples'],
            ['chave' => 'margem_imposto_percentual', 'valor' => '6'],
        ]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 1000,
            'desconto' => 0,
            'valor_final' => 1000,
        ]);

        $this->encerrarComoPago($orderId, 1000.00);

        // Taxa efetivamente cobrada pela operadora, como a baixa registraria.
        DB::table('financeiro')->insert([
            'os_id' => $orderId,
            'tipo' => 'pagar',
            'categoria' => 'Taxa de cartão',
            'descricao' => 'Taxa operadora - OS teste',
            'valor' => 34.90,
            'status' => 'pago',
            'origem_tipo' => 'os_recebimento_cartao',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular')->assertOk();

        // receita 1000; taxa real 34,90; imposto 6% = 60
        // MC = 1000 - 34,90 - 60 = 905,10 -> 90,51%
        $this->assertJsonNumero($response, 'data.margem.custo_taxa_recebimento', 34.90);
        $this->assertJsonNumero($response, 'data.margem.custo_imposto', 60);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 905.10);
        $this->assertJsonNumero($response, 'data.margem.percentual_margem', 90.51);
    }

    /**
     * Ponta a ponta pela baixa real em cartao: prova ao mesmo tempo que
     *
     *  1. a margem desconta a taxa que a operadora efetivamente cobrou; e
     *  2. a despesa de taxa nasce classificada no DRE.
     *
     * O item 2 era um defeito: OrderClosureService::registerCardFeeExpense()
     * criava o titulo com `grupo_dre` nulo e o DRE agrupa POR grupo, entao a
     * taxa saia do caixa e nunca aparecia no resultado, apesar de
     * impacta_dre=true.
     */
    public function test_baixa_em_cartao_desconta_taxa_da_margem_e_classifica_despesa_no_dre(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $card = $this->seedCardRate();

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 200,
            'desconto' => 0,
            'valor_final' => 200,
            'data_entrega' => now(),
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/closure", [
            'encerrar_como' => 'entregue_reparado_pago',
            'data_entrega' => now()->toDateString(),
            'recebimentos' => [[
                'valor' => 200.00,
                'forma_pagamento' => 'cartao_credito',
                'operadora_id' => $card['operadora_id'],
                'modalidade' => 'credito',
                'parcelas' => 1,
            ]],
        ])->assertOk();

        // 5% de 200 + R$ 0,50 fixos = R$ 10,50.
        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId)->assertOk();

        $this->assertJsonNumero($response, 'data.margem.custo_taxa_recebimento', 10.50);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 189.50);

        $this->assertDatabaseHas('financeiro', [
            'os_id' => $orderId,
            'origem_tipo' => 'os_recebimento_cartao',
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Taxas e impostos',
            'dre_fixo_mensal' => false,
        ]);
    }

    /**
     * No MEI o DAS e valor FIXO mensal: nao muda se a assistencia fizer 10 ou
     * 100 OS. Descontar um valor fixo de cada venda subestimaria a margem
     * unitaria e — pior — tiraria essa despesa do ponto de equilibrio, que e
     * exatamente onde ela precisa estar.
     *
     * Por isso o regime tem precedencia sobre o percentual: mesmo com aliquota
     * configurada, o MEI devolve 0.
     */
    public function test_regime_mei_nao_desconta_imposto_da_margem(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        DB::table('configuracoes')->insert([
            ['chave' => 'regime_tributario', 'valor' => 'mei'],
            // Percentual presente de propósito: o regime tem de vencer.
            ['chave' => 'margem_imposto_percentual', 'valor' => '6'],
        ]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_final' => 1000,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular')->assertOk();

        $this->assertJsonNumero($response, 'data.margem.custo_imposto', 0);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 1000);
    }

    /**
     * Trocar de MEI para Simples e mudanca de configuracao, nao de codigo: a
     * assistencia cresce e o imposto passa a ser proporcional ao faturamento.
     */
    public function test_regime_simples_desconta_imposto_da_margem(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        DB::table('configuracoes')->insert([
            ['chave' => 'regime_tributario', 'valor' => 'simples'],
            ['chave' => 'margem_imposto_percentual', 'valor' => '6'],
        ]);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_final' => 1000,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular')->assertOk();

        $this->assertJsonNumero($response, 'data.margem.custo_imposto', 60);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 940);
    }

    /**
     * Sem configuracao nenhuma o sistema assume MEI — a realidade de quem esta
     * usando. Um padrao que descontasse imposto inventaria uma despesa.
     */
    public function test_padrao_sem_configuracao_e_mei(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        DB::table('configuracoes')->insert(['chave' => 'margem_imposto_percentual', 'valor' => '6']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_final' => 500,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular')->assertOk();

        $this->assertJsonNumero($response, 'data.margem.custo_imposto', 0);
    }

    /**
     * Uma taxa estornada (titulo cancelado) nao pode continuar consumindo a
     * margem — senao um cancelamento de lancamento deixaria a OS
     * permanentemente mais pobre do que foi.
     */
    public function test_taxa_cancelada_nao_consome_margem(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_final' => 500,
        ]);

        DB::table('financeiro')->insert([
            'os_id' => $orderId,
            'tipo' => 'pagar',
            'categoria' => 'Taxa de cartão',
            'descricao' => 'Taxa estornada',
            'valor' => 25.00,
            'status' => 'cancelado',
            'origem_tipo' => 'os_recebimento_cartao',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        $response = $this->postJson('/api/v1/financeiro/margem/' . $orderId . '/recalcular')->assertOk();

        $this->assertJsonNumero($response, 'data.margem.custo_taxa_recebimento', 0);
        $this->assertJsonNumero($response, 'data.margem.margem_contribuicao', 500);
    }

    /**
     * O indicador do periodo e o INDICE DE CONTRIBUICAO (MC total / receita
     * total), nao a media aritmetica dos percentuais das OS.
     *
     * Num mix heterogeneo — o caso normal de uma assistencia tecnica — a media
     * simples mente: aqui, 90% e 20% dariam "55%", enquanto a realidade
     * economica do mes e 24,38%.
     */
    public function test_margem_media_do_periodo_e_ponderada_pela_receita(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $pecaId = $this->createPecaRecord(['preco_custo' => 960.00, 'preco_venda' => 1200.00]);

        // OS pequena, sem peca: 100% de margem.
        $osServico = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 80,
            'valor_final' => 80,
            'data_entrega' => now(),
        ]);
        $this->encerrarComoPago($osServico, 80.00);

        // OS grande, peca cara: 20% de margem.
        $osReparo = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 1200,
            'valor_final' => 1200,
            'data_entrega' => now(),
        ]);
        $this->createMovimentacaoRecord([
            'peca_id' => $pecaId,
            'os_id' => $osReparo,
            'tipo' => 'saida',
            'quantidade' => 1,
            'responsavel_id' => $admin->id,
        ]);
        $this->encerrarComoPago($osReparo, 1200.00);

        $response = $this->getJson('/api/v1/financeiro/margem?mes=' . now()->format('Y-m'))->assertOk();

        // MC total = 80 + 240 = 320; receita total = 1280 -> 25,00%
        // A media simples dos percentuais daria (100 + 20) / 2 = 60%.
        $this->assertJsonNumero($response, 'data.margem.receita_total', 1280);
        $this->assertJsonNumero($response, 'data.margem.margem_total', 320);
        $this->assertJsonNumero($response, 'data.margem.margem_media_percentual', 25.00);
    }

    /**
     * Quando a bancada e o recurso restrito, o criterio de priorizacao e a MC
     * por HORA, nao a MC por OS: a OS de maior margem absoluta pode ser a pior
     * escolha se travar o tecnico o dia inteiro.
     */
    public function test_margem_por_hora_usa_apontamento_do_tecnico(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 300,
            'valor_final' => 300,
            'data_entrega' => now(),
        ]);

        $this->seedStatusReparoConcluido();

        // Tecnico aponta as horas ao concluir o reparo.
        $this->patchJson("/api/v1/orders/{$orderId}/status", [
            'status' => 'reparo_concluido',
            'tempo_tecnico_horas' => 2.5,
        ])->assertOk();

        $this->encerrarComoPago($orderId, 300.00);

        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId)->assertOk();

        $this->assertJsonNumero($response, 'data.margem.tempo_tecnico_horas', 2.5);
        $this->assertJsonNumero($response, 'data.margem.margem_por_hora', 120);
    }

    /**
     * OS sem apontamento nao pode inventar uma margem por hora — ficaria com
     * um numero fabricado disputando o ranking com apontamentos reais. Fica
     * nula e o relatorio a contabiliza como "sem apontamento".
     */
    public function test_os_sem_apontamento_fica_fora_da_margem_por_hora(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 300,
            'valor_final' => 300,
            'data_entrega' => now(),
        ]);

        $this->encerrarComoPago($orderId, 300.00);

        $detalhe = $this->getJson('/api/v1/financeiro/margem/' . $orderId)->assertOk();
        $this->assertNull($detalhe->json('data.margem.margem_por_hora'));

        $periodo = $this->getJson('/api/v1/financeiro/margem?mes=' . now()->format('Y-m'))->assertOk();
        $this->assertSame(1, $periodo->json('data.margem.horas.os_sem_apontamento'));
        $this->assertSame(0, $periodo->json('data.margem.horas.os_com_apontamento'));
        $this->assertSame([], $periodo->json('data.margem.melhores_por_hora'));
    }

    /**
     * A margem por hora do tecnico so pode confrontar a margem das OS que TEM
     * apontamento com as horas dessas mesmas OS.
     *
     * Dividir a margem do periodo inteiro pelas horas de uma parte dele
     * atribuiria a margem das OS sem apontamento ao tempo das que tem — e
     * quanto pior a cobertura do apontamento, maior a distorcao.
     */
    public function test_margem_por_hora_do_tecnico_ignora_os_sem_apontamento(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $tecnico = $this->createUserRecord(['grupo_id' => 2, 'perfil' => 'tecnico']);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $this->seedStatusReparoConcluido();

        // OS com apontamento: 2h, margem 200 -> R$ 100/h.
        $comHoras = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => $tecnico->id,
            'status' => 'aguardando_reparo',
            'valor_total' => 200,
            'valor_final' => 200,
            'data_entrega' => now(),
        ]);
        $this->patchJson("/api/v1/orders/{$comHoras}/status", [
            'status' => 'reparo_concluido',
            'tempo_tecnico_horas' => 2,
        ])->assertOk();
        $this->encerrarComoPago($comHoras, 200.00);

        // OS sem apontamento, margem alta: não pode ser creditada às 2h acima.
        $semHoras = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'tecnico_id' => $tecnico->id,
            'status' => 'aguardando_reparo',
            'valor_total' => 1000,
            'valor_final' => 1000,
            'data_entrega' => now(),
        ]);
        $this->encerrarComoPago($semHoras, 1000.00);

        $response = $this->getJson('/api/v1/financeiro/margem?mes=' . now()->format('Y-m'))->assertOk();

        $linha = collect($response->json('data.margem.por_tecnico'))
            ->firstWhere('tecnico_id', $tecnico->id);

        $this->assertNotNull($linha, 'técnico não apareceu no relatório');
        $this->assertEqualsWithDelta(1200, (float) $linha['margem_total'], 0.01);
        $this->assertEqualsWithDelta(2, (float) $linha['horas_totais'], 0.01);
        $this->assertSame(1, $linha['os_com_apontamento']);
        // 200 / 2h = 100. Se contasse a OS sem apontamento, daria 600.
        $this->assertEqualsWithDelta(100, (float) $linha['margem_por_hora'], 0.01);
    }

    /**
     * `os_margem` e cache gravado uma vez, na baixa. Mudar a formula so vale
     * para OS futuras ate alguem reprocessar o historico — dai o comando.
     *
     * Simulacao nao pode gravar nada: reescrever em massa a base de um
     * relatorio financeiro sem o operador ver antes o tamanho do estrago e
     * justamente o que a flag existe para impedir.
     */
    public function test_comando_de_recalculo_simula_sem_gravar_e_aplica_com_flag(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'entregue_reparado_pago',
            'valor_total' => 1000,
            'desconto' => 0,
            'valor_final' => 1000,
            'data_entrega' => now(),
        ]);

        // Linha legada: gravada pela fórmula antiga, sem taxa apurada.
        DB::table('os_margem')->insert([
            'os_id' => $orderId,
            'receita_liquida' => 1000,
            'custo_pecas' => 0,
            'custo_comissao' => 0,
            'custo_taxa_recebimento' => 0,
            'custo_imposto' => 0,
            'margem_contribuicao' => 1000,
            'percentual_margem' => 100,
            'calculado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('financeiro')->insert([
            'os_id' => $orderId,
            'tipo' => 'pagar',
            'categoria' => 'Taxa de cartão',
            'descricao' => 'Taxa da operadora',
            'valor' => 34.90,
            'status' => 'pago',
            'origem_tipo' => 'os_recebimento_cartao',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        $this->artisan('financeiro:recalcular-margem')->assertSuccessful();
        $this->assertDatabaseHas('os_margem', ['os_id' => $orderId, 'custo_taxa_recebimento' => 0]);

        $this->artisan('financeiro:recalcular-margem', ['--aplicar' => true])->assertSuccessful();
        $this->assertEqualsWithDelta(
            34.90,
            (float) DB::table('os_margem')->where('os_id', $orderId)->value('custo_taxa_recebimento'),
            0.01
        );
        $this->assertEqualsWithDelta(
            965.10,
            (float) DB::table('os_margem')->where('os_id', $orderId)->value('margem_contribuicao'),
            0.01
        );
    }

    /**
     * A tabela cache só pode conter OS que geraram receita real. Uma linha
     * remanescente de OS que voltou para o fluxo continuaria somando margem de
     * uma venda que não aconteceu.
     */
    public function test_comando_de_recalculo_remove_margem_de_os_sem_receita(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 130,
            'valor_final' => 130,
        ]);

        DB::table('os_margem')->insert([
            'os_id' => $orderId,
            'receita_liquida' => 130,
            'custo_pecas' => 0,
            'custo_comissao' => 0,
            'custo_taxa_recebimento' => 0,
            'custo_imposto' => 0,
            'margem_contribuicao' => 130,
            'percentual_margem' => 100,
            'calculado_em' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('financeiro:recalcular-margem', ['--aplicar' => true])->assertSuccessful();

        $this->assertDatabaseMissing('os_margem', ['os_id' => $orderId]);
    }

    /**
     * A baixa e rede de seguranca, nao autoridade: quem viveu o reparo e o
     * tecnico que o concluiu. Se ja existe apontamento, o valor enviado no
     * fechamento e ignorado.
     */
    public function test_baixa_nao_sobrescreve_apontamento_do_tecnico(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 400,
            'valor_final' => 400,
            'data_entrega' => now(),
        ]);

        $this->seedStatusReparoConcluido();

        $this->patchJson("/api/v1/orders/{$orderId}/status", [
            'status' => 'reparo_concluido',
            'tempo_tecnico_horas' => 4,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/closure", [
            'encerrar_como' => 'entregue_reparado_pago',
            'data_entrega' => now()->toDateString(),
            'tempo_tecnico_horas' => 99,
            'recebimentos' => [['valor' => 400.00, 'forma_pagamento' => 'pix']],
        ])->assertOk();

        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId)->assertOk();

        $this->assertJsonNumero($response, 'data.margem.tempo_tecnico_horas', 4);
    }

    /**
     * OS que chegou na baixa sem apontamento ainda pode receber um: depois do
     * fechamento a margem ja foi calculada, e o dado nunca mais entraria.
     */
    public function test_baixa_preenche_apontamento_ausente(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        Sanctum::actingAs($admin, ['*']);

        $orderId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 400,
            'valor_final' => 400,
            'data_entrega' => now(),
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/closure", [
            'encerrar_como' => 'entregue_reparado_pago',
            'data_entrega' => now()->toDateString(),
            'tempo_tecnico_horas' => 2,
            'recebimentos' => [['valor' => 400.00, 'forma_pagamento' => 'pix']],
        ])->assertOk();

        $response = $this->getJson('/api/v1/financeiro/margem/' . $orderId)->assertOk();

        $this->assertJsonNumero($response, 'data.margem.tempo_tecnico_horas', 2);
        $this->assertJsonNumero($response, 'data.margem.margem_por_hora', 200);
    }
}
