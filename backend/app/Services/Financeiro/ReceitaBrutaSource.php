<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fonte única das linhas que compõem a receita bruta de um mês.
 *
 * Nasceu de uma exigência do Anexo X (relatório mensal das receitas brutas do
 * MEI): o total declarado ao fisco tem que bater, centavo a centavo, com a
 * receita que o DRE mostra para o mesmo mês e o mesmo regime. Dois números
 * diferentes para o mesmo faturamento não são um detalhe de tela — um deles
 * está errado, e o usuário não tem como saber qual.
 *
 * Essa garantia só é honesta se os dois relatórios lerem **as mesmas linhas**,
 * não predicados parecidos escritos duas vezes. E os predicados daqui têm
 * sutilezas que uma reescrita "equivalente" não acerta por acaso:
 *
 *  - `linhasPorCompetencia()` faz merge dos títulos `dre_fixo_mensal` vencidos
 *    até o fim do período, que estão fora da janela de competência;
 *  - `queryMovimentos()` NÃO filtra `tipo_movimento`, então estorno entra na
 *    receita de caixa (comportamento herdado — ver nota abaixo);
 *  - `excludeOs` traz de volta quem tem `venda_id`, porque
 *    `SalePaymentService::createReceivable()` grava `os_id` no título da VENDA
 *    quando ela está vinculada a uma OS, e um `whereNull('os_id')` seco a
 *    faria sumir do faturamento sem aparecer do outro lado.
 *
 * Por isso esta classe guarda a **construção das queries**, e quem chama faz a
 * sumarização que precisa: o DRE agrega
 * (`FinanceiroReportService::summarizeRows()`), o Anexo X percorre linha a
 * linha para separar mercadoria de serviço. Uma definição, dois consumos.
 *
 * **Imprecisão herdada, registrada de propósito:** `queryMovimentos()` não
 * filtra `tipo_movimento`, então um estorno entra na receita de caixa. Isso já
 * era assim no DRE antes desta extração. Consertar só aqui quebraria a
 * igualdade que a classe existe para garantir — se for para corrigir, corrige
 * no comportamento e os dois relatórios se movem juntos.
 */
class ReceitaBrutaSource
{
    /**
     * OS cuja receita é reconhecida no período.
     *
     * Devolve o Builder, e não linhas prontas, porque os dois consumidores
     * precisam de coisas diferentes da mesma seleção: o DRE agrega com
     * `selectRaw`, o Anexo X lê `valor_pecas`/`valor_mao_obra` de cada OS para
     * separar mercadoria de serviço.
     *
     * O predicado nunca é redigitado: vem de `Order::scopeReceitaReconhecida()`
     * (só `OrderStatus::REVENUE_CLOSURE_CODE` gera receita — entregue sem custo
     * e garantia são contagem operacional) e de `Order::REVENUE_DATE_SQL`.
     */
    public function queryOrdensReconhecidas(CarbonImmutable $inicio, CarbonImmutable $fim): Builder
    {
        return Order::query()
            ->receitaReconhecida()
            ->whereRaw(Order::REVENUE_DATE_SQL.' BETWEEN ? AND ?', [$inicio->startOfDay(), $fim->endOfDay()]);
    }

    /**
     * Títulos do financeiro por COMPETÊNCIA.
     *
     * @return Collection<int, Financeiro>
     */
    public function linhasPorCompetencia(
        string $tipo,
        ?string $grupoDre,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        bool $excludeOs = false,
        ?string $excluirGrupoDre = null,
        ?string $excluirOrigemTipo = null,
        ?string $origemTipo = null,
        bool $incluirVendas = false
    ): Collection {
        $query = Financeiro::query()
            ->where('tipo', $tipo)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->where('impacta_dre', true);

        if ($origemTipo !== null) {
            $query->where('origem_tipo', $origemTipo);
        }

        if ($grupoDre !== null) {
            $query->where('grupo_dre', $grupoDre);
        }

        // Negacao explicita em vez de filtrar pelo grupo desejado: "outras
        // receitas" precisa continuar sendo CATCH-ALL. Ha titulo no banco com
        // grupo_dre nulo (lancamento incompleto), e trocar isto por
        // where('grupo_dre', 'Outras Receitas') o faria sumir do relatorio,
        // mudando o resultado do mes sem ninguem pedir.
        if ($excluirGrupoDre !== null) {
            $query->where(function ($q) use ($excluirGrupoDre): void {
                $q->whereNull('grupo_dre')
                    ->orWhere('grupo_dre', '!=', $excluirGrupoDre);
            });
        }

        if ($excluirOrigemTipo !== null) {
            $query->where(function ($q) use ($excluirOrigemTipo): void {
                $q->whereNull('origem_tipo')
                    ->orWhere('origem_tipo', '!=', $excluirOrigemTipo);
            });
        }

        if ($excludeOs) {
            // A venda vinculada a uma OS recebe os_id no titulo
            // (SalePaymentService), e um whereNull('os_id') seco a excluiria —
            // sem que ela aparecesse do outro lado, ja que a receita de OS vem
            // de os.valor_final e nao do titulo. Ela sumiria do faturamento.
            // Por isso venda entra sempre que $incluirVendas.
            $query->where(function ($q) use ($incluirVendas): void {
                $q->whereNull('os_id');

                if ($incluirVendas) {
                    $q->orWhereNotNull('venda_id');
                }
            });
        }

        $dentroDoPeriodo = (clone $query)
            ->competenciaEntre($inicio->toDateString(), $fim->toDateString());

        $fixosMensais = (clone $query)
            ->where('dre_fixo_mensal', true)
            ->where('data_vencimento', '<=', $fim->toDateString());

        return $dentroDoPeriodo->get()
            ->merge($fixosMensais->get())
            ->unique('id');
    }

    /**
     * Baixas (`financeiro_movimentos`) por DATA DE MOVIMENTO — regime de caixa.
     *
     * @param  array<int, string>  $colunasExtras  colunas adicionais para quem precisa classificar a linha
     * @return Collection<int, FinanceiroMovimento>
     */
    public function linhasPorMovimento(
        string $tipo,
        ?string $grupoDre,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        bool $excludeOs = false,
        bool $incluirFixoVariavel = false,
        ?string $excluirGrupoDre = null,
        ?string $excluirOrigemTipo = null,
        ?string $origemTipo = null,
        array $colunasExtras = []
    ): Collection {
        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', $tipo)
            ->where('financeiro.impacta_dre', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()]);

        if ($origemTipo !== null) {
            $query->where('financeiro.origem_tipo', $origemTipo);
        }

        if ($grupoDre !== null) {
            $query->where('financeiro.grupo_dre', $grupoDre);
        }

        // Ver linhasPorCompetencia(): negacao para preservar o catch-all.
        if ($excluirGrupoDre !== null) {
            $query->where(function ($q) use ($excluirGrupoDre): void {
                $q->whereNull('financeiro.grupo_dre')
                    ->orWhere('financeiro.grupo_dre', '!=', $excluirGrupoDre);
            });
        }

        if ($excluirOrigemTipo !== null) {
            $query->where(function ($q) use ($excluirOrigemTipo): void {
                $q->whereNull('financeiro.origem_tipo')
                    ->orWhere('financeiro.origem_tipo', '!=', $excluirOrigemTipo);
            });
        }

        if ($excludeOs) {
            $query->whereNull('financeiro.os_id');
        }

        $columns = [
            'financeiro_movimentos.valor_movimento as valor',
            'financeiro.subgrupo_dre as subgrupo_dre',
        ];

        if ($incluirFixoVariavel) {
            $columns[] = 'financeiro.dre_fixo_mensal as dre_fixo_mensal';
        }

        return $query->get(array_merge($columns, $colunasExtras));
    }

    /**
     * Baixas de faturamento no regime de caixa.
     *
     * Builder, e não linhas prontas, pelo mesmo motivo de
     * `queryOrdensReconhecidas()`: o DRE agrega em SQL (`SUM`/`COUNT`) e o
     * Anexo X precisa de cada baixa para ratear entre mercadoria e serviço.
     * Mantendo a agregação no banco, a extração não muda nem o último centavo
     * do que o DRE já publicava.
     */
    public function queryMovimentos(
        string $tipo,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        bool $onlyOperacional = false,
        bool $onlyOs = false
    ): \Illuminate\Database\Eloquent\Builder {
        $query = FinanceiroMovimento::query()
            ->join('financeiro', 'financeiro.id', '=', 'financeiro_movimentos.financeiro_id')
            ->where('financeiro.tipo', $tipo)
            ->where('financeiro.impacta_dre', true)
            ->whereBetween('financeiro_movimentos.data_movimento', [$inicio->toDateString(), $fim->toDateString()]);

        // Faturamento realizado: OS (que sempre tem os_id) e todo lancamento
        // marcado como RECEITA OPERACIONAL — a venda de balcao. O que sobra e
        // receita nao operacional e vai para "outras receitas".
        if ($onlyOperacional) {
            $query->where(function ($q): void {
                $q->whereNotNull('financeiro.os_id')
                    ->orWhere('financeiro.grupo_dre', Financeiro::GRUPO_DRE_RECEITA_OPERACIONAL);
            });
        }

        if ($onlyOs) {
            $query->whereNotNull('financeiro.os_id');
        }

        return $query;
    }
}
