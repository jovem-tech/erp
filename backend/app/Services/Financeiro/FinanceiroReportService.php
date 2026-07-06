<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroMovimentoCartao;
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

        $saldoInicial = $this->netMovimentosAcumulado(null, $inicio->copy()->subDay());

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

        $entradaProjetadaPorDia = $this->projectedCashInByDay($inicio, $fim);
        $detalhesPorDia = $this->buildDailyDetails($inicio, $fim);

        // Saldo líquido: o que de fato está (ou vai estar) disponível na
        // conta — diferente do saldo realizado (que soma o bruto no dia da
        // venda/baixa). Acumula pelo dia em que o dinheiro pousa, já líquido
        // de taxa para cartão; ver netCashDeltaByLandingDay().
        $saldoLiquidoInicial = $this->netCashDeltaAcumulado($inicio->copy()->subDay());
        $netCashDeltaPorDia = $this->netCashDeltaByLandingDay($inicio, $fim);
        $saldoLiquidoAcumulado = $saldoLiquidoInicial;

        foreach ($linhasDiarias as &$linha) {
            $linha['entrada_projetada'] = round($entradaProjetadaPorDia[$linha['data']] ?? 0, 2);
            $linha['detalhes'] = $detalhesPorDia[$linha['data']] ?? ['movimentos' => [], 'previstos_para_hoje' => []];

            $saldoLiquidoAcumulado = round($saldoLiquidoAcumulado + ($netCashDeltaPorDia[$linha['data']] ?? 0), 2);
            $linha['saldo_liquido'] = $saldoLiquidoAcumulado;
        }
        unset($linha);

        $saldoFinal = round((float) $saldoInicial + $realizadosEntradas - $realizadosSaidas, 2);

        return [
            'mes' => $mes,
            'periodo_label' => $label,
            'saldo_inicial' => round((float) $saldoInicial, 2),
            'saldo_liquido_inicial' => round($saldoLiquidoInicial, 2),
            'saldo_liquido_final' => round($saldoLiquidoAcumulado, 2),
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
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
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
     * Mesma agregação de netMovimentos(), mas sem limite inferior de data —
     * usada para o saldo inicial, que precisa somar TODO o histórico antes
     * do período, não só o dia anterior. netMovimentos($tipo, $inicio-1dia)
     * (sem passar $end) colapsa para um intervalo de um único dia, porque
     * `$end ??= $start` — isso subestimava o saldo inicial de qualquer conta
     * com mais de um dia de movimentação anterior ao período do relatório.
     */
    private function netMovimentosAcumulado(?string $tipo, CarbonImmutable $ateData): float
    {
        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) <= ?', [$ateData->toDateString()]);

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
        // DATE(...) normaliza o agrupamento para o dia puro: a coluna é do
        // tipo `date`, mas alguns motores (SQLite, usado nos testes) não
        // truncam a hora se o valor gravado incluir "00:00:00" — sem
        // DATE(...), a chave do agrupamento vira "Y-m-d 00:00:00", que não
        // bate com o Carbon::toDateString() ("Y-m-d") usado logo abaixo pelo
        // cursor dia-a-dia, e a linha correspondente fica zerada.
        $movimentosPorDia = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) BETWEEN ? AND ?', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw("DATE(financeiro_movimentos.data_movimento) as dia, financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total")
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

    /**
     * Entradas de caixa agregadas pelo dia em que o dinheiro efetivamente
     * "pousa" na conta — diferente de "entradas_realizadas"/buildDailyRows()
     * (que agregam pelo dia da venda/baixa, data_movimento, e permanecem
     * inalterados). Para formas de pagamento imediatas (dinheiro, pix,
     * boleto, transferência, débito sem meta de cartão) o dia de pouso é o
     * próprio data_movimento; para cartão, é
     * COALESCE(data_credito_efetivo, data_prevista_recebimento), que pode
     * cair em outro mês — por isso o filtro de período da consulta de
     * cartão é sobre essa data calculada, nunca sobre data_movimento: uma
     * venda no fim do mês com repasse no mês seguinte deve aparecer aqui no
     * relatório do mês seguinte, não no mês da venda.
     *
     * @return array<string, float> chave = 'Y-m-d'
     */
    private function projectedCashInByDay(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        // DATE(...) normaliza a comparação/agrupamento para o dia puro: a
        // coluna é do tipo `date`, mas alguns motores (SQLite, usado nos
        // testes) não truncam a hora se o valor gravado incluir "00:00:00" —
        // sem DATE(...), o agrupamento gera uma chave "Y-m-d 00:00:00" que
        // não bate com o Carbon::toDateString() ("Y-m-d") usado como chave
        // pelo cursor dia-a-dia, e a linha correspondente fica sempre zerada.
        $imediato = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->leftJoin('financeiro_movimentos_cartao as fmc', 'fmc.movimento_id', '=', 'financeiro_movimentos.id')
            ->where('financeiro.tipo', Financeiro::TIPO_RECEBER)
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereNull('fmc.id')
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) BETWEEN ? AND ?', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw('DATE(financeiro_movimentos.data_movimento) as dia, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $viaCartao = FinanceiroMovimentoCartao::query()
            ->join('financeiro_movimentos', 'financeiro_movimentos.id', '=', 'financeiro_movimentos_cartao.movimento_id')
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', Financeiro::TIPO_RECEBER)
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw(
                'DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) BETWEEN ? AND ?',
                [$inicio->toDateString(), $fim->toDateString()]
            )
            ->selectRaw('DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) as dia, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total')
            ->groupBy('dia')
            ->pluck('total', 'dia');

        $porDia = [];
        foreach ($imediato as $dia => $valor) {
            $chave = (string) $dia;
            $porDia[$chave] = round(($porDia[$chave] ?? 0) + (float) $valor, 2);
        }
        foreach ($viaCartao as $dia => $valor) {
            $chave = (string) $dia;
            $porDia[$chave] = round(($porDia[$chave] ?? 0) + (float) $valor, 2);
        }

        return $porDia;
    }

    /**
     * Base da consulta de movimentos "imediatos" (sem cartão): usada tanto
     * para o saldo líquido por dia quanto para o acumulado antes do
     * período. Exclui explicitamente a despesa "Taxa de cartão" gerada
     * automaticamente (ver FinanceiroService::registerCardFeeExpense()) —
     * seu efeito já está embutido no valor líquido (valor_liquido) usado
     * para a venda em cartão que a originou; somar a taxa de novo aqui
     * dobraria o desconto dela no saldo líquido.
     */
    private function baseImediatoQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->leftJoin('financeiro_movimentos_cartao as fmc', 'fmc.movimento_id', '=', 'financeiro_movimentos.id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereNull('fmc.id')
            ->where(function ($q): void {
                $q->whereNull('financeiro.origem_tipo')
                    ->orWhere('financeiro.origem_tipo', '!=', 'financeiro_movimento_cartao');
            });
    }

    private function baseCartaoQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return FinanceiroMovimentoCartao::query()
            ->join('financeiro_movimentos', 'financeiro_movimentos.id', '=', 'financeiro_movimentos_cartao.movimento_id')
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true);
    }

    /**
     * @param \Illuminate\Support\Collection<int, mixed> $rows
     * @return array<string, float>
     */
    private function sumSignedByDay(\Illuminate\Support\Collection $rows): array
    {
        $porDia = [];
        foreach ($rows as $row) {
            $dia = (string) $row->dia;
            $sinal = $row->tipo === Financeiro::TIPO_RECEBER ? 1 : -1;
            $porDia[$dia] = round(($porDia[$dia] ?? 0) + $sinal * (float) $row->total, 2);
        }

        return $porDia;
    }

    /**
     * Saldo verdadeiramente em caixa: acumula pelo dia em que o dinheiro
     * efetivamente pousa na conta, já líquido de taxa para vendas em
     * cartão — diferente de "Saldo realizado" (soma o bruto no dia da
     * venda/baixa) e de "Entrada projetada" (também bruta, só
     * informativa).
     *
     * @return array<string, float> chave = 'Y-m-d', delta líquido do dia (pode ser negativo)
     */
    private function netCashDeltaByLandingDay(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $imediato = $this->baseImediatoQuery()
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) BETWEEN ? AND ?', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw('DATE(financeiro_movimentos.data_movimento) as dia, financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total')
            ->groupBy('dia', 'tipo')
            ->get();

        $viaCartao = $this->baseCartaoQuery()
            ->whereRaw(
                'DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) BETWEEN ? AND ?',
                [$inicio->toDateString(), $fim->toDateString()]
            )
            ->selectRaw(
                'DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) as dia, financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos_cartao.valor_liquido), 0) as total'
            )
            ->groupBy('dia', 'tipo')
            ->get();

        return $this->sumSignedByDay($imediato->concat($viaCartao));
    }

    /**
     * Mesma lógica de netCashDeltaByLandingDay(), sem limite inferior — para
     * o saldo líquido inicial, cumulativo desde sempre até uma data.
     */
    private function netCashDeltaAcumulado(CarbonImmutable $ateData): float
    {
        $imediato = $this->baseImediatoQuery()
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) <= ?', [$ateData->toDateString()])
            ->selectRaw('financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos.valor_movimento), 0) as total')
            ->groupBy('tipo')
            ->get();

        $viaCartao = $this->baseCartaoQuery()
            ->whereRaw(
                'DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) <= ?',
                [$ateData->toDateString()]
            )
            ->selectRaw('financeiro.tipo as tipo, COALESCE(SUM(financeiro_movimentos_cartao.valor_liquido), 0) as total')
            ->groupBy('tipo')
            ->get();

        $total = 0.0;
        foreach ($imediato->concat($viaCartao) as $row) {
            $sinal = $row->tipo === Financeiro::TIPO_RECEBER ? 1 : -1;
            $total += $sinal * (float) $row->total;
        }

        return round($total, 2);
    }

    /**
     * Detalhamento linha a linha por dia, para o modal de "Ações" do fluxo de
     * caixa: o que foi pago/recebido naquele dia (mesma base de
     * "entradas_realizadas"/"saidas_realizadas") e, separadamente, quais
     * entradas de cartão de OUTROS dias estão previstas para pousar na conta
     * justamente nesse dia (mesma base de projectedCashInByDay()). As duas
     * consultas rodam uma única vez por carregamento de página, com
     * eager-load, não em loop por dia — evita N+1 independente de quantos
     * modais o usuário abrir.
     *
     * @return array<string, array{movimentos: array<int, array<string, mixed>>, previstos_para_hoje: array<int, array<string, mixed>>}>
     */
    private function buildDailyDetails(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $movimentosDoDia = FinanceiroMovimento::query()
            ->with([
                'financeiro.client',
                'financeiro.supplier',
                'financeiro.origemMovimento.cartao.operadora',
                'financeiro.origemMovimento.cartao.bandeira',
                'cartao.operadora',
                'cartao.bandeira',
            ])
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw('DATE(financeiro_movimentos.data_movimento) BETWEEN ? AND ?', [$inicio->toDateString(), $fim->toDateString()])
            ->select('financeiro_movimentos.*')
            ->get()
            ->groupBy(fn (FinanceiroMovimento $m): string => $m->data_movimento->toDateString());

        $previstosParaHoje = FinanceiroMovimentoCartao::query()
            ->with(['movimento.financeiro.client', 'movimento.financeiro.supplier', 'operadora', 'bandeira'])
            ->join('financeiro_movimentos', 'financeiro_movimentos.id', '=', 'financeiro_movimentos_cartao.movimento_id')
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', Financeiro::TIPO_RECEBER)
            ->where('financeiro.impacta_fluxo_caixa', true)
            ->whereRaw(
                'DATE(COALESCE(financeiro_movimentos_cartao.data_credito_efetivo, financeiro_movimentos_cartao.data_prevista_recebimento, financeiro_movimentos.data_movimento)) BETWEEN ? AND ?',
                [$inicio->toDateString(), $fim->toDateString()]
            )
            ->select('financeiro_movimentos_cartao.*')
            ->get()
            ->groupBy(function (FinanceiroMovimentoCartao $c): string {
                $data = $c->data_credito_efetivo ?? $c->data_prevista_recebimento ?? $c->movimento->data_movimento;

                return $data->toDateString();
            });

        $resultado = [];
        $cursor = $inicio;

        while ($cursor->lte($fim)) {
            $diaKey = $cursor->toDateString();

            $resultado[$diaKey] = [
                'movimentos' => ($movimentosDoDia->get($diaKey) ?? collect())
                    ->map(fn (FinanceiroMovimento $m): array => $this->presentMovimento($m))
                    ->values()->all(),
                'previstos_para_hoje' => ($previstosParaHoje->get($diaKey) ?? collect())
                    ->map(fn (FinanceiroMovimentoCartao $c): array => $this->presentPrevisto($c))
                    ->values()->all(),
            ];

            $cursor = $cursor->addDay();
        }

        return $resultado;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMovimento(FinanceiroMovimento $m): array
    {
        $financeiro = $m->financeiro;

        // A taxa de cartão (ver FinanceiroService::registerCardFeeExpense())
        // não tem seu próprio financeiro_movimentos_cartao — ela é uma
        // despesa à parte, sem cartão de verdade envolvido. Mas o valor dela
        // só é efetivamente retido pela operadora no momento em que o
        // repasse da VENDA original acontece (a cobrança da taxa não é
        // imediata, ela acompanha a data prevista de caixa da venda que a
        // gerou) — por isso, para fins de exibição de "quando cai/sai da
        // conta", buscamos o cartão do movimento de origem (financeiro
        // .origem_id) em vez do cartão do próprio movimento da taxa.
        $cartao = $m->cartao
            ?? ($financeiro->origem_tipo === 'financeiro_movimento_cartao'
                ? $financeiro->origemMovimento?->cartao
                : null);

        return [
            'movimento_id' => $m->id,
            'tipo' => $financeiro->tipo,
            'origem' => $this->presentOrigem($financeiro),
            'contraparte' => $financeiro->tipo === Financeiro::TIPO_RECEBER
                ? $financeiro->client?->nome_razao
                : $financeiro->supplier?->nome_fantasia,
            'categoria' => $financeiro->categoria,
            'forma_pagamento' => $m->forma_pagamento,
            'valor' => round((float) $m->valor_movimento, 2),
            'data_movimento' => $m->data_movimento->toDateString(),
            'data_prevista_caixa' => $cartao
                ? ($cartao->data_credito_efetivo ?? $cartao->data_prevista_recebimento)?->toDateString()
                : null,
            'cartao' => $this->presentCartao($cartao),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPrevisto(FinanceiroMovimentoCartao $c): array
    {
        $financeiro = $c->movimento->financeiro;

        return [
            'movimento_id' => $c->movimento_id,
            'origem' => $this->presentOrigem($financeiro),
            'contraparte' => $financeiro->client?->nome_razao,
            'categoria' => $financeiro->categoria,
            'forma_pagamento' => $c->movimento->forma_pagamento,
            'valor' => round((float) $c->movimento->valor_movimento, 2),
            'data_venda' => $c->movimento->data_movimento->toDateString(),
            'cartao' => $this->presentCartao($c),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentCartao(?FinanceiroMovimentoCartao $cartao): ?array
    {
        if (! $cartao) {
            return null;
        }

        return [
            'operadora' => $cartao->operadora?->nome,
            'bandeira' => $cartao->bandeira?->nome,
            'modalidade' => $cartao->modalidade,
            'parcelas' => $cartao->parcelas,
            'taxa_percentual' => round((float) $cartao->taxa_percentual, 4),
            'taxa_fixa' => round((float) $cartao->taxa_fixa, 2),
            'valor_taxa' => round((float) $cartao->valor_taxa, 2),
            'valor_liquido' => round((float) $cartao->valor_liquido, 2),
            'prazo_recebimento_dias' => $cartao->prazo_recebimento_dias,
        ];
    }

    private function presentOrigem(Financeiro $financeiro): string
    {
        if ($financeiro->os_id) {
            return 'OS #' . $financeiro->os_id;
        }

        if ($financeiro->origem_tipo === 'financeiro_movimento_cartao') {
            return 'Taxa de cartão';
        }

        return $financeiro->avulso ? 'Avulso' : $financeiro->categoria;
    }
}
