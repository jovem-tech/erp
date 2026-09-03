<?php

namespace Tests\Feature\Fiscal;

use App\Models\DocumentoFiscal;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Services\Financeiro\FinanceiroReportService;
use App\Services\Fiscal\AnexoXService;
use App\Support\RegimeTributario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Anexo X — Relatório Mensal das Receitas Brutas (Res. CGSN 140/2018, art. 106).
 */
class AnexoXTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $competencia;

    private int $clienteId;

    private int $equipamentoId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();

        $this->clienteId = $this->createClientRecord();
        $this->equipamentoId = $this->createEquipmentRecord($this->clienteId);
        $this->competencia = now()->format('Y-m');
    }

    private function servico(): AnexoXService
    {
        return app(AnexoXService::class);
    }

    /**
     * OS entregue e paga, com peça e mão de obra.
     */
    private function osEntregue(float $pecas, float $maoObra, float $desconto = 0.0, array $overrides = []): int
    {
        $total = $pecas + $maoObra;

        return $this->createOrderRecord(array_merge([
            'cliente_id' => $this->clienteId,
            'equipamento_id' => $this->equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_conclusao' => now()->startOfMonth()->addDays(5),
            'data_entrega' => now()->startOfMonth()->addDays(6),
            'valor_pecas' => $pecas,
            'valor_mao_obra' => $maoObra,
            'valor_total' => $total,
            'desconto' => $desconto,
            'valor_final' => $total - $desconto,
        ], $overrides));
    }

    // ------------------------------------------------------------ segregação

    public function test_anexo_x_soma_pecas_de_os_em_comercio_e_mao_de_obra_em_servicos(): void
    {
        $this->osEntregue(pecas: 200.00, maoObra: 300.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(200.00, $linhas['iii']['valor'], 'Peça aplicada na OS é revenda de mercadoria.');
        $this->assertSame(300.00, $linhas['ix']['valor'], 'Mão de obra é prestação de serviço.');
        $this->assertSame(500.00, $linhas['x']['valor']);
    }

    /**
     * O desconto foi concedido sobre o total, não sobre a mão de obra. Jogá-lo
     * inteiro num dos blocos distorceria a segregação por atividade que o
     * formulário exige.
     */
    public function test_anexo_x_rateia_o_desconto_da_os_proporcionalmente_entre_comercio_e_servicos(): void
    {
        // 200 peça + 300 serviço, R$ 50 de desconto sobre 500 => 10%.
        $this->osEntregue(pecas: 200.00, maoObra: 300.00, desconto: 50.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(180.00, $linhas['iii']['valor']);
        $this->assertSame(270.00, $linhas['ix']['valor']);
        $this->assertSame(450.00, $linhas['x']['valor'], 'A soma tem que fechar no líquido.');
    }

    public function test_anexo_x_joga_os_sem_quebra_de_valores_inteira_em_servicos(): void
    {
        $this->osEntregue(pecas: 0.0, maoObra: 0.0, overrides: [
            'valor_total' => 150.00,
            'valor_final' => 150.00,
        ]);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(0.0, $linhas['iii']['valor']);
        $this->assertSame(150.00, $linhas['ix']['valor'], 'Sem quebra de valores, o CNAE da casa é serviço.');
    }

    public function test_linhas_de_industria_saem_sempre_zeradas_mas_presentes(): void
    {
        $this->osEntregue(pecas: 100.00, maoObra: 100.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        foreach (['iv', 'v', 'vi'] as $linha) {
            $this->assertArrayHasKey($linha, $linhas, 'A linha existe no formulário oficial mesmo zerada.');
            $this->assertSame(0.0, $linhas[$linha]['valor']);
        }
    }

    /**
     * Só `entregue_reparado_pago` gera receita. Entregue sem custo e em
     * garantia são contagem operacional — somá-los inflaria um documento
     * entregue ao fisco com receita que não existiu.
     */
    public function test_os_entregue_sem_custo_nao_entra_no_anexo_x(): void
    {
        $this->osEntregue(pecas: 100.00, maoObra: 100.00, overrides: ['status' => 'entregue_sem_custo']);

        $this->assertSame(0.0, $this->servico()->apurar($this->competencia)['linhas']['x']['valor']);
    }

    // ------------------------------------------------- invariantes do formulário

    /**
     * O documento fiscal decide a COLUNA, nunca o TOTAL. Emitir uma nota não
     * pode mudar quanto se faturou no mês.
     */
    public function test_linha_x_nao_muda_quando_um_documento_fiscal_e_emitido(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00);

        $antes = $this->servico()->apurar($this->competencia)['linhas'];

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 300.00,
            'valor_pecas' => 0,
            'valor_total' => 300.00,
            'numero' => '35',
            'serie' => '1',
            'emitido_em' => now(),
        ]);

        $depois = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(300.00, $antes['vii']['valor'], 'Antes da nota, serviço estava com dispensa.');
        $this->assertSame(0.0, $antes['viii']['valor']);
        $this->assertSame(0.0, $depois['vii']['valor'], 'Depois da nota, migrou de coluna.');
        $this->assertSame(300.00, $depois['viii']['valor']);

        foreach (['iii', 'vi', 'ix', 'x'] as $linha) {
            $this->assertSame(
                $antes[$linha]['valor'],
                $depois[$linha]['valor'],
                "A linha {$linha} não pode mudar porque uma nota foi emitida."
            );
        }
    }

    public function test_totais_do_formulario_fecham_em_qualquer_combinacao(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00, desconto: 25.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 100.00,
            'valor_pecas' => 0,
            'valor_total' => 100.00,
            'emitido_em' => now(),
        ]);

        $l = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame($l['iii']['valor'], round($l['i']['valor'] + $l['ii']['valor'], 2));
        $this->assertSame($l['vi']['valor'], round($l['iv']['valor'] + $l['v']['valor'], 2));
        $this->assertSame($l['ix']['valor'], round($l['vii']['valor'] + $l['viii']['valor'], 2));
        $this->assertSame($l['x']['valor'], round($l['iii']['valor'] + $l['vi']['valor'] + $l['ix']['valor'], 2));
    }

    // ------------------------------------------------------ igualdade com o DRE

    /**
     * Dois números diferentes para o faturamento do mesmo mês não são detalhe
     * de tela: um deles está errado, e o usuário não tem como saber qual.
     */
    public function test_total_do_anexo_x_bate_com_a_receita_liquida_do_dre_de_competencia(): void
    {
        $this->montarMesCompleto();

        $anexo = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_COMPETENCIA);
        $dre = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(
            round((float) $dre['receita']['receita_liquida'], 2),
            $anexo['linhas']['x']['valor'],
            'Anexo X e DRE discordam sobre a receita do mesmo mês — o usuário vê dois números diferentes.'
        );
    }

    public function test_total_do_anexo_x_bate_com_a_receita_liquida_do_dre_de_caixa(): void
    {
        $this->montarMesCompleto();

        $anexo = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA);
        $dre = app(FinanceiroReportService::class)->dreCashReport($this->competencia);

        $this->assertSame(
            round((float) $dre['receita']['receita_liquida'], 2),
            $anexo['linhas']['x']['valor'],
            'Anexo X e DRE de caixa discordam sobre a receita do mesmo mês.'
        );
    }

    // ------------------------------------------------------- documento fiscal

    public function test_os_com_nfse_e_sem_nfe_manda_mao_de_obra_para_viii_e_pecas_para_i(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 300.00,
            'valor_pecas' => 0,
            'valor_total' => 300.00,
            'emitido_em' => now(),
        ]);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(200.00, $relatorio['linhas']['i']['valor'], 'Peça sem NF-e fica com dispensa.');
        $this->assertSame(0.0, $relatorio['linhas']['ii']['valor']);
        $this->assertSame(0.0, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(300.00, $relatorio['linhas']['viii']['valor']);
        $this->assertContains('peca_sem_nfe', $relatorio['drill_down'][0]['alertas']);
    }

    /**
     * A nota cancelada continua existindo no fisco, mas não documenta mais
     * nada: a receita volta para a coluna "com dispensa".
     */
    public function test_documento_cancelado_devolve_a_receita_para_a_coluna_com_dispensa(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_CANCELADO,
            'os_id' => $osId,
            'valor_servicos' => 300.00,
            'valor_total' => 300.00,
            'emitido_em' => now(),
            'cancelado_em' => now(),
        ]);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(300.00, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(0.0, $relatorio['linhas']['viii']['valor']);
        $this->assertContains('documento_cancelado', $relatorio['drill_down'][0]['alertas']);
    }

    public function test_documento_em_rascunho_nao_cobre_a_receita(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_RASCUNHO,
            'os_id' => $osId,
            'valor_servicos' => 300.00,
            'valor_total' => 300.00,
        ]);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(300.00, $relatorio['linhas']['vii']['valor'], 'Rascunho é intenção, não documento.');
        $this->assertContains('documento_rascunho', $relatorio['drill_down'][0]['alertas']);
    }

    /**
     * Nota englobando duas OS, ou desconto dado depois de emitir. Sem o min(),
     * a linha VII ficaria negativa e IX deixaria de ser VII+VIII.
     */
    public function test_documento_com_valor_maior_que_a_os_nao_deixa_a_linha_negativa(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 900.00,
            'valor_total' => 900.00,
            'emitido_em' => now(),
        ]);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(0.0, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(300.00, $relatorio['linhas']['viii']['valor'], 'Cobre no máximo o valor da operação.');
        $this->assertSame(300.00, $relatorio['linhas']['ix']['valor']);
        $this->assertContains('documento_excedente', $relatorio['drill_down'][0]['alertas']);
    }

    public function test_documento_parcial_cobre_so_ate_o_proprio_valor(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 120.00,
            'valor_total' => 120.00,
            'emitido_em' => now(),
        ]);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(180.00, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(120.00, $relatorio['linhas']['viii']['valor']);
        $this->assertContains('documento_parcial', $relatorio['drill_down'][0]['alertas']);
    }

    public function test_duas_notas_emitidas_na_mesma_os_somam_sem_estourar_a_parcela(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);

        foreach ([100.00, 200.00] as $valor) {
            DocumentoFiscal::query()->create([
                'tipo' => DocumentoFiscal::TIPO_NFSE,
                'status' => DocumentoFiscal::STATUS_EMITIDO,
                'os_id' => $osId,
                'valor_servicos' => $valor,
                'valor_total' => $valor,
                'emitido_em' => now(),
            ]);
        }

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(0.0, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(300.00, $relatorio['linhas']['viii']['valor']);
    }

    public function test_nfe_cobre_mercadoria_e_nao_servico(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_pecas' => 200.00,
            'valor_total' => 200.00,
            'emitido_em' => now(),
        ]);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(0.0, $linhas['i']['valor']);
        $this->assertSame(200.00, $linhas['ii']['valor']);
        $this->assertSame(300.00, $linhas['vii']['valor'], 'NF-e não documenta serviço.');
    }

    // ------------------------------------------------------------- devoluções

    public function test_devolucao_abate_da_coluna_com_dispensa_da_atividade_devolvida(): void
    {
        $vendaId = $this->vendaDeBalcao(mercadoria: 400.00, servico: 0.0);
        $this->devolucaoDeVenda($vendaId, 100.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(300.00, $linhas['i']['valor'], 'A devolução abate do que não teve documento.');
        $this->assertSame(300.00, $linhas['x']['valor']);
    }

    /**
     * A devolução não cancela a nota já emitida — ela continua documentando a
     * operação original. Por isso o abatimento começa na coluna "com dispensa"
     * e só escorre para "com documento" quando não cabe.
     */
    public function test_devolucao_maior_que_a_coluna_com_dispensa_escorre_sem_deixar_linha_negativa(): void
    {
        $vendaId = $this->vendaDeBalcao(mercadoria: 400.00, servico: 0.0);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFCE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'venda_id' => $vendaId,
            'valor_pecas' => 300.00,
            'valor_total' => 300.00,
            'emitido_em' => now(),
        ]);

        // Sobra 100 na coluna "com dispensa"; a devolução de 250 estoura ela.
        $this->devolucaoDeVenda($vendaId, 250.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(0.0, $linhas['i']['valor']);
        $this->assertSame(150.00, $linhas['ii']['valor'], 'O excedente escorreu para a coluna com documento.');
        $this->assertSame(150.00, $linhas['iii']['valor']);
        $this->assertSame(150.00, $linhas['x']['valor']);
    }

    /**
     * Venda de balcão vinculada a uma OS gera DUAS operações — a OS (por
     * `os.valor_total`) e o título da venda (por `financeiro.venda_id`). São
     * receitas diferentes e é assim que o DRE conta; o risco é o Anexo X somar
     * a mesma coisa duas vezes ao tentar cruzá-las.
     */
    public function test_venda_vinculada_a_os_nao_conta_a_receita_duas_vezes(): void
    {
        $osId = $this->osEntregue(pecas: 100.00, maoObra: 200.00);
        $this->vendaDeBalcao(mercadoria: 80.00, servico: 0.0, overrides: ['os_id' => $osId]);

        $anexo = $this->servico()->apurar($this->competencia);
        $dre = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(380.00, $anexo['linhas']['x']['valor']);
        $this->assertSame(
            round((float) $dre['receita']['receita_liquida'], 2),
            $anexo['linhas']['x']['valor'],
            'A venda vinculada a OS tem que ser contada uma vez só, e da mesma forma que o DRE conta.'
        );
    }

    /**
     * Documento com `os_id` E `venda_id` pertence à VENDA. Contá-lo nos dois
     * cobriria a mesma receita duas vezes, e a OS apareceria como documentada
     * sem ter documento próprio.
     */
    public function test_documento_com_os_id_e_venda_id_cobre_apenas_a_venda(): void
    {
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 300.00);
        $vendaId = $this->vendaDeBalcao(mercadoria: 100.00, servico: 0.0, overrides: ['os_id' => $osId]);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFCE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'venda_id' => $vendaId,
            'valor_pecas' => 100.00,
            'valor_total' => 100.00,
            'emitido_em' => now(),
        ]);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(100.00, $linhas['ii']['valor'], 'A mercadoria da venda está documentada.');
        $this->assertSame(0.0, $linhas['i']['valor']);
        // A OS continua sem documento próprio: a nota é da venda.
        $this->assertSame(300.00, $linhas['vii']['valor']);
        $this->assertSame(0.0, $linhas['viii']['valor']);
        $this->assertSame(400.00, $linhas['x']['valor'], 'A cobertura não pode inflar nem reduzir o total.');
    }

    /**
     * Devolução maior que toda a receita do mês. O total sai NEGATIVO de
     * propósito — maquiar um formulário entregue ao fisco é pior que exibir um
     * número feio — mas a distribuição entre as colunas não pode inventar
     * valor em lugar nenhum.
     */
    public function test_devolucao_maior_que_a_receita_do_mes_deixa_x_negativo_sem_inventar_valor(): void
    {
        $vendaId = $this->vendaDeBalcao(mercadoria: 100.00, servico: 0.0);
        $this->devolucaoDeVenda($vendaId, 250.00);

        $anexo = $this->servico()->apurar($this->competencia);
        $l = $anexo['linhas'];
        $dre = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(-150.00, $l['x']['valor']);
        $this->assertSame(
            round((float) $dre['receita']['receita_liquida'], 2),
            $l['x']['valor'],
            'Mesmo negativo, o total continua sendo o mesmo que o DRE publica.'
        );

        // Os totais de bloco continuam fechando.
        $this->assertSame($l['iii']['valor'], round($l['i']['valor'] + $l['ii']['valor'], 2));
        $this->assertSame($l['ix']['valor'], round($l['vii']['valor'] + $l['viii']['valor'], 2));
        $this->assertSame($l['x']['valor'], round($l['iii']['valor'] + $l['vi']['valor'] + $l['ix']['valor'], 2));

        // O rombo fica na atividade devolvida, sem contaminar serviços.
        $this->assertSame(0.0, $l['ix']['valor'], 'Não havia receita de serviço no mês.');
    }

    // ----------------------------------------------------------------- caixa

    public function test_caixa_rateia_a_baixa_na_proporcao_da_os_de_origem(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00);
        $this->baixaDeOs($osId, 500.00);

        $linhas = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA)['linhas'];

        $this->assertSame(200.00, $linhas['iii']['valor']);
        $this->assertSame(300.00, $linhas['ix']['valor']);
    }

    /**
     * Baixa parcial usa a proporção do todo. Não é verdade sobre o que o
     * cliente pagou, mas é a única regra que soma de volta ao total quando
     * todas as parcelas caem.
     */
    public function test_caixa_rateia_baixa_parcial_na_proporcao_do_todo(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00);
        $this->baixaDeOs($osId, 100.00);

        $linhas = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA)['linhas'];

        $this->assertSame(40.00, $linhas['iii']['valor']);
        $this->assertSame(60.00, $linhas['ix']['valor']);
    }

    /**
     * `SalePaymentService::createReceivable()` grava `os_id` no título da
     * VENDA quando ela está vinculada a uma OS. Ler `os_id` primeiro aplicaria
     * a mistura peça/serviço da OS ao pagamento de uma venda de balcão.
     */
    public function test_caixa_usa_a_proporcao_da_venda_quando_o_titulo_tem_venda_id_e_os_id(): void
    {
        // OS toda de mão de obra; venda vinculada, toda de mercadoria.
        $osId = $this->osEntregue(pecas: 0.0, maoObra: 500.00, overrides: ['status' => 'aguardando_reparo']);
        $vendaId = $this->vendaDeBalcao(mercadoria: 100.00, servico: 0.0, overrides: ['os_id' => $osId]);

        $titulo = Financeiro::query()->where('venda_id', $vendaId)->first();
        $titulo->forceFill(['os_id' => $osId])->save();

        FinanceiroMovimento::create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->startOfMonth()->addDays(7)->toDateString(),
            'valor_movimento' => 100.00,
            'forma_pagamento' => 'dinheiro',
        ]);

        $linhas = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA)['linhas'];

        $this->assertSame(100.00, $linhas['iii']['valor'], 'A baixa é da venda, não da OS.');
        $this->assertSame(0.0, $linhas['ix']['valor']);
    }

    public function test_caixa_classifica_titulo_manual_como_servico_e_sinaliza(): void
    {
        $titulo = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'avulso' => true,
            'categoria' => 'Serviço avulso',
            'descricao' => 'Conserto rápido no balcão',
            'valor' => 80.00,
            'grupo_dre' => Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->startOfMonth()->addDays(3)->toDateString(),
            'data_competencia' => now()->startOfMonth()->addDays(3)->toDateString(),
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->startOfMonth()->addDays(3)->toDateString(),
            'valor_movimento' => 80.00,
            'forma_pagamento' => 'dinheiro',
        ]);

        $relatorio = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA);

        $this->assertSame(80.00, $relatorio['linhas']['ix']['valor']);
        $this->assertSame(1, $relatorio['origens']['sem_classificacao']['quantidade'], 'Fica visível, não silencioso.');
        $this->assertContains('sem_classificacao_de_atividade', $relatorio['drill_down'][0]['alertas']);
    }

    // ------------------------------------------------------------ item avulso

    public function test_venda_de_balcao_com_item_avulso_cai_em_comercio_e_fica_auditavel(): void
    {
        $this->vendaDeBalcao(mercadoria: 0.0, servico: 0.0, avulso: 150.00);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(150.00, $relatorio['linhas']['iii']['valor']);
        $this->assertSame(150.00, $relatorio['origens']['avulsos_da_venda']['comercio'], 'Exposto para conferência.');
    }

    // ------------------------------------------------------------- acumulado

    public function test_acumulado_do_ano_usa_limite_integral_sem_data_de_abertura(): void
    {
        $this->osEntregue(pecas: 0.0, maoObra: 1000.00);

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertSame(81000.00, $acumulado['limite']);
        $this->assertFalse($acumulado['limite_proporcional']);
        $this->assertSame('dentro', $acumulado['faixa']);
    }

    public function test_acumulado_do_ano_proporcionaliza_o_limite_no_ano_de_abertura(): void
    {
        // Abertura em setembro do ano corrente => 4 meses de atividade.
        $this->configurar('empresa_data_abertura', now()->format('Y').'-09-15');

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertTrue($acumulado['limite_proporcional']);
        $this->assertSame(4, $acumulado['meses_de_atividade']);
        $this->assertSame(27000.00, $acumulado['limite']);
    }

    public function test_acumulado_sinaliza_faixa_de_excesso_ate_vinte_por_cento(): void
    {
        $this->osEntregue(pecas: 0.0, maoObra: 85000.00);

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertSame('excesso_ate_20', $acumulado['faixa']);
        $this->assertNotNull($acumulado['mensagem']);
    }

    public function test_acumulado_sinaliza_excesso_acima_de_vinte_por_cento(): void
    {
        $this->osEntregue(pecas: 0.0, maoObra: 120000.00);

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertSame('excesso_acima_20', $acumulado['faixa']);
        $this->assertStringContainsString('retroage', (string) $acumulado['mensagem']);
    }

    /**
     * Fora do MEI o teto de R$ 81.000 não existe — exibi-lo seria erro ativo.
     */
    public function test_acumulado_nao_aparece_quando_o_regime_nao_e_mei(): void
    {
        $this->configurar(RegimeTributario::CHAVE, RegimeTributario::SIMPLES);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertNull($relatorio['acumulado_ano']);
        $this->assertNotNull($relatorio['aviso_regime_tributario']);
    }

    // ------------------------------------------------------- receita sem doc

    public function test_receita_sem_documento_marca_tomador_pessoa_juridica(): void
    {
        $clienteId = $this->createClientRecord([
            'nome_razao' => 'Empresa Cliente LTDA',
            'tipo_pessoa' => 'juridica',
            'cpf_cnpj' => '11222333000181',
        ]);

        $this->osEntregue(pecas: 0.0, maoObra: 400.00, overrides: ['cliente_id' => $clienteId]);

        $semDocumento = $this->servico()->apurar($this->competencia)['sem_documento'];

        $this->assertSame(400.00, $semDocumento['total']);
        $this->assertSame(400.00, $semDocumento['total_tomador_pj'], 'Venda para PJ não é dispensada de emitir.');
        $this->assertContains('tomador_pj_sem_documento', $semDocumento['itens'][0]['alertas']);
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * Um mês com tudo que o sistema sabe faturar, para os testes de igualdade
     * com o DRE não passarem por falta de variedade.
     */
    private function montarMesCompleto(): void
    {
        $osId = $this->osEntregue(pecas: 200.00, maoObra: 300.00, desconto: 50.00);
        $this->baixaDeOs($osId, 450.00);

        $vendaId = $this->vendaDeBalcao(mercadoria: 120.00, servico: 30.00, avulso: 50.00);
        $this->baixaDeVenda($vendaId, 200.00);

        $this->devolucaoDeVenda($vendaId, 40.00);

        DocumentoFiscal::query()->create([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_EMITIDO,
            'os_id' => $osId,
            'valor_servicos' => 200.00,
            'valor_total' => 200.00,
            'emitido_em' => now(),
        ]);
    }

    private function vendaDeBalcao(float $mercadoria, float $servico, float $avulso = 0.0, array $overrides = []): int
    {
        $total = $mercadoria + $servico + $avulso;

        $vendaId = $this->createSaleRecord(array_merge([
            'numero' => 'VD-'.random_int(100000, 999999),
            'data_venda' => now()->startOfMonth()->addDays(7)->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'valor_pago' => $total,
        ], $overrides));

        foreach ([['peca', $mercadoria], ['servico', $servico], ['avulso', $avulso]] as [$tipo, $valor]) {
            if ($valor <= 0.0) {
                continue;
            }

            DB::table('venda_itens')->insert([
                'venda_id' => $vendaId,
                'tipo_item' => $tipo,
                'descricao' => 'Item '.$tipo,
                'quantidade' => 1,
                'valor_unitario' => $valor,
                'total' => $valor,
                'custo_total' => 0,
                'baixa_estoque' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Financeiro::query()->create([
            'venda_id' => $vendaId,
            'os_id' => $overrides['os_id'] ?? null,
            'avulso' => ! isset($overrides['os_id']),
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Venda de balcão',
            'descricao' => 'Venda de balcão',
            'valor' => $total,
            'grupo_dre' => Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->startOfMonth()->addDays(7)->toDateString(),
            'data_competencia' => now()->startOfMonth()->addDays(7)->toDateString(),
            'origem_tipo' => 'venda',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        return $vendaId;
    }

    private function devolucaoDeVenda(int $vendaId, float $valor): void
    {
        $titulo = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Devolução de venda',
            'descricao' => 'Devolução da venda '.$vendaId,
            'valor' => $valor,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->startOfMonth()->addDays(9)->toDateString(),
            'data_competencia' => now()->startOfMonth()->addDays(9)->toDateString(),
            'origem_tipo' => Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO,
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        $devolucaoId = $this->createSaleReturnRecord([
            'numero' => 'DV-'.random_int(100000, 999999),
            'venda_id' => $vendaId,
            'financeiro_id' => $titulo->id,
            'data_devolucao' => now()->startOfMonth()->addDays(9)->toDateString(),
            'subtotal_itens' => $valor,
            'valor_devolvido' => $valor,
            'valor_reembolsado' => $valor,
        ]);

        $item = DB::table('venda_itens')->where('venda_id', $vendaId)->orderBy('id')->first();

        DB::table('venda_devolucao_itens')->insert([
            'venda_devolucao_id' => $devolucaoId,
            'venda_item_id' => (int) $item->id,
            'quantidade' => 1,
            'valor_unitario' => $valor,
            'valor_total' => $valor,
            'valor_reembolsado' => $valor,
            'custo_unitario' => 0,
            'custo_total' => 0,
            'retorna_estoque' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => now()->startOfMonth()->addDays(9)->toDateString(),
            'valor_movimento' => $valor,
            'forma_pagamento' => 'dinheiro',
        ]);
    }

    private function baixaDeOs(int $osId, float $valor): void
    {
        $titulo = Financeiro::query()->create([
            'os_id' => $osId,
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'Serviço de OS',
            'descricao' => 'Cobrança da OS '.$osId,
            'valor' => $valor,
            'grupo_dre' => Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            'status' => Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->startOfMonth()->addDays(6)->toDateString(),
            'data_competencia' => now()->startOfMonth()->addDays(6)->toDateString(),
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->startOfMonth()->addDays(6)->toDateString(),
            'valor_movimento' => $valor,
            'forma_pagamento' => 'pix',
        ]);
    }

    private function baixaDeVenda(int $vendaId, float $valor): void
    {
        $titulo = Financeiro::query()->where('venda_id', $vendaId)->firstOrFail();

        FinanceiroMovimento::create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_ENTRADA,
            'data_movimento' => now()->startOfMonth()->addDays(7)->toDateString(),
            'valor_movimento' => $valor,
            'forma_pagamento' => 'dinheiro',
        ]);
    }

    private function configurar(string $chave, string $valor): void
    {
        DB::table('configuracoes')->updateOrInsert(
            ['chave' => $chave],
            ['valor' => $valor, 'tipo' => 'texto', 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
