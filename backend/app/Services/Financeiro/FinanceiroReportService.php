<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinanceiroReportService
{
    /**
     * DRE por competência: reconhece a receita de OS pela data de entrega e as
     * demais entradas/saídas pela data de competência do lançamento (mesmo
     * critério usado pelo legado em FinanceiroModel::getDreReport()).
     *
     * @return array<string, mixed>
     */
    public function dreReport(string $mes): array
    {
        [$inicio, $fim, $label] = $this->resolveMonthRange($mes);

        $os = Order::query()
            ->join('os_status', 'os_status.codigo', '=', 'os.status')
            ->where('os_status.status_final', true)
            ->whereBetween('os.data_entrega', [$inicio->startOfDay(), $fim->endOfDay()])
            ->selectRaw('COUNT(*) as total_os, COALESCE(SUM(os.valor_total), 0) as receita_bruta, COALESCE(SUM(os.desconto), 0) as descontos, COALESCE(SUM(os.valor_final), 0) as receita_liquida')
            ->first();

        $receitaOs = [
            'receita_bruta' => round((float) ($os->receita_bruta ?? 0), 2),
            'descontos' => round((float) ($os->descontos ?? 0), 2),
            'receita_liquida' => round((float) ($os->receita_liquida ?? 0), 2),
            'total_os' => (int) ($os->total_os ?? 0),
        ];

        $custosDiretos = $this->groupByCompetencia(Financeiro::TIPO_PAGAR, 'Custo Direto (OS)', $inicio, $fim);
        $outrasReceitas = $this->groupByCompetencia(Financeiro::TIPO_RECEBER, null, $inicio, $fim, excludeOs: true);
        $despesasOperacionais = $this->groupByCompetencia(Financeiro::TIPO_PAGAR, 'Despesas Operacionais', $inicio, $fim);

        return $this->buildSummary($label, 'competencia', $receitaOs, $custosDiretos, $outrasReceitas, $despesasOperacionais);
    }

    /**
     * DRE de caixa: reconhece cada valor pela data real do movimento de baixa,
     * não pela competência (mesma intenção do getDreCashReport() do legado).
     * Sem a tabela de liquidação de cartão (prevista para a fase de Cartões),
     * a data de realização usada é financeiro_movimentos.data_movimento.
     *
     * @return array<string, mixed>
     */
    public function dreCashReport(string $mes): array
    {
        [$inicio, $fim, $label] = $this->resolveMonthRange($mes);

        $receitaOs = $this->sumMovimentosResumo(Financeiro::TIPO_RECEBER, $inicio, $fim, onlyOs: true);
        $custosDiretos = $this->groupByMovimento(Financeiro::TIPO_PAGAR, 'Custo Direto (OS)', $inicio, $fim);
        $outrasReceitas = $this->groupByMovimento(Financeiro::TIPO_RECEBER, null, $inicio, $fim, excludeOs: true);
        $despesasOperacionais = $this->groupByMovimento(Financeiro::TIPO_PAGAR, 'Despesas Operacionais', $inicio, $fim);

        return $this->buildSummary($label, 'caixa', $receitaOs, $custosDiretos, $outrasReceitas, $despesasOperacionais);
    }

    /**
     * Fluxo de caixa diário: saldo inicial (movimentos antes do período),
     * realizados (movimentos dentro do período) e previstos (títulos
     * pendentes/parciais com vencimento no período).
     *
     * @return array<string, mixed>
     */
    public function cashFlowReport(string $mes): array
    {
        [$inicio, $fim, $label] = $this->resolveMonthRange($mes);

        $saldoInicial = $this->netMovimentos(null, $inicio->copy()->subDay());

        $realizadosEntradas = $this->netMovimentos(Financeiro::TIPO_RECEBER, $inicio, $fim, true);
        $realizadosSaidas = $this->netMovimentos(Financeiro::TIPO_PAGAR, $inicio, $fim, true);

        $previstos = Financeiro::query()
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL])
            ->where('impacta_fluxo_caixa', true)
            ->whereBetween('data_vencimento', [$inicio->toDateString(), $fim->toDateString()])
            ->get();

        $valorAbertoPorTitulo = $this->openAmountsByTitle($previstos->pluck('id')->all());

        $previstosEntradas = 0.0;
        $previstosSaidas = 0.0;
        $previstosPorCategoria = [];

        foreach ($previstos as $titulo) {
            $valorAberto = $valorAbertoPorTitulo[$titulo->id] ?? round((float) $titulo->valor, 2);
            if ($valorAberto <= 0) {
                continue;
            }

            if ($titulo->tipo === Financeiro::TIPO_RECEBER) {
                $previstosEntradas += $valorAberto;
            } else {
                $previstosSaidas += $valorAberto;
            }

            $chave = $titulo->subgrupo_dre ?? 'Sem subgrupo';
            $previstosPorCategoria[$chave] = round(($previstosPorCategoria[$chave] ?? 0) + $valorAberto, 2);
        }

        $linhasDiarias = $this->buildDailyRows($inicio, $fim, (float) $saldoInicial, $previstosEntradas - $previstosSaidas);

        $saldoFinal = round((float) $saldoInicial + $realizadosEntradas - $realizadosSaidas, 2);

        return [
            'mes' => $mes,
            'periodo_label' => $label,
            'saldo_inicial' => round((float) $saldoInicial, 2),
            'entradas_realizadas' => round($realizadosEntradas, 2),
            'saidas_realizadas' => round($realizadosSaidas, 2),
            'saldo_final' => $saldoFinal,
            'entradas_previstas' => round($previstosEntradas, 2),
            'saidas_previstas' => round($previstosSaidas, 2),
            'saldo_projetado' => round($saldoFinal + $previstosEntradas - $previstosSaidas, 2),
            'realizados_por_categoria' => $this->movimentosPorCategoria($inicio, $fim),
            'previstos_por_categoria' => $previstosPorCategoria,
            'linhas_diarias' => $linhasDiarias,
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function resolveMonthRange(string $mes): array
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', $mes) === 1 ? $mes : now()->format('Y-m');
        $inicio = CarbonImmutable::createFromFormat('Y-m-d', $mes . '-01')->startOfMonth();
        $fim = $inicio->endOfMonth();

        return [$inicio, $fim, $inicio->format('m/Y')];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupByCompetencia(string $tipo, ?string $grupoDre, CarbonImmutable $inicio, CarbonImmutable $fim, bool $excludeOs = false): array
    {
        $query = Financeiro::query()
            ->where('tipo', $tipo)
            ->where('impacta_dre', true);

        if ($grupoDre !== null) {
            $query->where('grupo_dre', $grupoDre);
        }

        if ($excludeOs) {
            $query->whereNull('os_id');
        }

        $dentroDoPeriodo = (clone $query)->where(function ($q) use ($inicio, $fim): void {
            $q->whereBetween('data_competencia', [$inicio->toDateString(), $fim->toDateString()])
                ->orWhere(function ($inner) use ($inicio, $fim): void {
                    $inner->whereNull('data_competencia')
                        ->whereBetween('data_vencimento', [$inicio->toDateString(), $fim->toDateString()]);
                });
        });

        $fixosMensais = (clone $query)
            ->where('dre_fixo_mensal', true)
            ->where('data_vencimento', '<=', $fim->toDateString());

        $rows = $dentroDoPeriodo->get()
            ->merge($fixosMensais->get())
            ->unique('id');

        return $this->summarizeRows($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupByMovimento(string $tipo, ?string $grupoDre, CarbonImmutable $inicio, CarbonImmutable $fim, bool $excludeOs = false): array
    {
        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', $tipo)
            ->where('financeiro.impacta_dre', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()]);

        if ($grupoDre !== null) {
            $query->where('financeiro.grupo_dre', $grupoDre);
        }

        if ($excludeOs) {
            $query->whereNull('financeiro.os_id');
        }

        $rows = $query->get([
            'financeiro_movimentos.valor_movimento as valor',
            'financeiro.subgrupo_dre as subgrupo_dre',
        ]);

        return $this->summarizeRows($rows, 'valor');
    }

    /**
     * @return array<string, mixed>
     */
    private function sumMovimentosResumo(string $tipo, CarbonImmutable $inicio, CarbonImmutable $fim, bool $onlyOs = false): array
    {
        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', $tipo)
            ->where('financeiro.impacta_dre', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()]);

        if ($onlyOs) {
            $query->whereNotNull('financeiro.os_id');
        }

        $total = (float) $query->sum('financeiro_movimentos.valor_movimento');

        return [
            'receita_bruta' => round($total, 2),
            'descontos' => 0.0,
            'receita_liquida' => round($total, 2),
            'total_os' => (int) (clone $query)->count(),
        ];
    }

    /**
     * @param Collection<int, mixed> $rows
     * @return array<string, mixed>
     */
    private function summarizeRows(Collection $rows, string $valueField = 'valor'): array
    {
        $porSubgrupo = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $valor = round((float) $row->{$valueField}, 2);
            $total += $valor;
            $chave = $row->subgrupo_dre ?? 'Sem subgrupo';
            $porSubgrupo[$chave] = round(($porSubgrupo[$chave] ?? 0) + $valor, 2);
        }

        return [
            'total' => round($total, 2),
            'por_subgrupo' => $porSubgrupo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(
        string $label,
        string $modo,
        array $receitaOs,
        array $custosDiretos,
        array $outrasReceitas,
        array $despesasOperacionais
    ): array {
        $custosDiretosTotal = (float) ($custosDiretos['total'] ?? 0);
        $outrasReceitasTotal = (float) ($outrasReceitas['total'] ?? 0);
        $despesasOperacionaisTotal = (float) ($despesasOperacionais['total'] ?? 0);

        $receitaLiquidaOs = (float) $receitaOs['receita_liquida'];
        $lucroBruto = round($receitaLiquidaOs - $custosDiretosTotal, 2);
        $resultadoLiquido = round($lucroBruto + $outrasReceitasTotal - $despesasOperacionaisTotal, 2);

        return [
            'periodo_label' => $label,
            'modo' => $modo,
            'receita' => $receitaOs,
            'custos_diretos' => $custosDiretos,
            'outras_receitas' => $outrasReceitas,
            'despesas_operacionais' => $despesasOperacionais,
            'lucro_bruto' => $lucroBruto,
            'resultado_liquido' => $resultadoLiquido,
        ];
    }

    private function netMovimentos(?string $tipo, CarbonImmutable $start, ?CarbonImmutable $end = null, bool $strict = false): float
    {
        $end ??= $start;

        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$start->toDateString(), $end->toDateString()]);

        if ($tipo !== null) {
            $query->where('financeiro.tipo', $tipo);

            return round((float) $query->sum('financeiro_movimentos.valor_movimento'), 2);
        }

        $entradas = (float) (clone $query)->where('financeiro.tipo', Financeiro::TIPO_RECEBER)->sum('financeiro_movimentos.valor_movimento');
        $saidas = (float) (clone $query)->where('financeiro.tipo', Financeiro::TIPO_PAGAR)->sum('financeiro_movimentos.valor_movimento');

        return round($entradas - $saidas, 2);
    }

    /**
     * @return array<string, float>
     */
    private function movimentosPorCategoria(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $rows = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()])
            ->get(['financeiro_movimentos.valor_movimento as valor', 'financeiro.subgrupo_dre as subgrupo_dre', 'financeiro.tipo as tipo']);

        $porCategoria = [];

        foreach ($rows as $row) {
            $sinal = $row->tipo === Financeiro::TIPO_RECEBER ? 1 : -1;
            $chave = $row->subgrupo_dre ?? 'Sem subgrupo';
            $porCategoria[$chave] = round(($porCategoria[$chave] ?? 0) + $sinal * (float) $row->valor, 2);
        }

        return $porCategoria;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, float>
     */
    private function openAmountsByTitle(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $titulos = Financeiro::query()->whereIn('id', $ids)->get(['id', 'valor']);
        $movimentado = FinanceiroMovimento::query()
            ->whereIn('financeiro_id', $ids)
            ->selectRaw('financeiro_id, COALESCE(SUM(valor_movimento), 0) as total')
            ->groupBy('financeiro_id')
            ->pluck('total', 'financeiro_id');

        $resultado = [];
        foreach ($titulos as $titulo) {
            $resultado[$titulo->id] = max(0, round((float) $titulo->valor - (float) ($movimentado[$titulo->id] ?? 0), 2));
        }

        return $resultado;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDailyRows(CarbonImmutable $inicio, CarbonImmutable $fim, float $saldoInicial, float $previstoNetoTotal): array
    {
        $movimentosPorDia = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("financeiro_movimentos.data_movimento as dia, financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total")
            ->groupBy('dia', 'tipo')
            ->get();

        $entradasPorDia = [];
        $saidasPorDia = [];

        foreach ($movimentosPorDia as $row) {
            $dia = (string) $row->dia;
            if ($row->tipo === Financeiro::TIPO_RECEBER) {
                $entradasPorDia[$dia] = (float) $row->total;
            } else {
                $saidasPorDia[$dia] = (float) $row->total;
            }
        }

        $linhas = [];
        $saldoAcumulado = $saldoInicial;
        $cursor = $inicio;

        while ($cursor->lte($fim)) {
            $diaKey = $cursor->toDateString();
            $entradasDia = round($entradasPorDia[$diaKey] ?? 0, 2);
            $saidasDia = round($saidasPorDia[$diaKey] ?? 0, 2);
            $saldoAcumulado = round($saldoAcumulado + $entradasDia - $saidasDia, 2);

            $linhas[] = [
                'data' => $diaKey,
                'entradas_realizadas' => $entradasDia,
                'saidas_realizadas' => $saidasDia,
                'saldo_realizado' => $saldoAcumulado,
            ];

            $cursor = $cursor->addDay();
        }

        if ($linhas !== []) {
            $ultimoIndice = count($linhas) - 1;
            $linhas[$ultimoIndice]['saldo_projetado'] = round($linhas[$ultimoIndice]['saldo_realizado'] + $previstoNetoTotal, 2);
        }

        return $linhas;
    }
}
