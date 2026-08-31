<?php

namespace App\Services\Financeiro;

use App\Models\ComissaoTecnico;
use App\Models\Configuration;
use App\Models\Financeiro;
use App\Models\Movimentacao;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OsMargem;
use App\Support\RegimeTributario;
use Carbon\CarbonImmutable;
use RuntimeException;

class OsMargemService
{
    /**
     * Marcador das despesas de taxa criadas por
     * OrderClosureService::registerCardFeeExpense() na baixa da OS. E o custo
     * REAL cobrado pela operadora (ja com piso/teto aplicados), nao uma
     * estimativa por percentual — por isso e ele que entra na margem.
     */
    private const ORIGEM_TAXA_CARTAO = 'os_recebimento_cartao';

    /**
     * Percentual de imposto sobre a venda usado quando nao ha um lancamento
     * fiscal por OS. Cai no mesmo parametro que a precificacao ja usa no
     * divisor de preco, para que preco planejado e margem realizada partam da
     * mesma aliquota.
     */
    private const CHAVE_IMPOSTO = 'margem_imposto_percentual';

    private const CHAVE_IMPOSTO_FALLBACK = 'precificacao_servico_imposto_percentual';

    /**
     * Calcula (e grava em cache) a margem de contribuicao de uma OS.
     *
     * MC = receita - custos e despesas VARIAVEIS, sendo variaveis:
     *   - pecas aplicadas, ao custo real de estoque;
     *   - comissao do tecnico;
     *   - taxa de recebimento efetivamente cobrada pela operadora;
     *   - imposto sobre a venda.
     *
     * Custos fixos (aluguel, folha, energia) NAO entram aqui: e exatamente a
     * separacao que faz a MC responder "quanto sobra para pagar o fixo".
     *
     * @return array<string, mixed>
     */
    public function calcularParaOs(int $osId): array
    {
        $order = Order::query()->find($osId);

        if (! $order instanceof Order) {
            throw new RuntimeException('Ordem de serviço não encontrada.');
        }

        // Regra de projeto (skill sistema-erp-os-fluxo-fechamento): a analise de
        // margem so considera OS que geraram receita real, ou seja, encerradas
        // como OrderStatus::REVENUE_CLOSURE_CODE. Qualquer outro status
        // (incluindo devolvido sem reparo / descartado) nao entra no relatorio;
        // se existir um registro stale de um recalculo antigo, e removido aqui.
        if (trim((string) $order->status) !== OrderStatus::REVENUE_CLOSURE_CODE) {
            OsMargem::query()->where('os_id', $osId)->delete();

            return [];
        }

        $receitaLiquida = round((float) $order->valor_final, 2);
        $custoPecas = $this->custoPecasAplicadas($osId);
        $percentualComissao = $this->resolveComissaoPercentual($order->tecnico_id);
        $custoComissao = round($receitaLiquida * $percentualComissao / 100, 2);
        $custoTaxa = $this->custoTaxaRecebimento($osId);
        $custoImposto = round($receitaLiquida * $this->resolveImpostoPercentual() / 100, 2);

        $registro = OsMargem::query()->updateOrCreate(
            ['os_id' => $osId],
            $this->montarRegistro(
                $receitaLiquida,
                $custoPecas,
                $custoComissao,
                $custoTaxa,
                $custoImposto,
                $this->normalizarHoras($order->tempo_tecnico_horas)
            ) + ['calculado_em' => now()]
        );

        return $registro->toArray();
    }

    /**
     * @return int quantidade de OS recalculadas
     */
    public function recalcularEmLote(?CarbonImmutable $desde = null): int
    {
        // Invariante (skill sistema-erp-os-fluxo-fechamento): a tabela cache
        // os_margem so contem OS que geraram receita real (REVENUE_CLOSURE_CODE).
        // Remove aqui qualquer registro stale de OS que nao seja (ou deixou de
        // ser) uma entrega com receita — inclui devolvido/descartado/cancelado
        // e etapas intermediarias que nunca deveriam ter entrado no relatorio.
        OsMargem::query()
            ->whereNotIn('os_id', function ($sub): void {
                $sub->select('id')
                    ->from('os')
                    ->where('status', OrderStatus::REVENUE_CLOSURE_CODE);
            })
            ->delete();

        $query = Order::query()
            ->where('os.status', OrderStatus::REVENUE_CLOSURE_CODE);

        if ($desde !== null) {
            $query->where('os.data_entrega', '>=', $desde->toDateString());
        }

        $orders = $query->get(['os.id', 'os.valor_final', 'os.tecnico_id', 'os.tempo_tecnico_horas']);

        if ($orders->isEmpty()) {
            return 0;
        }

        $ids = $orders->pluck('id');
        $custosPecas = $this->custoPecasAplicadasEmLote($ids->all());
        $custosTaxa = $this->custoTaxaRecebimentoEmLote($ids->all());
        $comissaoPadrao = $this->resolveComissaoPadrao();
        $comissoesPorTecnico = $this->resolveComissoesPorTecnico(
            $orders->pluck('tecnico_id')->filter()->unique()->all()
        );
        $impostoPercentual = $this->resolveImpostoPercentual();

        $agora = now();
        $registros = $orders->map(function (Order $order) use (
            $custosPecas,
            $custosTaxa,
            $comissoesPorTecnico,
            $comissaoPadrao,
            $impostoPercentual,
            $agora
        ): array {
            $receitaLiquida = round((float) $order->valor_final, 2);
            $percentualComissao = $comissoesPorTecnico[$order->tecnico_id] ?? $comissaoPadrao;

            return ['os_id' => $order->id] + $this->montarRegistro(
                $receitaLiquida,
                $custosPecas[$order->id] ?? 0.0,
                round($receitaLiquida * $percentualComissao / 100, 2),
                $custosTaxa[$order->id] ?? 0.0,
                round($receitaLiquida * $impostoPercentual / 100, 2),
                $this->normalizarHoras($order->tempo_tecnico_horas)
            ) + ['calculado_em' => $agora];
        })->all();

        OsMargem::query()->upsert(
            $registros,
            ['os_id'],
            [
                'receita_liquida',
                'custo_pecas',
                'custo_comissao',
                'custo_taxa_recebimento',
                'custo_imposto',
                'margem_contribuicao',
                'percentual_margem',
                'tempo_tecnico_horas',
                'margem_por_hora',
                'calculado_em',
            ]
        );

        return $orders->count();
    }

    /**
     * Monta as colunas derivadas a partir dos componentes variaveis. Fica num
     * lugar so para que o calculo unitario e o de lote nao possam divergir.
     *
     * @return array<string, float|null>
     */
    private function montarRegistro(
        float $receitaLiquida,
        float $custoPecas,
        float $custoComissao,
        float $custoTaxa,
        float $custoImposto,
        ?float $horas
    ): array {
        $margem = round($receitaLiquida - $custoPecas - $custoComissao - $custoTaxa - $custoImposto, 2);

        return [
            'receita_liquida' => $receitaLiquida,
            'custo_pecas' => $custoPecas,
            'custo_comissao' => $custoComissao,
            'custo_taxa_recebimento' => $custoTaxa,
            'custo_imposto' => $custoImposto,
            'margem_contribuicao' => $margem,
            'percentual_margem' => $receitaLiquida > 0 ? round(($margem / $receitaLiquida) * 100, 2) : 0.0,
            'tempo_tecnico_horas' => $horas,
            'margem_por_hora' => $horas !== null && $horas > 0 ? round($margem / $horas, 2) : null,
        ];
    }

    private function normalizarHoras(mixed $horas): ?float
    {
        if ($horas === null || $horas === '') {
            return null;
        }

        $valor = round((float) $horas, 2);

        return $valor > 0 ? $valor : null;
    }

    /**
     * @param array<int, int> $osIds
     * @return array<int, float> custo de peças aplicadas, indexado por os_id
     */
    private function custoPecasAplicadasEmLote(array $osIds): array
    {
        if ($osIds === []) {
            return [];
        }

        return Movimentacao::query()
            ->join('pecas', 'pecas.id', '=', 'movimentacoes.peca_id')
            ->whereIn('movimentacoes.os_id', $osIds)
            ->where('movimentacoes.tipo', 'saida')
            ->selectRaw('movimentacoes.os_id as os_id, COALESCE(SUM(movimentacoes.quantidade * pecas.preco_custo), 0) as total')
            ->groupBy('movimentacoes.os_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->os_id => round((float) $row->total, 2)])
            ->all();
    }

    /**
     * @param array<int, int> $osIds
     * @return array<int, float> taxa de recebimento real, indexada por os_id
     */
    private function custoTaxaRecebimentoEmLote(array $osIds): array
    {
        if ($osIds === []) {
            return [];
        }

        return $this->baseTaxaRecebimento()
            ->whereIn('os_id', $osIds)
            ->selectRaw('os_id, COALESCE(SUM(valor), 0) as total')
            ->groupBy('os_id')
            ->get()
            ->mapWithKeys(static fn ($row): array => [(int) $row->os_id => round((float) $row->total, 2)])
            ->all();
    }

    private function custoTaxaRecebimento(int $osId): float
    {
        return round((float) $this->baseTaxaRecebimento()->where('os_id', $osId)->sum('valor'), 2);
    }

    /**
     * Despesas de taxa vinculadas a OS, ignorando as canceladas — um
     * cancelamento de lancamento estorna a taxa, e uma taxa estornada nao
     * pode continuar consumindo a margem.
     */
    private function baseTaxaRecebimento(): \Illuminate\Database\Eloquent\Builder
    {
        return Financeiro::query()
            ->where('tipo', Financeiro::TIPO_PAGAR)
            ->where('origem_tipo', self::ORIGEM_TAXA_CARTAO)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->whereNotNull('os_id');
    }

    /**
     * @param array<int, int> $tecnicoIds
     * @return array<int, float> percentual de comissao ativo, indexado por tecnico_id
     */
    private function resolveComissoesPorTecnico(array $tecnicoIds): array
    {
        if ($tecnicoIds === []) {
            return [];
        }

        return ComissaoTecnico::query()
            ->whereIn('tecnico_id', $tecnicoIds)
            ->where('ativo', true)
            ->pluck('percentual_padrao', 'tecnico_id')
            ->map(static fn ($percentual): float => (float) $percentual)
            ->all();
    }

    private function resolveComissaoPadrao(): float
    {
        $padrao = Configuration::query()
            ->where('chave', 'comissao_tecnico_percentual_padrao')
            ->value('valor');

        return $padrao !== null ? (float) $padrao : 0.0;
    }

    /**
     * Aliquota efetiva sobre a venda, ou 0 quando o regime nao tem imposto
     * proporcional ao faturamento.
     *
     * O MEI devolve 0 SEMPRE, mesmo que exista percentual configurado: o DAS
     * do MEI e um valor fixo mensal e nao muda com o volume de OS. Descontar
     * um valor fixo de cada venda subestimaria a margem unitaria e, pior,
     * deixaria essa despesa fora do ponto de equilibrio — que e exatamente
     * onde ela precisa estar. Ver App\Support\RegimeTributario.
     *
     * Fora do MEI, a chave propria permite que a apuracao real divirja do
     * parametro usado na precificacao, mas cai nele por padrao para nao exigir
     * configuracao duplicada.
     */
    public function resolveImpostoPercentual(): float
    {
        $regime = Configuration::query()->where('chave', RegimeTributario::CHAVE)->value('valor');

        if (! RegimeTributario::temImpostoVariavel($regime)) {
            return 0.0;
        }

        foreach ([self::CHAVE_IMPOSTO, self::CHAVE_IMPOSTO_FALLBACK] as $chave) {
            $valor = Configuration::query()->where('chave', $chave)->value('valor');

            if ($valor !== null && $valor !== '') {
                return max(0.0, (float) $valor);
            }
        }

        return 0.0;
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<string, mixed>
     */
    public function relatorioPorPeriodo(string $mes, array $filtros = []): array
    {
        [$inicio, $fim, $label] = $this->resolveMonthRange($mes);

        $query = OsMargem::query()
            ->join('os', 'os.id', '=', 'os_margem.os_id')
            ->whereBetween('os.data_entrega', [$inicio->toDateString(), $fim->toDateString()]);

        if (! empty($filtros['cliente_id'])) {
            $query->where('os.cliente_id', (int) $filtros['cliente_id']);
        }

        if (! empty($filtros['tecnico_id'])) {
            $query->where('os.tecnico_id', (int) $filtros['tecnico_id']);
        }

        $rows = $query->get([
            'os_margem.*',
            'os.numero_os',
            'os.cliente_id',
            'os.tecnico_id',
            'os.data_entrega',
        ]);

        $totalOs = $rows->count();
        $receitaTotal = round((float) $rows->sum('receita_liquida'), 2);
        $margemTotal = round((float) $rows->sum('margem_contribuicao'), 2);
        $ticketMedio = $totalOs > 0 ? round($receitaTotal / $totalOs, 2) : 0.0;

        $comHoras = $rows->filter(static fn ($row): bool => (float) ($row->tempo_tecnico_horas ?? 0) > 0);
        $horasTotais = round((float) $comHoras->sum('tempo_tecnico_horas'), 2);

        $porTecnico = [];
        foreach ($rows->groupBy('tecnico_id') as $tecnicoId => $grupo) {
            $receitaGrupo = round((float) $grupo->sum('receita_liquida'), 2);
            $margemGrupo = round((float) $grupo->sum('margem_contribuicao'), 2);

            // A margem por hora só pode confrontar a margem das OS que TÊM
            // apontamento com as horas dessas mesmas OS. Dividir a margem do
            // grupo inteiro pelas horas de uma parte dele atribuiria a margem
            // das OS sem apontamento ao tempo das que têm, inflando o
            // indicador — quanto pior a cobertura do apontamento, maior a
            // mentira.
            $grupoComHoras = $grupo->filter(static fn ($row): bool => (float) ($row->tempo_tecnico_horas ?? 0) > 0);
            $horasGrupo = round((float) $grupoComHoras->sum('tempo_tecnico_horas'), 2);
            $margemComHoras = round((float) $grupoComHoras->sum('margem_contribuicao'), 2);

            $porTecnico[] = [
                'tecnico_id' => $tecnicoId,
                'total_os' => $grupo->count(),
                'receita_total' => $receitaGrupo,
                'margem_media_percentual' => $this->indiceContribuicao($margemGrupo, $receitaGrupo),
                'margem_total' => $margemGrupo,
                'horas_totais' => $horasGrupo,
                'os_com_apontamento' => $grupoComHoras->count(),
                'margem_por_hora' => $horasGrupo > 0 ? round($margemComHoras / $horasGrupo, 2) : null,
            ];
        }

        $ordenadoPorMargem = $rows->sortBy('percentual_margem')->values();
        $ordenadoPorHora = $comHoras->sortByDesc('margem_por_hora')->values();

        return [
            'mes' => $mes,
            'periodo_label' => $label,
            'total_os' => $totalOs,
            'receita_total' => $receitaTotal,
            'ticket_medio' => $ticketMedio,
            // Indice de contribuicao do periodo (MC total / receita total). NAO
            // e a media dos percentuais das OS: num mix heterogeneo — uma
            // formatacao de R$ 80 a 90% ao lado de um reparo de R$ 1.200 a 20%
            // — a media simples devolve 55% onde a realidade e 24%.
            'margem_media_percentual' => $this->indiceContribuicao($margemTotal, $receitaTotal),
            'margem_total' => $margemTotal,
            'custos_variaveis' => [
                'pecas' => round((float) $rows->sum('custo_pecas'), 2),
                'comissao' => round((float) $rows->sum('custo_comissao'), 2),
                'taxa_recebimento' => round((float) $rows->sum('custo_taxa_recebimento'), 2),
                'imposto' => round((float) $rows->sum('custo_imposto'), 2),
                'total' => round($receitaTotal - $margemTotal, 2),
            ],
            'horas' => [
                'total' => $horasTotais,
                'os_com_apontamento' => $comHoras->count(),
                'os_sem_apontamento' => $totalOs - $comHoras->count(),
                'margem_por_hora' => $horasTotais > 0
                    ? round((float) $comHoras->sum('margem_contribuicao') / $horasTotais, 2)
                    : null,
            ],
            'por_tecnico' => $porTecnico,
            'piores_os' => $ordenadoPorMargem->take(5)->values()->all(),
            'melhores_os' => $ordenadoPorMargem->reverse()->take(5)->values()->all(),
            // Ranking pelo recurso restrito: quando a fila e maior que a
            // bancada, o que decide o mix e a MC por hora, nao a MC da OS.
            'melhores_por_hora' => $ordenadoPorHora->take(5)->values()->all(),
            'piores_por_hora' => $ordenadoPorHora->reverse()->take(5)->values()->all(),
        ];
    }

    /**
     * Totais consolidados do periodo, para o DRE gerencial montar a linha de
     * margem de contribuicao a partir da mesma fonte que a tela de margem —
     * evita que os dois relatorios contem custo de peca de formas diferentes.
     *
     * @return array<string, float|int>
     */
    public function totaisPorPeriodo(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $row = OsMargem::query()
            ->join('os', 'os.id', '=', 'os_margem.os_id')
            // Mesmo recorte de data que o DRE usa para reconhecer receita
            // (Order::REVENUE_DATE_SQL): filtrar so por data_entrega deixava de
            // fora as OS fechadas sem entrega registrada, e a margem cobriria um
            // conjunto de OS diferente do faturamento com que e confrontada.
            ->whereRaw(Order::REVENUE_DATE_SQL . ' BETWEEN ? AND ?', [$inicio->toDateString(), $fim->toDateString()])
            ->selectRaw(
                'COUNT(*) as total_os,'
                . ' COALESCE(SUM(os_margem.receita_liquida), 0) as receita,'
                . ' COALESCE(SUM(os_margem.custo_pecas), 0) as pecas,'
                . ' COALESCE(SUM(os_margem.custo_comissao), 0) as comissao,'
                . ' COALESCE(SUM(os_margem.custo_taxa_recebimento), 0) as taxa,'
                . ' COALESCE(SUM(os_margem.custo_imposto), 0) as imposto,'
                . ' COALESCE(SUM(os_margem.margem_contribuicao), 0) as margem,'
                . ' COALESCE(SUM(os_margem.tempo_tecnico_horas), 0) as horas'
            )
            ->first();

        return [
            'total_os' => (int) ($row->total_os ?? 0),
            'receita' => round((float) ($row->receita ?? 0), 2),
            'pecas' => round((float) ($row->pecas ?? 0), 2),
            'comissao' => round((float) ($row->comissao ?? 0), 2),
            'taxa_recebimento' => round((float) ($row->taxa ?? 0), 2),
            'imposto' => round((float) ($row->imposto ?? 0), 2),
            'margem' => round((float) ($row->margem ?? 0), 2),
            'horas' => round((float) ($row->horas ?? 0), 2),
        ];
    }

    private function indiceContribuicao(float $margem, float $receita): float
    {
        return $receita > 0 ? round(($margem / $receita) * 100, 2) : 0.0;
    }

    private function custoPecasAplicadas(int $osId): float
    {
        $total = Movimentacao::query()
            ->join('pecas', 'pecas.id', '=', 'movimentacoes.peca_id')
            ->where('movimentacoes.os_id', $osId)
            ->where('movimentacoes.tipo', 'saida')
            ->selectRaw('COALESCE(SUM(movimentacoes.quantidade * pecas.preco_custo), 0) as total')
            ->value('total');

        return round((float) $total, 2);
    }

    private function resolveComissaoPercentual(?int $tecnicoId): float
    {
        if ($tecnicoId !== null) {
            $percentual = ComissaoTecnico::query()
                ->where('tecnico_id', $tecnicoId)
                ->where('ativo', true)
                ->value('percentual_padrao');

            if ($percentual !== null) {
                return (float) $percentual;
            }
        }

        $padrao = Configuration::query()
            ->where('chave', 'comissao_tecnico_percentual_padrao')
            ->value('valor');

        return $padrao !== null ? (float) $padrao : 0.0;
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
}
