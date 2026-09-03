<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Services\Fiscal\AnexoXAjusteService;
use App\Services\Fiscal\AnexoXFechamentoService;
use App\Services\Fiscal\AnexoXService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Resumo anual — os doze meses nos dois regimes, que alimentam a tabela do ano.
 */
class AnexoXResumoAnualTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private int $ano;

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
        $this->ano = (int) now()->format('Y');
        $this->usuario = $this->createUserRecord(['grupo_id' => 1]);
    }

    private function servico(): AnexoXService
    {
        return app(AnexoXService::class);
    }

    /**
     * OS entregue num mês específico do ano corrente.
     */
    private function osNoMes(int $mes, float $pecas, float $maoObra): int
    {
        $data = now()->setDate($this->ano, $mes, 10)->startOfDay();

        return $this->createOrderRecord([
            'cliente_id' => $this->clienteId,
            'equipamento_id' => $this->equipamentoId,
            'status' => 'entregue_reparado_pago',
            'data_conclusao' => $data,
            'data_entrega' => $data,
            'valor_pecas' => $pecas,
            'valor_mao_obra' => $maoObra,
            'valor_total' => $pecas + $maoObra,
            'desconto' => 0,
            'valor_final' => $pecas + $maoObra,
        ]);
    }

    public function test_resumo_devolve_sempre_doze_meses_mesmo_em_ano_sem_movimento(): void
    {
        $resumo = $this->servico()->resumoAnual($this->ano + 5);

        $this->assertCount(12, $resumo['meses']);

        foreach ($resumo['meses'] as $indice => $mes) {
            $this->assertSame($indice + 1, $mes['mes']);
            $this->assertSame(sprintf('%04d-%02d', $this->ano + 5, $indice + 1), $mes['competencia']);
            $this->assertTrue($mes['futuro']);
            $this->assertSame(0.0, $mes['regimes']['competencia']['total']);
        }
    }

    /**
     * O guard central: a tabela do ano e o modal do mês têm que mostrar
     * exatamente o mesmo número. Se divergirem, o usuário perde a confiança no
     * relatório fiscal inteiro e não tem como saber qual dos dois está certo.
     */
    public function test_resumo_de_cada_mes_e_identico_ao_apurar_do_mesmo_mes(): void
    {
        $this->osNoMes(3, 100.00, 200.00);
        $this->osNoMes(7, 50.00, 150.00);
        $this->osNoMes(7, 0.0, 80.00);

        $resumo = $this->servico()->resumoAnual($this->ano);

        foreach ($resumo['meses'] as $mes) {
            foreach (AnexoXService::regimes() as $regime) {
                $aoVivo = $this->servico()->apurar($mes['competencia'], $regime, incluirAcumulado: false);

                foreach (array_keys($aoVivo['linhas']) as $linha) {
                    $this->assertSame(
                        $aoVivo['linhas'][$linha]['valor'],
                        $mes['regimes'][$regime]['linhas'][$linha]['valor'],
                        "Resumo e apuração discordam em {$mes['competencia']} ({$regime}), linha {$linha}."
                    );
                }
            }
        }
    }

    public function test_mes_encerrado_no_resumo_vem_do_valor_congelado(): void
    {
        $osId = $this->osNoMes(4, 0.0, 300.00);
        $competencia = sprintf('%04d-04', $this->ano);

        app(AnexoXFechamentoService::class)->fechar(
            $this->servico()->apurar($competencia),
            (int) $this->usuario->id
        );

        DB::table('os')->where('id', $osId)->update([
            'valor_mao_obra' => 900.00,
            'valor_total' => 900.00,
            'valor_final' => 900.00,
        ]);

        $mes = $this->servico()->resumoAnual($this->ano)['meses'][3];

        $this->assertSame('fechamento', $mes['regimes']['competencia']['origem_dos_valores']);
        $this->assertSame(300.00, $mes['regimes']['competencia']['total'], 'A tabela mostra o que foi declarado.');
        $this->assertSame(1, $mes['regimes']['competencia']['fechamento']['versao']);
        $this->assertNull(
            $mes['regimes']['competencia']['fechamento']['integro'],
            'A integridade não é conferida na tabela — é ação explícita de um mês só.'
        );
    }

    /**
     * O resumo carrega o que a TABELA precisa e nada do que só o modal usa. A
     * distinção importa: `sem_documento` aqui é a COLUNA (I+IV+VII, um número),
     * não a lista de operações que `apurar()` devolve com o mesmo nome — essa
     * fica para o modal, sob demanda.
     */
    public function test_resumo_traz_as_colunas_da_tabela_e_nao_o_detalhe_do_mes(): void
    {
        $this->osNoMes(2, 100.00, 100.00);

        $mes = $this->servico()->resumoAnual($this->ano)['meses'][1]['regimes']['competencia'];

        $this->assertArrayNotHasKey('drill_down', $mes, 'O drill-down é caro e só o modal usa.');
        $this->assertArrayNotHasKey('origens', $mes);
        $this->assertArrayNotHasKey('acumulado_ano', $mes);

        $this->assertIsFloat($mes['sem_documento'], 'Aqui `sem_documento` é a coluna, não a lista.');
        $this->assertArrayHasKey('linhas', $mes, 'As linhas vêm, para o modal não precisar de fetch.');
    }

    public function test_colunas_com_doc_e_sem_doc_sao_a_soma_das_linhas_certas(): void
    {
        $this->osNoMes(5, 100.00, 200.00);

        $mes = $this->servico()->resumoAnual($this->ano)['meses'][4]['regimes']['competencia'];
        $l = $mes['linhas'];

        $this->assertSame(
            round($l['ii']['valor'] + $l['v']['valor'] + $l['viii']['valor'], 2),
            $mes['com_documento']
        );
        $this->assertSame(
            round($l['i']['valor'] + $l['iv']['valor'] + $l['vii']['valor'], 2),
            $mes['sem_documento']
        );
        $this->assertSame(round($mes['com_documento'] + $mes['sem_documento'], 2), $mes['total']);
        $this->assertSame(round($mes['comercio'] + $mes['industria'] + $mes['servicos'], 2), $mes['total']);
    }

    public function test_acumulado_do_resumo_bate_com_a_soma_dos_meses(): void
    {
        $this->osNoMes(1, 0.0, 500.00);
        $this->osNoMes(2, 0.0, 300.00);

        $resumo = $this->servico()->resumoAnual($this->ano);

        $this->assertSame(800.00, $resumo['acumulado']['competencia']['acumulado']);
        $this->assertSame(800.00, $resumo['totais']['competencia']['total']);
    }

    public function test_grafico_traz_os_dois_regimes_com_doze_pontos_cada(): void
    {
        $this->osNoMes(6, 0.0, 400.00);

        $grafico = $this->servico()->resumoAnual($this->ano)['grafico'];

        foreach (['competencia', 'caixa'] as $regime) {
            foreach (['bruto', 'com_documento', 'sem_documento', 'ajuste'] as $serie) {
                $this->assertCount(12, $grafico['regimes'][$regime][$serie], "{$regime}.{$serie}");
            }
        }

        $this->assertSame(400.00, $grafico['regimes']['competencia']['bruto'][5]);
        $this->assertSame(81000.00, $grafico['limite']['anual']);
        $this->assertSame(6750.00, $grafico['limite']['mensal_medio']);
    }

    public function test_mostrar_industria_e_falso_quando_todo_vi_e_zero(): void
    {
        $this->osNoMes(3, 100.00, 100.00);

        $this->assertFalse($this->servico()->resumoAnual($this->ano)['mostrar_industria']);
    }

    public function test_resumo_marca_o_regime_que_conta_para_o_limite(): void
    {
        $resumo = $this->servico()->resumoAnual($this->ano);

        $this->assertSame(
            AnexoXService::REGIME_COMPETENCIA,
            $resumo['regime_que_conta_para_o_limite'],
            'O limite do MEI é sobre a receita bruta AUFERIDA — regime de competência.'
        );
    }

    public function test_resumo_inclui_o_ajuste_manual_do_mes(): void
    {
        $this->osNoMes(8, 0.0, 100.00);

        app(AnexoXAjusteService::class)->lancar(
            sprintf('%04d-08', $this->ano),
            AnexoXService::REGIME_COMPETENCIA,
            'vii',
            50.00,
            'Serviço cobrado fora do sistema',
            (int) $this->usuario->id
        );

        $mes = $this->servico()->resumoAnual($this->ano)['meses'][7]['regimes']['competencia'];

        $this->assertSame(150.00, $mes['total']);
        $this->assertSame(50.00, $mes['ajuste_total']);
        $this->assertSame(1, $mes['ajuste_quantidade']);
    }

    /**
     * O risco real desta tela não é volume, é N+1: o custo tem que crescer com
     * os doze meses, nunca com o número de operações dentro deles.
     */
    public function test_consultas_do_resumo_nao_crescem_com_o_numero_de_operacoes(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->osNoMes(2, 10.00, 20.00);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->servico()->resumoAnual($this->ano);
        $comTresOperacoes = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 0; $i < 12; $i++) {
            $this->osNoMes(2, 10.00, 20.00);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->servico()->resumoAnual($this->ano);
        $comQuinzeOperacoes = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $comTresOperacoes,
            $comQuinzeOperacoes,
            'O resumo virou N+1: o número de consultas passou a depender do volume de operações.'
        );
    }

    public function test_resumo_anual_nao_passa_do_orcamento_de_consultas(): void
    {
        foreach (range(1, 12) as $mes) {
            $this->osNoMes($mes, 10.00, 20.00);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->servico()->resumoAnual($this->ano);
        $consultas = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            220,
            $consultas,
            "O resumo anual gastou {$consultas} consultas — o orçamento é ~184 com o ano todo aberto."
        );
    }
}
