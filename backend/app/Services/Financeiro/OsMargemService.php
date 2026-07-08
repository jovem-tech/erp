<?php

namespace App\Services\Financeiro;

use App\Models\ComissaoTecnico;
use App\Models\Configuration;
use App\Models\Movimentacao;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\OsMargem;
use Carbon\CarbonImmutable;
use RuntimeException;

class OsMargemService
{
    /**
     * Calcula (e grava em cache) a margem de contribuição de uma OS:
     * receita líquida - custo real de peças aplicadas (estoque) - comissão do técnico.
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
        $margem = round($receitaLiquida - $custoPecas - $custoComissao, 2);
        $percentualMargem = $receitaLiquida > 0 ? round(($margem / $receitaLiquida) * 100, 2) : 0.0;

        $registro = OsMargem::query()->updateOrCreate(
            ['os_id' => $osId],
            [
                'receita_liquida' => $receitaLiquida,
                'custo_pecas' => $custoPecas,
                'custo_comissao' => $custoComissao,
                'margem_contribuicao' => $margem,
                'percentual_margem' => $percentualMargem,
                'calculado_em' => now(),
            ]
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

        $orders = $query->get(['os.id', 'os.valor_final', 'os.tecnico_id']);

        if ($orders->isEmpty()) {
            return 0;
        }

        $ids = $orders->pluck('id');
        $custosPecas = $this->custoPecasAplicadasEmLote($ids->all());
        $comissaoPadrao = $this->resolveComissaoPadrao();
        $comissoesPorTecnico = $this->resolveComissoesPorTecnico(
            $orders->pluck('tecnico_id')->filter()->unique()->all()
        );

        $agora = now();
        $registros = $orders->map(function (Order $order) use ($custosPecas, $comissoesPorTecnico, $comissaoPadrao, $agora): array {
            $receitaLiquida = round((float) $order->valor_final, 2);
            $custoPecas = $custosPecas[$order->id] ?? 0.0;
            $percentualComissao = $comissoesPorTecnico[$order->tecnico_id] ?? $comissaoPadrao;
            $custoComissao = round($receitaLiquida * $percentualComissao / 100, 2);
            $margem = round($receitaLiquida - $custoPecas - $custoComissao, 2);
            $percentualMargem = $receitaLiquida > 0 ? round(($margem / $receitaLiquida) * 100, 2) : 0.0;

            return [
                'os_id' => $order->id,
                'receita_liquida' => $receitaLiquida,
                'custo_pecas' => $custoPecas,
                'custo_comissao' => $custoComissao,
                'margem_contribuicao' => $margem,
                'percentual_margem' => $percentualMargem,
                'calculado_em' => $agora,
            ];
        })->all();

        OsMargem::query()->upsert(
            $registros,
            ['os_id'],
            ['receita_liquida', 'custo_pecas', 'custo_comissao', 'margem_contribuicao', 'percentual_margem', 'calculado_em']
        );

        return $orders->count();
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
        $ticketMedio = $totalOs > 0 ? round((float) $rows->avg('receita_liquida'), 2) : 0.0;
        $margemMediaPercentual = $totalOs > 0 ? round((float) $rows->avg('percentual_margem'), 2) : 0.0;
        $margemTotal = round((float) $rows->sum('margem_contribuicao'), 2);

        $porTecnico = [];
        foreach ($rows->groupBy('tecnico_id') as $tecnicoId => $grupo) {
            $porTecnico[] = [
                'tecnico_id' => $tecnicoId,
                'total_os' => $grupo->count(),
                'margem_media_percentual' => round((float) $grupo->avg('percentual_margem'), 2),
                'margem_total' => round((float) $grupo->sum('margem_contribuicao'), 2),
            ];
        }

        $ordenadoPorMargem = $rows->sortBy('percentual_margem')->values();

        return [
            'mes' => $mes,
            'periodo_label' => $label,
            'total_os' => $totalOs,
            'ticket_medio' => $ticketMedio,
            'margem_media_percentual' => $margemMediaPercentual,
            'margem_total' => $margemTotal,
            'por_tecnico' => $porTecnico,
            'piores_os' => $ordenadoPorMargem->take(5)->values()->all(),
            'melhores_os' => $ordenadoPorMargem->reverse()->take(5)->values()->all(),
        ];
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
