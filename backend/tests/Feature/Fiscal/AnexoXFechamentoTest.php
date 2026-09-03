<?php

namespace Tests\Feature\Fiscal;

use App\Models\AnexoXFechamento;
use App\Services\Fiscal\AnexoXFechamentoService;
use App\Services\Fiscal\AnexoXService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Fechamento mensal do Anexo X: congelar, reconferir e reabrir.
 */
class AnexoXFechamentoTest extends TestCase
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

    private function fechamentos(): AnexoXFechamentoService
    {
        return app(AnexoXFechamentoService::class);
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

    public function test_fechar_congela_os_valores_e_grava_hash(): void
    {
        $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $relatorio = $this->servico()->apurar($this->competencia);
        $fechamento = $this->fechamentos()->fechar($relatorio, (int) $usuario->id);

        $this->assertSame(1, (int) $fechamento->versao);
        $this->assertSame(AnexoXFechamento::STATUS_FECHADO, $fechamento->status);
        $this->assertSame(500.00, round((float) $fechamento->linha_x, 2));
        $this->assertSame(200.00, round((float) $fechamento->linha_iii, 2));
        $this->assertSame(64, strlen((string) $fechamento->payload_hash_sha256));
        $this->assertNotEmpty($fechamento->app_versao, 'A versão do ERP distingue mudança de dado de mudança de regra.');
    }

    /**
     * Um lançamento retroativo não pode mudar um relatório já assinado e
     * arquivado.
     */
    public function test_mes_fechado_nao_recalcula_mesmo_com_os_alterada_depois(): void
    {
        $osId = $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        DB::table('os')->where('id', $osId)->update([
            'valor_mao_obra' => 900.00,
            'valor_total' => 1100.00,
            'valor_final' => 1100.00,
        ]);

        $relatorio = $this->servico()->relatorio($this->competencia);

        $this->assertSame('fechamento', $relatorio['origem_dos_valores']);
        $this->assertSame(500.00, $relatorio['linhas']['x']['valor'], 'O mês fechado devolve o que foi declarado.');
    }

    public function test_reconferir_mostra_divergencia_quando_a_os_muda_depois_do_fechamento(): void
    {
        $osId = $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        DB::table('os')->where('id', $osId)->update([
            'valor_mao_obra' => 500.00,
            'valor_total' => 700.00,
            'valor_final' => 700.00,
        ]);

        $relatorio = $this->servico()->relatorio($this->competencia, AnexoXService::REGIME_COMPETENCIA, reconferir: true);

        $this->assertTrue($relatorio['fechamento']['reconferido']);
        $this->assertFalse($relatorio['fechamento']['confere']);

        $linhaX = collect($relatorio['fechamento']['divergencias'])->firstWhere('linha', 'x');

        $this->assertSame(500.00, $linhaX['congelado']);
        $this->assertSame(700.00, $linhaX['atual']);
        $this->assertSame(200.00, $linhaX['diferenca']);
    }

    public function test_sem_reconferir_o_mes_fechado_nao_recalcula_nada(): void
    {
        $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        $relatorio = $this->servico()->relatorio($this->competencia);

        $this->assertFalse($relatorio['fechamento']['reconferido']);
        $this->assertSame([], $relatorio['fechamento']['divergencias']);
    }

    /**
     * Reserializar o mesmo relatório não pode mudar o hash: `1500.0`, `1500` e
     * `"1500.00"` são o mesmo valor declarado.
     */
    public function test_hash_nao_muda_ao_reserializar_o_mesmo_payload(): void
    {
        $relatorio = ['competencia' => '2026-09', 'linhas' => ['x' => ['valor' => 1500.0]]];
        $equivalente = ['linhas' => ['x' => ['valor' => 1500]], 'competencia' => '2026-09'];

        $this->assertSame(
            $this->fechamentos()->hash($relatorio),
            $this->fechamentos()->hash($equivalente),
            'Ordem de chave e representação de número não podem mudar o hash.'
        );
    }

    public function test_hash_ignora_campos_volateis(): void
    {
        $base = ['competencia' => '2026-09', 'linhas' => ['x' => ['valor' => 10.0]]];

        $this->assertSame(
            $this->fechamentos()->hash($base),
            $this->fechamentos()->hash($base + ['gerado_em' => now()->toIso8601String(), 'origem_dos_valores' => 'ao_vivo']),
            'Reconferir o mesmo mês duas vezes não pode acusar adulteração inexistente.'
        );
    }

    public function test_hash_muda_quando_o_payload_e_adulterado_no_banco(): void
    {
        $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $fechamento = $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        $this->assertTrue($this->fechamentos()->apresentar($fechamento->refresh())['integro']);

        $payload = $fechamento->payload();
        $payload['linhas']['x']['valor'] = 50.00;

        DB::table('anexo_x_fechamentos')->where('id', $fechamento->id)->update([
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $this->assertFalse(
            $this->fechamentos()->apresentar($fechamento->refresh())['integro'],
            'Editar o JSON direto no banco tem que ser detectável.'
        );
    }

    public function test_fechar_depois_de_reabrir_grava_nova_versao(): void
    {
        $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        $reaberto = $this->fechamentos()->reabrir(
            $this->competencia,
            AnexoXService::REGIME_COMPETENCIA,
            (int) $usuario->id,
            'Lançamento de devolução esquecido no mês'
        );

        $this->assertSame(AnexoXFechamento::STATUS_REABERTO, $reaberto->status);
        $this->assertNull($this->fechamentos()->vigente($this->competencia, AnexoXService::REGIME_COMPETENCIA));

        $novo = $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);

        $this->assertSame(2, (int) $novo->versao);
        $this->assertSame(2, AnexoXFechamento::query()->count(), 'A primeira versão continua no banco como evidência.');
    }

    public function test_mes_reaberto_volta_a_calcular_ao_vivo_e_mostra_o_motivo(): void
    {
        $osId = $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar($this->servico()->apurar($this->competencia), (int) $usuario->id);
        $this->fechamentos()->reabrir(
            $this->competencia,
            AnexoXService::REGIME_COMPETENCIA,
            (int) $usuario->id,
            'Nota fiscal emitida com atraso'
        );

        DB::table('os')->where('id', $osId)->update([
            'valor_mao_obra' => 500.00,
            'valor_total' => 700.00,
            'valor_final' => 700.00,
        ]);

        $relatorio = $this->servico()->relatorio($this->competencia);

        $this->assertSame('ao_vivo', $relatorio['origem_dos_valores']);
        $this->assertSame(700.00, $relatorio['linhas']['x']['valor']);
        $this->assertSame('Nota fiscal emitida com atraso', $relatorio['fechamento']['motivo_reabertura']);
    }

    /**
     * São duas apurações diferentes da mesma receita, e o MEI declara por uma
     * delas. Fechar competência não pode congelar caixa.
     */
    public function test_competencia_e_caixa_fecham_de_forma_independente(): void
    {
        $this->osEntregue(200.00, 300.00);
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->fechamentos()->fechar(
            $this->servico()->apurar($this->competencia, AnexoXService::REGIME_COMPETENCIA),
            (int) $usuario->id
        );

        $this->assertNotNull($this->fechamentos()->vigente($this->competencia, AnexoXService::REGIME_COMPETENCIA));
        $this->assertNull($this->fechamentos()->vigente($this->competencia, AnexoXService::REGIME_CAIXA));

        $this->assertSame(
            'ao_vivo',
            $this->servico()->relatorio($this->competencia, AnexoXService::REGIME_CAIXA)['origem_dos_valores']
        );
    }

    public function test_reabrir_mes_que_nunca_foi_fechado_nao_faz_nada(): void
    {
        $usuario = $this->createUserRecord(['grupo_id' => 1]);

        $this->assertNull($this->fechamentos()->reabrir(
            $this->competencia,
            AnexoXService::REGIME_COMPETENCIA,
            (int) $usuario->id,
            'Motivo qualquer suficientemente longo'
        ));
    }

    public function test_acumulado_do_ano_usa_o_valor_congelado_dos_meses_ja_fechados(): void
    {
        $usuario = $this->createUserRecord(['grupo_id' => 1]);
        $mesAnterior = now()->startOfMonth()->subMonth()->format('Y-m');

        // Mês anterior fechado declarando R$ 1.000, sem nenhum dado de origem
        // que sustente esse número hoje.
        $this->createAnexoXFechamentoRecord([
            'competencia' => $mesAnterior,
            'regime' => AnexoXService::REGIME_COMPETENCIA,
            'linha_x' => 1000.00,
            'fechado_por' => (int) $usuario->id,
        ]);

        $this->osEntregue(0.0, 500.00);

        $acumulado = $this->servico()->apurar($this->competencia)['acumulado_ano'];

        $this->assertContains($mesAnterior, $acumulado['meses_fechados']);
        $this->assertSame(1000.00, $acumulado['por_mes'][$mesAnterior]);
        $this->assertSame(1500.00, $acumulado['acumulado'], 'O limite se confere contra o que foi declarado.');
    }
}
