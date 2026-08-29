<?php

namespace Tests\Feature\Api\V1;

use App\Services\Financeiro\CustoHoraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Custo da hora produtiva — specs/037, Fase 3.
 *
 * As bordas importam mais que o caminho feliz: um custo-hora errado contamina
 * o preco de todo servico, e o modo de falha mais perigoso e devolver ZERO —
 * que faz cada servico parecer infinitamente lucrativo.
 */
class PrecificacaoCustoHoraTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
    }

    private function configurar(array $valores): void
    {
        foreach ($valores as $chave => $valor) {
            DB::table('configuracoes')->updateOrInsert(['chave' => $chave], ['valor' => (string) $valor]);
        }
    }

    private function lancarCustoFixo(float $valor, int $mesesAtras): void
    {
        $data = now()->startOfMonth()->subMonths($mesesAtras)->addDays(4);

        DB::table('financeiro')->insert([
            'tipo' => 'pagar',
            'avulso' => 1,
            'categoria' => 'Aluguel',
            'descricao' => 'Aluguel',
            'valor' => $valor,
            'status' => 'pendente',
            'data_vencimento' => $data,
            'data_competencia' => $data,
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Aluguel',
            'impacta_dre' => 1,
            'impacta_fluxo_caixa' => 1,
            'dre_fixo_mensal' => 1,
        ]);
    }

    public function test_calcula_a_partir_dos_custos_fixos_e_da_capacidade(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 1,
            'precificacao_capacidade_horas_dia' => 6,
            'precificacao_capacidade_dias_mes' => 22,
            'precificacao_custo_hora_meses_base' => 3,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        // R$ 3.000 em cada um dos 3 meses fechados anteriores.
        foreach ([1, 2, 3] as $mes) {
            $this->lancarCustoFixo(3000.00, $mes);
        }

        $resultado = app(CustoHoraService::class)->resolver();

        // 3.000 / (1 × 6 × 22 = 132 h) = 22,73
        $this->assertSame(CustoHoraService::ORIGEM_CALCULADO, $resultado['origem']);
        $this->assertEqualsWithDelta(22.73, $resultado['valor'], 0.01);
        $this->assertEqualsWithDelta(132.0, $resultado['base']['horas_totais'], 0.01);
    }

    /**
     * O mes corrente esta sempre incompleto: incluí-lo faria o custo-hora
     * despencar no dia 3 e dobrar no dia 28, conforme aluguel e folha caem.
     */
    public function test_ignora_o_mes_corrente(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 1,
            'precificacao_capacidade_horas_dia' => 10,
            'precificacao_capacidade_dias_mes' => 10,
            'precificacao_custo_hora_meses_base' => 1,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        $this->lancarCustoFixo(1000.00, 1);
        // Este NAO pode entrar na conta.
        $this->lancarCustoFixo(9999.00, 0);

        $resultado = app(CustoHoraService::class)->resolver();

        // Só os R$ 1.000 do mês fechado: 1.000 / 100 h = 10,00
        $this->assertEqualsWithDelta(10.00, $resultado['valor'], 0.01);
    }

    public function test_sem_capacidade_configurada_cai_no_manual(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 0,
            'precificacao_capacidade_horas_dia' => 6,
            'precificacao_capacidade_dias_mes' => 22,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        $resultado = app(CustoHoraService::class)->resolver();

        $this->assertSame(CustoHoraService::ORIGEM_MANUAL, $resultado['origem']);
        $this->assertSame(CustoHoraService::MOTIVO_CAPACIDADE, $resultado['motivo']);
        $this->assertFalse($resultado['confiavel']);
        $this->assertEqualsWithDelta(40.00, $resultado['valor'], 0.01);
    }

    /**
     * A borda mais perigosa do serviço inteiro. Zero faria todo serviço
     * parecer infinitamente lucrativo, e o semáforo pintaria tudo de verde.
     */
    public function test_sem_custo_fixo_lancado_nunca_devolve_zero(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 1,
            'precificacao_capacidade_horas_dia' => 6,
            'precificacao_capacidade_dias_mes' => 22,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        $resultado = app(CustoHoraService::class)->resolver();

        $this->assertGreaterThan(0, $resultado['valor']);
        $this->assertSame(CustoHoraService::MOTIVO_SEM_FIXO, $resultado['motivo']);
        $this->assertSame(CustoHoraService::ORIGEM_MANUAL, $resultado['origem']);
        $this->assertFalse($resultado['confiavel']);
    }

    /**
     * Resultado absurdo em relacao ao manual: devolve o CALCULADO (ele pode
     * estar certo e o manual desatualizado), mas marcado para a tela pedir
     * conferencia. Trocar o numero em silencio e o que faz o usuario deixar de
     * confiar no recurso.
     */
    public function test_fora_da_faixa_esperada_devolve_o_calculado_mas_avisa(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 1,
            'precificacao_capacidade_horas_dia' => 1,
            'precificacao_capacidade_dias_mes' => 1,
            'precificacao_custo_hora_meses_base' => 1,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        // 5.000 / 1 h = 5.000/h — muito acima de 5 × 40.
        $this->lancarCustoFixo(5000.00, 1);

        $resultado = app(CustoHoraService::class)->resolver();

        $this->assertSame(CustoHoraService::ORIGEM_CALCULADO, $resultado['origem']);
        $this->assertEqualsWithDelta(5000.00, $resultado['valor'], 0.01);
        $this->assertSame(CustoHoraService::MOTIVO_FORA_DA_FAIXA, $resultado['motivo']);
        $this->assertFalse($resultado['confiavel']);
    }

    /**
     * Custo VARIAVEL nao entra no custo-hora: ele ja e descontado item a item
     * na margem de contribuicao. Somar aqui contaria duas vezes.
     */
    public function test_despesa_variavel_nao_entra_no_custo_hora(): void
    {
        $this->configurar([
            'precificacao_capacidade_tecnicos' => 1,
            'precificacao_capacidade_horas_dia' => 10,
            'precificacao_capacidade_dias_mes' => 10,
            'precificacao_custo_hora_meses_base' => 1,
            'precificacao_servico_custo_hora_produtiva' => 40,
        ]);

        $this->lancarCustoFixo(1000.00, 1);

        $data = now()->startOfMonth()->subMonth()->addDays(4);
        DB::table('financeiro')->insert([
            'tipo' => 'pagar', 'avulso' => 1, 'categoria' => 'Taxas',
            'descricao' => 'Taxa de cartão', 'valor' => 5000.00, 'status' => 'pendente',
            'data_vencimento' => $data, 'data_competencia' => $data,
            'grupo_dre' => 'Despesas Operacionais', 'subgrupo_dre' => 'Taxas e impostos',
            'impacta_dre' => 1, 'impacta_fluxo_caixa' => 1,
            'dre_fixo_mensal' => 0,
        ]);

        $resultado = app(CustoHoraService::class)->resolver();

        $this->assertEqualsWithDelta(10.00, $resultado['valor'], 0.01);
    }
}
