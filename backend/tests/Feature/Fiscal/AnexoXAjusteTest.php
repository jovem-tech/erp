<?php

namespace Tests\Feature\Fiscal;

use App\Models\AnexoXAjuste;
use App\Models\User;
use App\Services\Financeiro\FinanceiroReportService;
use App\Services\Fiscal\AnexoXAjusteService;
use App\Services\Fiscal\AnexoXFechamentoService;
use App\Services\Fiscal\AnexoXService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Ajustes manuais declarados no Anexo X.
 *
 * O relatório é o espelho do que está lançado no ERP, mas o Anexo X tem que
 * declarar TODA a receita bruta — inclusive a que nunca passou pelo sistema. O
 * ajuste é o lançamento auditado que cobre essa diferença.
 */
class AnexoXAjusteTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $competencia;

    private int $clienteId;

    private int $equipamentoId;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->seedOrderCatalog();

        $this->clienteId = $this->createClientRecord();
        $this->equipamentoId = $this->createEquipmentRecord($this->clienteId);
        $this->competencia = now()->format('Y-m');
        $this->usuario = $this->createUserRecord(['grupo_id' => 1]);
    }

    private function servico(): AnexoXService
    {
        return app(AnexoXService::class);
    }

    private function ajustes(): AnexoXAjusteService
    {
        return app(AnexoXAjusteService::class);
    }

    private function osEntregue(float $pecas, float $maoObra): int
    {
        return $this->createOrderRecord([
            'cliente_id' => $this->clienteId,
            'equipamento_id' => $this->equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_conclusao' => now()->startOfMonth()->addDays(5),
            'data_entrega' => now()->startOfMonth()->addDays(6),
            'valor_pecas' => $pecas,
            'valor_mao_obra' => $maoObra,
            'valor_total' => $pecas + $maoObra,
            'desconto' => 0,
            'valor_final' => $pecas + $maoObra,
        ]);
    }

    private function lancar(string $linha, float $valor, ?string $regime = null): AnexoXAjuste
    {
        return $this->ajustes()->lancar(
            $this->competencia,
            $regime ?? AnexoXService::REGIME_COMPETENCIA,
            $linha,
            $valor,
            'Serviço cobrado em dinheiro, não lançado no sistema',
            (int) $this->usuario->id
        );
    }

    // ------------------------------------------------------------- apuração

    public function test_ajuste_em_linha_folha_soma_ao_declarado_sem_mudar_o_calculado(): void
    {
        $this->osEntregue(0.0, 210.00);
        $this->lancar('vii', 90.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(210.00, $linhas['vii']['calculado'], 'O apurado não se move.');
        $this->assertSame(90.00, $linhas['vii']['ajuste']);
        $this->assertSame(300.00, $linhas['vii']['valor'], 'O declarado é calculado + ajuste.');
    }

    public function test_ajuste_recompoe_as_linhas_calculadas(): void
    {
        $this->osEntregue(100.00, 200.00);
        $this->lancar('vii', 90.00);
        $this->lancar('i', 10.00);

        $l = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(110.00, $l['iii']['valor'], 'III recompõe sozinha.');
        $this->assertSame(290.00, $l['ix']['valor']);
        $this->assertSame(400.00, $l['x']['valor']);
        $this->assertSame($l['iii']['valor'], round($l['i']['valor'] + $l['ii']['valor'], 2));
        $this->assertSame($l['x']['valor'], round($l['iii']['valor'] + $l['vi']['valor'] + $l['ix']['valor'], 2));
    }

    public function test_ajuste_negativo_reduz_a_linha(): void
    {
        $this->osEntregue(0.0, 300.00);
        $this->lancar('vii', -50.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(250.00, $linhas['vii']['valor']);
        $this->assertSame(-50.00, $linhas['vii']['ajuste']);
    }

    public function test_dois_ajustes_na_mesma_linha_somam_e_ficam_listados_separados(): void
    {
        $this->osEntregue(0.0, 100.00);
        $this->lancar('vii', 30.00);
        $this->lancar('vii', 20.00);

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(150.00, $relatorio['linhas']['vii']['valor']);
        $this->assertSame(50.00, $relatorio['linhas']['vii']['ajuste']);
        $this->assertCount(2, $relatorio['ajustes']['por_linha']['vii'], 'Dois fatos, dois lançamentos.');
        $this->assertSame(2, $relatorio['ajustes']['quantidade']);
    }

    public function test_ajuste_cancelado_sai_da_apuracao_mas_continua_listado(): void
    {
        $this->osEntregue(0.0, 100.00);
        $ajuste = $this->lancar('vii', 40.00);

        $this->ajustes()->cancelar($ajuste, (int) $this->usuario->id, 'Lançado na competência errada');

        $relatorio = $this->servico()->apurar($this->competencia);

        $this->assertSame(100.00, $relatorio['linhas']['vii']['valor'], 'Sai da conta.');
        $this->assertSame(0.0, $relatorio['linhas']['vii']['ajuste']);
        $this->assertCount(1, $relatorio['ajustes']['por_linha']['vii'], 'Mas continua na trilha.');
        $this->assertNotNull($relatorio['ajustes']['por_linha']['vii'][0]['cancelado_em']);
        $this->assertSame('Lançado na competência errada', $relatorio['ajustes']['por_linha']['vii'][0]['motivo_cancelamento']);
        $this->assertSame(0, $relatorio['ajustes']['quantidade']);
    }

    /**
     * `aplicarDeducoes()` faz cascata entre colunas para não deixar linha
     * negativa. Uma devolução de venda QUE ESTÁ no sistema não pode consumir um
     * ajuste lançado para uma venda que NÃO está — por isso o ajuste entra
     * depois das deduções.
     */
    public function test_ajuste_nao_e_alcancado_pela_cascata_de_devolucoes(): void
    {
        $vendaId = $this->vendaDeBalcao(80.00);
        $this->devolucaoDeVenda($vendaId, 80.00);   // zera o comércio apurado
        $this->lancar('i', 200.00);

        $linhas = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame(0.0, $linhas['i']['calculado'], 'A devolução zerou o apurado.');
        $this->assertSame(200.00, $linhas['i']['valor'], 'E não comeu o ajuste.');
        $this->assertSame(200.00, $linhas['x']['valor']);
    }

    public function test_ajuste_de_um_regime_nao_vaza_para_o_outro(): void
    {
        $this->osEntregue(0.0, 100.00);
        $this->lancar('vii', 90.00, AnexoXService::REGIME_COMPETENCIA);

        $competencia = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_COMPETENCIA);
        $caixa = $this->servico()->apurar($this->competencia, AnexoXService::REGIME_CAIXA);

        $this->assertSame(90.00, $competencia['linhas']['vii']['ajuste']);
        $this->assertSame(0.0, $caixa['linhas']['vii']['ajuste'], 'São duas apurações independentes.');
    }

    public function test_ajuste_registra_autor_e_data(): void
    {
        $this->lancar('vii', 90.00);

        $lancamento = $this->servico()->apurar($this->competencia)['ajustes']['por_linha']['vii'][0];

        $this->assertSame((int) $this->usuario->id, $lancamento['criado_por']['id']);
        $this->assertSame($this->usuario->nome, $lancamento['criado_por']['nome']);
        $this->assertNotNull($lancamento['criado_em']);
        $this->assertSame('Serviço cobrado em dinheiro, não lançado no sistema', $lancamento['motivo']);
    }

    public function test_linhas_calculadas_nao_sao_ajustaveis(): void
    {
        foreach (['iii', 'vi', 'ix', 'x'] as $linha) {
            $this->assertFalse(AnexoXAjuste::linhaAjustavel($linha), "A linha {$linha} é soma das demais.");
        }

        foreach (AnexoXAjuste::LINHAS_AJUSTAVEIS as $linha) {
            $this->assertTrue(AnexoXAjuste::linhaAjustavel($linha));
        }
    }

    public function test_totais_do_formulario_continuam_fechando_com_ajuste(): void
    {
        $this->osEntregue(50.00, 150.00);
        $this->lancar('i', 25.00);
        $this->lancar('viii', 75.00);

        $l = $this->servico()->apurar($this->competencia)['linhas'];

        $this->assertSame($l['iii']['valor'], round($l['i']['valor'] + $l['ii']['valor'], 2));
        $this->assertSame($l['vi']['valor'], round($l['iv']['valor'] + $l['v']['valor'], 2));
        $this->assertSame($l['ix']['valor'], round($l['vii']['valor'] + $l['viii']['valor'], 2));
        $this->assertSame($l['x']['valor'], round($l['iii']['valor'] + $l['vi']['valor'] + $l['ix']['valor'], 2));
    }

    // -------------------------------------------- a invariante em duas partes

    /**
     * A igualdade original continua valendo — agora sobre o CALCULADO.
     */
    public function test_x_calculado_continua_batendo_com_o_dre_mesmo_com_ajuste(): void
    {
        $this->osEntregue(100.00, 200.00);
        $this->lancar('vii', 90.00);

        $anexo = $this->servico()->apurar($this->competencia);
        $dre = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(
            round((float) $dre['receita']['receita_liquida'], 2),
            $anexo['linhas']['x']['calculado'],
            'O apurado continua sendo exatamente o que o DRE conhece.'
        );
    }

    /**
     * E o declarado se afasta do DRE por exatamente o ajuste — a exceção fica
     * pinada, não implícita.
     */
    public function test_ajuste_manual_afasta_x_do_dre_exatamente_pelo_valor_ajustado(): void
    {
        $this->osEntregue(100.00, 200.00);
        $this->lancar('vii', 90.00);

        $anexo = $this->servico()->apurar($this->competencia);
        $dre = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(
            90.00,
            round($anexo['linhas']['x']['valor'] - (float) $dre['receita']['receita_liquida'], 2)
        );
    }

    /**
     * O ajuste é parcela somada depois da apuração e nunca toca
     * `ReceitaBrutaSource`. Se alguém um dia implementá-lo como um `Financeiro`
     * sintético, este teste quebra na hora.
     */
    public function test_ajuste_nao_aparece_no_dre(): void
    {
        $this->osEntregue(100.00, 200.00);

        $antes = app(FinanceiroReportService::class)->dreReport($this->competencia);
        $this->lancar('vii', 90.00);
        $depois = app(FinanceiroReportService::class)->dreReport($this->competencia);

        $this->assertSame(
            round((float) $antes['receita']['receita_liquida'], 2),
            round((float) $depois['receita']['receita_liquida'], 2),
            'O DRE é gerencial: não enxerga receita que nunca passou pelo sistema.'
        );
    }

    // ------------------------------------------------------------ fechamento

    public function test_ajuste_entra_no_payload_congelado_e_nas_colunas(): void
    {
        $this->osEntregue(0.0, 210.00);
        $this->lancar('vii', 90.00);

        $fechamento = app(AnexoXFechamentoService::class)->fechar(
            $this->servico()->apurar($this->competencia),
            (int) $this->usuario->id
        );

        $this->assertSame(300.00, round((float) $fechamento->linha_vii, 2), 'Congela o DECLARADO.');
        $this->assertSame(90.00, round((float) $fechamento->ajuste_total, 2));
        $this->assertSame(1, (int) $fechamento->ajuste_quantidade);

        $payload = $fechamento->payload();
        $this->assertSame(210.00, $payload['linhas']['vii']['calculado']);
        $this->assertSame(90.00, $payload['ajustes']['total']);
    }

    public function test_ajuste_fica_bloqueado_com_a_competencia_encerrada(): void
    {
        $this->osEntregue(0.0, 100.00);

        $this->assertFalse($this->servico()->apurar($this->competencia)['ajustes']['bloqueado']);

        app(AnexoXFechamentoService::class)->fechar(
            $this->servico()->apurar($this->competencia),
            (int) $this->usuario->id
        );

        $this->assertTrue(
            $this->servico()->apurar($this->competencia)['ajustes']['bloqueado'],
            'Mês encerrado não aceita ajuste — reabra antes.'
        );
    }

    public function test_acumulado_do_ano_inclui_o_ajuste_manual(): void
    {
        $this->osEntregue(0.0, 1000.00);
        $this->lancar('vii', 500.00);

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertSame(1500.00, $acumulado['acumulado'], 'O ajuste conta para o limite do MEI.');
    }

    // ------------------------------------------------------------ auxiliares

    private function vendaDeBalcao(float $mercadoria): int
    {
        $vendaId = $this->createSaleRecord([
            'numero' => 'VD-'.random_int(100000, 999999),
            'data_venda' => now()->startOfMonth()->addDays(7)->toDateString(),
            'subtotal' => $mercadoria,
            'total' => $mercadoria,
            'valor_pago' => $mercadoria,
        ]);

        DB::table('venda_itens')->insert([
            'venda_id' => $vendaId,
            'tipo_item' => 'peca',
            'descricao' => 'Item peca',
            'quantidade' => 1,
            'valor_unitario' => $mercadoria,
            'total' => $mercadoria,
            'custo_total' => 0,
            'baixa_estoque' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \App\Models\Financeiro::query()->create([
            'venda_id' => $vendaId,
            'avulso' => true,
            'tipo' => \App\Models\Financeiro::TIPO_RECEBER,
            'categoria' => 'Venda de balcão',
            'descricao' => 'Venda de balcão',
            'valor' => $mercadoria,
            'grupo_dre' => \App\Models\Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL,
            'status' => \App\Models\Financeiro::STATUS_PAGO,
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
        $titulo = \App\Models\Financeiro::query()->create([
            'tipo' => \App\Models\Financeiro::TIPO_PAGAR,
            'avulso' => true,
            'categoria' => 'Devolução de venda',
            'descricao' => 'Devolução da venda '.$vendaId,
            'valor' => $valor,
            'status' => \App\Models\Financeiro::STATUS_PAGO,
            'data_vencimento' => now()->startOfMonth()->addDays(9)->toDateString(),
            'data_competencia' => now()->startOfMonth()->addDays(9)->toDateString(),
            'origem_tipo' => \App\Models\Financeiro::ORIGEM_TIPO_VENDA_DEVOLUCAO,
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
    }
}
