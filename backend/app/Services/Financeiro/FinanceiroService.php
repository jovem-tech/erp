<?php

namespace App\Services\Financeiro;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Financeiro;
use App\Models\FinanceiroCartaoCredito;
use App\Models\FinanceiroCategoria;
use App\Models\FinanceiroFormaPagamento;
use App\Models\FinanceiroMovimento;
use App\Models\FinanceiroMovimentoCartao;
use App\Models\Movimentacao;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Sale;
use App\Models\Supplier;
use App\Services\Estoque\EntradaPecaService;
use App\Services\Orders\OrderEventService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FinanceiroService
{
    /**
     * Quantos meses (incluindo o próprio lançamento criado) são gerados de
     * uma vez quando o usuário marca "repetir esta despesa fixa" no
     * cadastro. Criação em lote no momento do save — não é um motor de
     * recorrência com scheduler: quando os meses acabarem, o usuário lança
     * de novo marcando repetir.
     */
    private const FIXED_EXPENSE_REPEAT_MONTHS = 12;

    public function __construct(
        private readonly FinanceiroCartaoService $financeiroCartaoService,
        private readonly FinanceiroCartaoCreditoService $financeiroCartaoCreditoService,
        private readonly FinanceiroContaService $financeiroContaService,
        private readonly OrderEventService $orderEventService,
        private readonly EntradaPecaService $entradaPecaService
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        return Financeiro::query()
            ->withFilters($filters)
            // Relações necessárias para resolveOriginTrail() (trilha de origem
            // exibida sob a categoria, na listagem) — todas eager-loaded para
            // não virar N+1 por linha: 'order.equipment.*' cobre o caso
            // "Serviço" ligado a OS, 'supplier' cobre "A pagar" avulso,
            // 'origemMovimento.financeiro.*' resolve taxas de cartão até o
            // título a receber que as originou.
            ->with([
                'order.equipment.brand',
                'order.equipment.model',
                'client',
                'supplier',
                'origemMovimento.financeiro.client',
                'origemMovimento.financeiro.order',
                // Venda de balcão (specs/027): sem isto, todo título de venda
                // cairia no ramo genérico e exibiria "sem OS vinculada".
                'sale',
                // Cartão da assistência: a listagem mostra em qual cartão/fatura
                // a despesa caiu, então precisa do nome (não só do id).
                'cartaoCredito',
            ])
            // Ordem de pagamento/recebimento efetivo, não de vencimento. Sem
            // data_pagamento (título ainda pendente) vai para o fim da lista —
            // NULL é o menor valor em ORDER BY DESC.
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Totais de despesas fixas x variáveis para o resumo da listagem de
     * Lançamentos. Sempre sobre tipo=pagar (dre_fixo_mensal só é relevante
     * para despesas — ver resolveClassification()) e ignora cancelados, mas
     * respeita os demais filtros ativos (status, cliente, mês). Agregação
     * feita no banco (SUM/GROUP BY), independente da paginação da listagem.
     *
     * @param  array<string, mixed>  $filters
     * @return array{fixas: float, variaveis: float}
     */
    public function totaisFixoVariavel(array $filters): array
    {
        $rows = Financeiro::query()
            ->withFilters(array_merge($filters, ['tipo' => Financeiro::TIPO_PAGAR]))
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            // Recibo de pagamento de fatura de cartão (ver
            // Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO) não é despesa
            // fixa nem variável — as despesas que ele resume já entram nesta
            // soma individualmente. Exclusão incondicional (não depende de
            // filtro de dre_fixo_mensal) porque estes totais aparecem com ou
            // sem esse filtro.
            ->where(function (Builder $q): void {
                $q->whereNull('origem_tipo')
                    ->orWhere('origem_tipo', '!=', Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO);
            })
            ->selectRaw('dre_fixo_mensal, SUM(valor) as total')
            ->groupBy('dre_fixo_mensal')
            ->get();

        $totais = ['fixas' => 0.0, 'variaveis' => 0.0];

        foreach ($rows as $row) {
            $chave = ((bool) $row->dre_fixo_mensal) ? 'fixas' : 'variaveis';
            $totais[$chave] = round((float) $row->total, 2);
        }

        return $totais;
    }

    /**
     * Trilha de rastreabilidade da origem de um lançamento, exibida na
     * listagem sob a categoria (ex.: "Cliente | OS 26060014 | iPhone 12").
     * Substitui o antigo subtítulo genérico grupo_dre/subgrupo_dre, que era
     * igual para todo lançamento da mesma categoria e não dizia nada sobre a
     * origem específica daquele registro.
     *
     * @return array<int, string>
     */
    public function resolveOriginTrail(Financeiro $financeiro): array
    {
        $segments = [];

        if ((bool) $financeiro->dre_fixo_mensal) {
            $segments[] = 'Fixo mensal';
        }

        // Recibo de pagamento de fatura (payInvoice(), baixa em lote) —
        // dre_fixo_mensal é sempre false nele, então o segmento "Fixo
        // mensal" acima nunca aparece aqui.
        if ((string) $financeiro->origem_tipo === Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO) {
            return ['Pagamento de fatura em lote'];
        }

        // Taxa de cartão (os_recebimento_cartao = gerada na baixa da OS,
        // financeiro_movimento_cartao = gerada num recebimento avulso em
        // cartão) — em ambos os casos origem_id aponta para o movimento cuja
        // baixa gerou a taxa; o título pai desse movimento é a conta a
        // receber de origem.
        if (in_array((string) $financeiro->origem_tipo, ['os_recebimento_cartao', 'financeiro_movimento_cartao'], true)) {
            $tituloOrigem = $financeiro->origemMovimento?->financeiro;

            if (! $tituloOrigem instanceof Financeiro) {
                return array_merge($segments, ['Origem da taxa não encontrada']);
            }

            if ($tituloOrigem->client instanceof Client) {
                $segments[] = (string) $tituloOrigem->client->nome_razao;
            }
            if ($tituloOrigem->order instanceof Order) {
                $segments[] = 'OS '.(string) $tituloOrigem->order->numero_os;
            }
            // Sempre inclui o id do título de origem — é o "mínimo" que
            // identifica a taxa mesmo quando o título pai também é avulso
            // (sem cliente/OS vinculado).
            $segments[] = 'Título #'.(int) $tituloOrigem->id;

            return $segments;
        }

        // Venda de balcão. Vem antes do ramo de OS de propósito: mesmo quando a
        // venda está vinculada a uma OS (acessório levado junto ao aparelho em
        // conserto), quem originou o título foi a venda, e é o número dela que
        // identifica o recebimento no caixa.
        if ($financeiro->sale instanceof Sale) {
            $client = $financeiro->client ?? $financeiro->sale->client;
            $segments[] = $client instanceof Client
                ? (string) $client->nome_razao
                : 'Consumidor final';
            $segments[] = 'Venda '.(string) $financeiro->sale->numero;

            return $segments;
        }

        // Ligado a uma OS (o caso mais comum de "Serviço", mas vale para
        // qualquer lançamento — manual ou automático — com os_id preenchido).
        if ($financeiro->order instanceof Order) {
            $client = $financeiro->client ?? $financeiro->order->client;
            if ($client instanceof Client) {
                $segments[] = (string) $client->nome_razao;
            }

            $segments[] = 'OS '.(string) $financeiro->order->numero_os;

            $equipment = $financeiro->order->equipment;
            $equipmentLabel = trim(implode(' ', array_filter([
                $equipment?->brand?->nome,
                $equipment?->model?->nome,
            ], static fn ($value): bool => trim((string) $value) !== '')));
            if ($equipmentLabel !== '') {
                $segments[] = $equipmentLabel;
            }

            return $segments;
        }

        // Avulso, sem OS: para "a receber", mostra o cliente (se houver) e
        // deixa explícito que não há OS vinculada; para "a pagar", mostra o
        // fornecedor quando preenchido.
        if ((string) $financeiro->tipo === Financeiro::TIPO_RECEBER) {
            if ($financeiro->client instanceof Client) {
                $segments[] = (string) $financeiro->client->nome_razao;
            }
            $segments[] = 'sem OS vinculada';

            return $segments;
        }

        if ($financeiro->supplier instanceof Supplier) {
            $segments[] = (string) ($financeiro->supplier->nome_fantasia ?: $financeiro->supplier->razao_social);

            return $segments;
        }

        if ($segments === []) {
            $segments[] = 'Lançamento avulso';
        }

        return $segments;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Financeiro
    {
        return DB::transaction(function () use ($payload): Financeiro {
            $resolved = $this->resolveClassification($payload, null);

            $financeiro = Financeiro::create($resolved);
            $this->finalizeAfterSave($financeiro, $payload);
            $financeiro = $financeiro->refresh();

            // Timeline da OS: todo titulo vinculado a uma OS vira evento auditavel
            // (cobre tambem ensureReceivableTitle da baixa, que passa por aqui).
            if ((int) ($financeiro->os_id ?? 0) > 0) {
                $this->orderEventService->record(
                    (int) $financeiro->os_id,
                    OrderEvent::CATEGORIA_FINANCEIRO,
                    OrderEvent::TIPO_TITULO_CRIADO,
                    'Título financeiro criado',
                    trim((string) $financeiro->descricao) !== '' ? (string) $financeiro->descricao : null,
                    [
                        'financeiro_id' => (int) $financeiro->id,
                        'tipo' => (string) $financeiro->tipo,
                        'categoria' => (string) $financeiro->categoria,
                        'valor' => round((float) $financeiro->valor, 2),
                        'origem_tipo' => $financeiro->origem_tipo,
                    ]
                );
            }

            // Entrada no estoque das peças compradas (specs/039).
            //
            // AQUI, antes do ramo de parcelas, e não depois: comprar em 3x gera
            // mais 2 títulos logo abaixo, e cada um deles passaria de novo por
            // este ponto se a chamada ficasse lá — três entradas para uma compra
            // só. A compra aconteceu uma vez; o que se parcela é o pagamento.
            //
            // Dentro da transação do create(): título e movimentações são
            // atômicos, nunca sobra um sem o outro.
            //
            // A guarda de chave vazia também protege os chamadores internos
            // (ensureReceivableTitle da baixa, fluxos de venda e OS), que nunca
            // mandam `itens_estoque` e por isso jamais disparam estoque.
            $itensEstoque = $payload['itens_estoque'] ?? [];

            if (is_array($itensEstoque) && $itensEstoque !== []) {
                $this->entradaPecaService->registrarDeLancamento(
                    $financeiro,
                    $itensEstoque,
                    Auth::id()
                );
            }

            // Parcelamento e repetição mensal são caminhos distintos: o
            // primeiro divide um total que acaba (12x do ar-condicionado), o
            // segundo repete um valor sem fim (mensalidade). Nunca os dois.
            if ((int) ($financeiro->cartao_parcelas_total ?? 0) > 1) {
                $cartao = FinanceiroCartaoCredito::query()->find($financeiro->cartao_credito_id);

                if ($cartao instanceof FinanceiroCartaoCredito) {
                    $this->generateRemainingInstallments(
                        $financeiro,
                        $cartao,
                        round((float) ($payload['valor'] ?? 0), 2)
                    );
                }
            } elseif ($this->shouldRepeatFixedExpense($payload, $financeiro)) {
                $this->generateFutureRepeatedTitles($financeiro);
            }

            return $financeiro;
        });
    }

    /**
     * Quantas parcelas a compra tem. Só o crédito parcela — no débito o valor
     * sai integral da conta na hora. Vale apenas na criação: reparcelar um
     * título que já existe mudaria valores/vencimentos de parcelas já lançadas.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveInstallmentCount(array $payload, ?Financeiro $existing, string $modalidade): int
    {
        if ($existing !== null || $modalidade !== FinanceiroCartaoCreditoService::MODALIDADE_CREDITO) {
            return 1;
        }

        return max(1, (int) ($payload['parcelas'] ?? 1));
    }

    /**
     * Gera as parcelas 2..N de uma compra parcelada no cartão. A 1ª já é o
     * próprio título criado (ver resolveClassification()).
     *
     * Cada parcela é um título independente e pendente, caindo na fatura
     * seguinte — é assim que a fatura de cada mês mostra só a parcela daquele
     * mês. Não passa pelo create() completo pelo mesmo motivo das repetições
     * de despesa fixa: nasce sem movimentos e sem vínculo de OS.
     */
    private function generateRemainingInstallments(Financeiro $primeira, FinanceiroCartaoCredito $cartao, float $valorTotal): void
    {
        $total = (int) $primeira->cartao_parcelas_total;

        if ($total <= 1) {
            return;
        }

        $dataCompra = $primeira->data_compra ?? $primeira->data_vencimento;
        $vencimentos = $this->financeiroCartaoCreditoService
            ->installmentDueDates($cartao, $dataCompra, $total);

        // Mesma divisão feita em resolveClassification() para a 1ª parcela —
        // parte do mesmo total, então a soma das N devolve o valor da compra.
        $valores = $this->financeiroCartaoCreditoService
            ->splitInstallmentAmounts($valorTotal, $total);

        for ($i = 1; $i < $total; $i++) {
            Financeiro::create([
                'tipo' => Financeiro::TIPO_PAGAR,
                'categoria' => $primeira->categoria,
                'descricao' => $primeira->descricao,
                'valor' => $valores[$i],
                'status' => Financeiro::STATUS_PENDENTE,
                'data_vencimento' => $vencimentos[$i],
                // Competência é a data da compra em todas as parcelas: o gasto
                // foi assumido de uma vez, o que se espalha é só o pagamento.
                'data_competencia' => $dataCompra->toDateString(),
                'data_compra' => $dataCompra->toDateString(),
                'cartao_credito_id' => $primeira->cartao_credito_id,
                'cartao_modalidade' => $primeira->cartao_modalidade,
                'cartao_parcela_numero' => $i + 1,
                'cartao_parcelas_total' => $total,
                'avulso' => $primeira->avulso,
                'fornecedor_id' => $primeira->fornecedor_id,
                'os_id' => $primeira->os_id,
                'grupo_dre' => $primeira->grupo_dre,
                'subgrupo_dre' => $primeira->subgrupo_dre,
                'impacta_dre' => $primeira->impacta_dre,
                'impacta_fluxo_caixa' => $primeira->impacta_fluxo_caixa,
                'dre_fixo_mensal' => $primeira->dre_fixo_mensal,
                'observacoes' => $primeira->observacoes,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shouldRepeatFixedExpense(array $payload, Financeiro $financeiro): bool
    {
        return $financeiro->tipo === Financeiro::TIPO_PAGAR
            && (bool) $financeiro->dre_fixo_mensal
            && filter_var($payload['repetir_proximos_meses'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * Gera cópias independentes do título recém-criado para os próximos
     * meses (mesmo dia de vencimento, valor inicial repetido mas editável
     * depois — contas como água/luz variam de valor mês a mês). Cada cópia
     * é um título comum, sem vínculo persistente com o original: não passa
     * pela orquestração completa de create() (sem OrderEvent, sem
     * finalizeAfterSave) porque nasce sempre pendente e sem movimentos —
     * nenhum dos ramos dessas rotinas se aplicaria.
     */
    private function generateFutureRepeatedTitles(Financeiro $original): void
    {
        $vencimento = $original->data_vencimento;
        $hoje = now()->startOfDay();

        // Despesa fixa lançada num cartão (ex.: plano de celular): o que se
        // repete todo mês é a COMPRA, e o vencimento de cada cópia é o da
        // fatura em que ela cai. Somar meses direto no vencimento erraria
        // sempre que o ciclo do cartão não coincidir com o mês calendário.
        // Só o crédito segue o ciclo da fatura; no débito cada cópia vence no
        // próprio dia da compra.
        $cartao = $original->cartao_credito_id !== null
            && $original->cartao_modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO
            ? FinanceiroCartaoCredito::query()->find($original->cartao_credito_id)
            : null;
        $baseCompra = $original->data_compra ?? $vencimento;

        for ($i = 1; $i < self::FIXED_EXPENSE_REPEAT_MONTHS; $i++) {
            if ($cartao instanceof FinanceiroCartaoCredito) {
                $proximaCompra = $baseCompra->copy()->addMonthsNoOverflow($i);
                $ciclo = $this->financeiroCartaoCreditoService->resolveInvoiceCycle($cartao, $proximaCompra);
                $proximoVencimento = Carbon::parse($ciclo['data_vencimento']);
                $proximaCompetencia = $proximaCompra->toDateString();
            } else {
                $proximaCompra = null;
                $proximoVencimento = $vencimento->copy()->addMonthsNoOverflow($i);
                $proximaCompetencia = $proximoVencimento->toDateString();
            }

            // Só gera pra frente da data de criação: se o título original foi
            // lançado com vencimento atrasado (conta antiga sendo colocada em
            // dia), os meses da repetição que ainda cairiam no passado ou em
            // hoje são pulados — não faz sentido a repetição "preencher"
            // meses que já passaram.
            if ($proximoVencimento->lte($hoje)) {
                continue;
            }

            Financeiro::create([
                'tipo' => Financeiro::TIPO_PAGAR,
                'categoria' => $original->categoria,
                'descricao' => $original->descricao,
                'valor' => $original->valor,
                'status' => Financeiro::STATUS_PENDENTE,
                'data_vencimento' => $proximoVencimento->toDateString(),
                'data_competencia' => $proximaCompetencia,
                'cartao_credito_id' => $original->cartao_credito_id,
                'cartao_modalidade' => $original->cartao_modalidade,
                'data_compra' => $proximaCompra?->toDateString(),
                'avulso' => $original->avulso,
                'fornecedor_id' => $original->fornecedor_id,
                'grupo_dre' => $original->grupo_dre,
                'subgrupo_dre' => $original->subgrupo_dre,
                'impacta_dre' => $original->impacta_dre,
                'impacta_fluxo_caixa' => $original->impacta_fluxo_caixa,
                'dre_fixo_mensal' => true,
                'observacoes' => $original->observacoes,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Financeiro $financeiro, array $payload): Financeiro
    {
        return DB::transaction(function () use ($financeiro, $payload): Financeiro {
            $financeiro = Financeiro::query()->lockForUpdate()->findOrFail($financeiro->id);
            $this->guardMutationAgainstMovements($financeiro, $payload);

            $antes = [
                'valor' => round((float) $financeiro->valor, 2),
                'status' => (string) $financeiro->status,
                'data_vencimento' => $financeiro->data_vencimento?->toDateString(),
                'descricao' => (string) $financeiro->descricao,
            ];

            $resolved = $this->resolveClassification($payload, $financeiro);
            $financeiro->update($resolved);
            $this->finalizeAfterSave($financeiro, $payload);
            $financeiro = $financeiro->refresh();

            if ((int) ($financeiro->os_id ?? 0) > 0) {
                $depois = [
                    'valor' => round((float) $financeiro->valor, 2),
                    'status' => (string) $financeiro->status,
                    'data_vencimento' => $financeiro->data_vencimento?->toDateString(),
                    'descricao' => (string) $financeiro->descricao,
                ];
                $diff = [];
                foreach ($antes as $campo => $valorAntes) {
                    if ($valorAntes !== $depois[$campo]) {
                        $diff[$campo] = ['antes' => $valorAntes, 'depois' => $depois[$campo]];
                    }
                }

                if ($diff !== []) {
                    $this->orderEventService->record(
                        (int) $financeiro->os_id,
                        OrderEvent::CATEGORIA_FINANCEIRO,
                        OrderEvent::TIPO_TITULO_ATUALIZADO,
                        'Título financeiro atualizado',
                        'Campos alterados: '.implode(', ', array_keys($diff)).'.',
                        ['financeiro_id' => (int) $financeiro->id, 'campos' => $diff]
                    );
                }
            }

            return $financeiro;
        });
    }

    public function delete(Financeiro $financeiro): void
    {
        // Snapshot ANTES do hard delete — e a unica chance de auditar o que saiu.
        $osId = (int) ($financeiro->os_id ?? 0);
        $snapshot = [
            'financeiro_id' => (int) $financeiro->id,
            'tipo' => (string) $financeiro->tipo,
            'categoria' => (string) $financeiro->categoria,
            'descricao' => (string) $financeiro->descricao,
            'valor' => round((float) $financeiro->valor, 2),
            'status' => (string) $financeiro->status,
        ];

        $financeiro->delete();

        if ($osId > 0) {
            $this->orderEventService->record(
                $osId,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_TITULO_EXCLUIDO,
                'Título financeiro excluído',
                $snapshot['descricao'] !== '' ? $snapshot['descricao'] : null,
                $snapshot
            );
        }
    }

    /**
     * Monta o contexto operacional completo de um lançamento financeiro.
     *
     * A listagem precisa continuar leve e paginada; por isso este método é usado
     * apenas no detalhe do lançamento. Ele carrega, sob demanda, as relações que
     * ajudam a responder "quem pagou/recebeu", "qual OS/equipamento originou o
     * valor", "quais formas de pagamento foram usadas" e "quais taxas de cartão
     * foram aplicadas", sem duplicar regra de negócio na camada desktop.
     *
     * @return array<string, mixed>
     */
    /**
     * Peças que este lançamento movimentou no estoque (specs/039).
     *
     * Sem isto `movimentacoes.financeiro_id` seria encanamento invisível e a
     * auditoria — "de onde veio esta entrada?" — só existiria em SQL. Inclui as
     * saídas de estorno para o cancelamento contar a história inteira.
     *
     * @return array<int, array<string, mixed>>
     */
    private function stockEntriesDetail(Financeiro $financeiro): array
    {
        return Movimentacao::query()
            ->with('peca')
            ->where('financeiro_id', (int) $financeiro->id)
            ->orderBy('id')
            ->get()
            ->map(static fn (Movimentacao $movimento): array => [
                'id' => (int) $movimento->id,
                'peca_id' => (int) $movimento->peca_id,
                'codigo' => (string) ($movimento->peca->codigo ?? ''),
                'nome' => (string) ($movimento->peca->nome ?? ''),
                'tipo' => (string) $movimento->tipo,
                'quantidade' => (float) $movimento->quantidade,
                'custo_unitario' => $movimento->custo_unitario !== null
                    ? round((float) $movimento->custo_unitario, 2)
                    : null,
                'created_at' => $movimento->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function detailContext(Financeiro $financeiro): array
    {
        $financeiro->loadMissing([
            'client',
            'supplier',
            'order.client',
            'order.equipment.type',
            'order.equipment.brand',
            'order.equipment.model',
            'order.statusCatalog',
            'movimentos.cartao.operadora',
            'movimentos.cartao.bandeira',
            'movimentos.conta',
            'origemMovimento.financeiro.client',
            'origemMovimento.financeiro.order.equipment.type',
            'origemMovimento.financeiro.order.equipment.brand',
            'origemMovimento.financeiro.order.equipment.model',
            'cartaoCredito.contaFinanceira',
        ]);

        return [
            'tipo_label' => $this->financeiroTipoLabel((string) $financeiro->tipo),
            'status_label' => $this->financeiroStatusLabel((string) $financeiro->status),
            'forma_pagamento_label' => $this->paymentMethodLabel($financeiro->forma_pagamento),
            'contraparte' => $this->counterpartyDetail($financeiro),
            'origem' => $this->originDetail($financeiro),
            'os' => $this->orderDetail($financeiro->order),
            'movimentos' => $financeiro->movimentos
                ->sortBy([
                    ['data_movimento', 'asc'],
                    ['id', 'asc'],
                ])
                ->values()
                ->map(fn (FinanceiroMovimento $movimento): array => $this->movementDetail($movimento))
                ->all(),
            'cartao_credito' => $this->creditCardDetail($financeiro),
            'fatura_cartao' => $this->creditCardInvoiceReceiptDetail($financeiro),
            'entradas_estoque' => $this->stockEntriesDetail($financeiro),
            'impactos' => [
                'impacta_dre' => (bool) $financeiro->impacta_dre,
                'impacta_fluxo_caixa' => (bool) $financeiro->impacta_fluxo_caixa,
                'dre_fixo_mensal' => (bool) $financeiro->dre_fixo_mensal,
                'grupo_dre' => $financeiro->grupo_dre,
                'subgrupo_dre' => $financeiro->subgrupo_dre,
                'data_competencia' => $this->dateForDetail($financeiro->data_competencia),
            ],
            'auditoria' => [
                'criado_em' => $this->dateTimeForDetail($financeiro->created_at),
                'atualizado_em' => $this->dateTimeForDetail($financeiro->updated_at),
            ],
        ];
    }

    /**
     * Cancela um título e estorna (remove) qualquer baixa já registrada, para
     * que o valor pare de contar no fluxo de caixa realizado e no DRE de
     * caixa — ambos calculados a partir de financeiro_movimentos. O DRE por
     * competência é filtrado por status=cancelado diretamente em
     * ReceitaBrutaSource::linhasPorCompetencia().
     *
     * Se algum dos movimentos deste título gerou uma despesa de taxa de
     * cartão (ver registerCardFeeExpense()), essa despesa é cancelada junto —
     * senão ela ficaria órfã, continuando a pesar no fluxo de caixa e no DRE
     * mesmo depois da receita que a gerou ter sido estornada.
     *
     * Se o título deu entrada de peça no estoque (specs/039), as entradas também
     * são estornadas — decisão diferente da 038 de propósito: lá a peça foi
     * fisicamente aplicada num aparelho; aqui o que motiva o cancelamento é o
     * equívoco (lançamento errado, ou peça que nunca chegou), e deixar o saldo
     * inflado seria a mentira. Se a peça já saiu, `$permitirEstoqueNegativo`
     * decide entre recusar nomeando as peças ou aceitar o saldo negativo.
     *
     * Tudo numa transação só: até esta entrega o método fazia várias escritas
     * soltas, o que era tolerável enquanto nenhuma delas mexia em estoque.
     *
     * @throws \App\Services\Estoque\SaldoInsuficienteException
     */
    public function cancel(Financeiro $financeiro, bool $permitirEstoqueNegativo = false): Financeiro
    {
        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Este título já está cancelado.');
        }

        return DB::transaction(
            fn (): Financeiro => $this->cancelWithinTransaction($financeiro, $permitirEstoqueNegativo)
        );
    }

    private function cancelWithinTransaction(Financeiro $financeiro, bool $permitirEstoqueNegativo): Financeiro
    {
        // Antes de qualquer outra escrita: se o estoque recusar (peça já saiu e
        // sem confirmação), a transação inteira volta e o título continua ativo.
        $this->entradaPecaService->estornarDeLancamento(
            $financeiro,
            Auth::id(),
            $permitirEstoqueNegativo
        );

        $movimentoIds = $financeiro->movimentos()->pluck('id');

        if ($movimentoIds->isNotEmpty()) {
            Financeiro::query()
                ->where('origem_tipo', 'financeiro_movimento_cartao')
                ->whereIn('origem_id', $movimentoIds)
                ->where('status', '!=', Financeiro::STATUS_CANCELADO)
                ->get()
                ->each(function (Financeiro $taxaFinanceiro): void {
                    $taxaFinanceiro->movimentos()->delete();
                    $taxaFinanceiro->update([
                        'status' => Financeiro::STATUS_CANCELADO,
                        'data_pagamento' => null,
                        'forma_pagamento' => null,
                    ]);
                });
        }

        $movimentosEstornados = $movimentoIds->count();

        $financeiro->movimentos()->delete();

        $financeiro->update([
            'status' => Financeiro::STATUS_CANCELADO,
            'data_pagamento' => null,
            'forma_pagamento' => null,
        ]);

        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $financeiro->os_id,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_TITULO_CANCELADO,
                'Título cancelado (estorno)',
                trim((string) $financeiro->descricao) !== '' ? (string) $financeiro->descricao : null,
                [
                    'financeiro_id' => (int) $financeiro->id,
                    'valor' => round((float) $financeiro->valor, 2),
                    'movimentos_estornados' => $movimentosEstornados,
                ]
            );
        }

        return $financeiro->refresh();
    }

    /**
     * Reduz (ou remove) movimentos de recebimento já registrados para que o
     * total baixado não ultrapasse um novo valor de título menor — necessário
     * quando o valor de uma OS encerrada é corrigido para baixo depois que o
     * pagamento já foi recebido (ex.: recebido R$60, corrigido para R$50).
     * Chamar ANTES de update() com o novo 'valor', senão guardMutationAgainstMovements()
     * rejeita a redução por já haver baixa maior registrada.
     *
     * Reduz a partir do movimento mais recente (LIFO) — mais fácil de conciliar
     * com o extrato bancário do que diluir proporcionalmente entre várias
     * linhas, e trata o pagamento mais recente como o mais provável de conter
     * o erro. Movimentos com meta de cartão vinculada nunca são tocados (uma
     * taxa de cartão já paga à operadora não pode ser "desfeita" automaticamente);
     * lança exceção se, mesmo pulando esses, sobrar excedente sem conseguir zerar.
     *
     * Corrige o título a receber (e, se preciso, os movimentos já baixados)
     * de uma OS para acompanhar um novo total de orçamento — usado tanto na
     * edição admin-autorizada de orçamento com OS já fechada
     * (BudgetWorkflowService::updateBudget()) quanto na aplicação de uma
     * revisão aprovada de orçamento convertido
     * (BudgetRevisionService::applyApprovedRevision()). Retorna null quando
     * a OS não tem título a receber (ex.: devolvido sem reparo / descartado
     * nunca geram lançamento financeiro).
     *
     * @return array{ajustado: bool, ajustes?: array<int, array<string, mixed>>, valor_liberado?: float}|null
     */
    public function correctReceivableTitleForOrder(Order $order, float $novoTotal): ?array
    {
        $financeiro = Financeiro::query()
            ->where('os_id', $order->id)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $financeiro instanceof Financeiro) {
            return null;
        }

        $ajuste = $this->reduceMovementsToTotal($financeiro, $novoTotal);
        $this->update($financeiro, ['valor' => $novoTotal]);

        return $ajuste;
    }

    /**
     * @return array{ajustado: bool, ajustes?: array<int, array<string, mixed>>, valor_liberado?: float}
     */
    public function reduceMovementsToTotal(Financeiro $financeiro, float $novoValorTitulo): array
    {
        $summary = $this->movementSummary($financeiro);
        $excedente = round($summary['valor_movimentado'] - $novoValorTitulo, 2);

        if ($excedente <= 0.009) {
            return ['ajustado' => false];
        }

        $movimentos = $financeiro->movimentos()
            ->with('cartao')
            ->orderByDesc('data_movimento')
            ->orderByDesc('id')
            ->get();

        $ajustes = [];

        foreach ($movimentos as $movimento) {
            if ($excedente <= 0.009) {
                break;
            }

            if ($movimento->cartao !== null) {
                continue;
            }

            $valorAtual = round((float) $movimento->valor_movimento, 2);
            $reducao = min($excedente, $valorAtual);
            $valorRestante = round($valorAtual - $reducao, 2);

            $ajustes[] = [
                'movimento_id' => (int) $movimento->id,
                'valor_antes' => $valorAtual,
                'valor_depois' => $valorRestante > 0.009 ? $valorRestante : 0.0,
                'removido' => $valorRestante <= 0.009,
            ];

            if ($valorRestante <= 0.009) {
                $movimento->delete();
            } else {
                $movimento->update(['valor_movimento' => $valorRestante]);
            }

            $excedente = round($excedente - $reducao, 2);
        }

        if ($excedente > 0.009) {
            throw new RuntimeException(
                'Não foi possível ajustar automaticamente: o valor excedente está concentrado em recebimentos via cartão, que exigem estorno manual.'
            );
        }

        return [
            'ajustado' => true,
            'ajustes' => $ajustes,
            'valor_liberado' => round($summary['valor_movimentado'] - $novoValorTitulo, 2),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function registerMovement(Financeiro $financeiro, array $payload): array
    {
        return DB::transaction(function () use ($financeiro, $payload): array {
            $lockedFinanceiro = Financeiro::query()->lockForUpdate()->findOrFail($financeiro->id);

            return $this->registerMovementLocked($lockedFinanceiro, $payload);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function registerMovementLocked(Financeiro $financeiro, array $payload): array
    {
        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível registrar baixa em título cancelado.');
        }

        $summary = $this->movementSummary($financeiro);
        $valorAberto = round((float) $summary['valor_aberto'], 2);

        if ($valorAberto <= 0) {
            throw new RuntimeException('Este título já está totalmente liquidado.');
        }

        $valorMovimento = round((float) ($payload['valor_movimento'] ?? $payload['valor'] ?? 0), 2);

        if ($valorMovimento <= 0) {
            throw new RuntimeException('Informe um valor válido para a baixa.');
        }

        if ($valorMovimento > $valorAberto + 0.001) {
            throw new RuntimeException('O valor da baixa não pode ser maior que o saldo em aberto do título.');
        }

        $formaPagamento = trim((string) ($payload['forma_pagamento'] ?? ''));
        $observacoes = trim((string) ($payload['observacoes'] ?? ''));
        $documentoRef = trim((string) ($payload['documento_ref'] ?? ''));

        $dataMovimento = $this->normalizeDate($payload['data_movimento'] ?? $payload['data_pagamento'] ?? null) ?? now()->toDateString();
        $contaFinanceiraId = (bool) $financeiro->impacta_fluxo_caixa
            ? $this->financeiroContaService->resolveAccountId(
                ! empty($payload['conta_financeira_id']) ? (int) $payload['conta_financeira_id'] : null,
                $formaPagamento !== '' ? $formaPagamento : null,
                $dataMovimento
            )
            : null;

        $movimento = FinanceiroMovimento::create([
            'financeiro_id' => $financeiro->id,
            'conta_financeira_id' => $contaFinanceiraId,
            'tipo_movimento' => $financeiro->tipo === Financeiro::TIPO_RECEBER
                ? FinanceiroMovimento::TIPO_ENTRADA
                : FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $dataMovimento,
            'valor_movimento' => $valorMovimento,
            'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
            'documento_ref' => $documentoRef !== '' ? $documentoRef : null,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);

        // O guard extra por operadora_id (além do forma_pagamento) é proposital:
        // outros chamadores de registerMovement() (ex.: OrderClosureService, no
        // fechamento de OS) simulam a taxa e registram o próprio
        // FinanceiroMovimentoCartao/despesa antes de chamar este método, sem
        // repassar operadora_id aqui — sem esse guard, este bloco tentaria
        // simular de novo sem operadora e derrubaria a baixa com exceção.
        if (FinanceiroFormaPagamento::isCardCode($formaPagamento) && ! empty($payload['operadora_id'])) {
            $simulation = $this->registerCardMovementMeta($movimento, $payload, $valorMovimento, $dataMovimento, $observacoes);
            $this->registerCardFeeExpense($financeiro, $simulation, $movimento);
        }

        $summary = $this->syncFromMovements($financeiro);
        $summary['movement_id'] = $movimento->id;

        if ((int) ($financeiro->os_id ?? 0) > 0) {
            $this->orderEventService->record(
                (int) $financeiro->os_id,
                OrderEvent::CATEGORIA_FINANCEIRO,
                OrderEvent::TIPO_MOVIMENTO_REGISTRADO,
                $financeiro->tipo === Financeiro::TIPO_RECEBER ? 'Recebimento registrado' : 'Pagamento registrado',
                sprintf(
                    'R$ %s (%s) em %s.',
                    number_format($valorMovimento, 2, ',', '.'),
                    $formaPagamento !== '' ? $formaPagamento : 'forma não informada',
                    Carbon::parse($dataMovimento)->format('d/m/Y')
                ),
                [
                    'financeiro_id' => (int) $financeiro->id,
                    'movimento_id' => (int) $movimento->id,
                    'valor' => $valorMovimento,
                    'forma_pagamento' => $formaPagamento !== '' ? $formaPagamento : null,
                    'data_movimento' => $dataMovimento,
                ]
            );
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function registerCardMovementMeta(
        FinanceiroMovimento $movimento,
        array $payload,
        float $valorMovimento,
        string $dataMovimento,
        string $observacoes
    ): array {
        $simulation = $this->financeiroCartaoService->simulate([
            'valor_bruto' => $valorMovimento,
            'operadora_id' => $payload['operadora_id'] ?? null,
            'bandeira_id' => $payload['bandeira_id'] ?? null,
            'modalidade' => $payload['modalidade'] ?? null,
            'forma_pagamento' => $payload['forma_pagamento'] ?? null,
            'parcelas' => $payload['parcelas'] ?? 1,
        ]);

        FinanceiroMovimentoCartao::query()->create([
            'movimento_id' => $movimento->id,
            'operadora_id' => $simulation['operadora_id'] ?? null,
            'bandeira_id' => $simulation['bandeira_id'] ?? null,
            'taxa_id' => $simulation['taxa_id'] ?? null,
            'modalidade' => (string) ($simulation['modalidade'] ?? 'credito'),
            'parcelas' => (int) ($simulation['parcelas'] ?? 1),
            'valor_bruto' => round((float) ($simulation['valor_bruto'] ?? 0), 2),
            'taxa_percentual' => round((float) ($simulation['taxa_percentual'] ?? 0), 4),
            'taxa_fixa' => round((float) ($simulation['taxa_fixa'] ?? 0), 2),
            'valor_taxa' => round((float) ($simulation['valor_taxa'] ?? 0), 2),
            'valor_liquido' => round((float) ($simulation['valor_liquido'] ?? 0), 2),
            'prazo_recebimento_dias' => (int) ($simulation['prazo_recebimento_dias'] ?? 0),
            'data_competencia' => $dataMovimento,
            'data_prevista_repasse' => $simulation['data_prevista_repasse'] ?? null,
            'data_prevista_recebimento' => $simulation['data_prevista_recebimento'] ?? null,
            'data_credito_efetivo' => $simulation['data_credito_efetivo'] ?? null,
            'observacoes' => $observacoes !== '' ? $observacoes : null,
        ]);

        return $simulation;
    }

    /**
     * Registra a taxa da operadora como uma despesa própria (tipo=pagar), para
     * que o custo real da maquininha deixe de ser invisível no fluxo de caixa e
     * no DRE — sem isso, o título a receber ficava com o valor bruto do cartão
     * como se a assistência tivesse recebido o valor integral, sem controlar
     * quanto a operadora reteve. A baixa original do título permanece com o
     * valor bruto (é o que o cliente de fato pagou e o que quita o título);
     * a taxa é registrada como uma saída separada, já realizada (paga), com
     * seu próprio movimento para contar em todos os relatórios (competência e
     * caixa) — mesma classificação DRE ("Despesas Operacionais" / "Taxas e
     * impostos") já usada para outras taxas do sistema.
     *
     * A taxa é datada no mesmo dia do pagamento (data_movimento), não na data
     * prevista de repasse da operadora — a receita bruta do título também é
     * reconhecida no dia do pagamento, então a taxa precisa seguir a mesma
     * competência/caixa para o fluxo de caixa e o DRE baterem no mesmo dia
     * (a data prevista de repasse continua registrada em
     * financeiro_movimentos_cartao.data_prevista_repasse, só não é usada aqui).
     *
     * @param  array<string, mixed>  $simulation
     */
    private function registerCardFeeExpense(Financeiro $financeiro, array $simulation, FinanceiroMovimento $movimento): void
    {
        $valorTaxa = round((float) ($simulation['valor_taxa'] ?? 0), 2);
        if ($valorTaxa <= 0) {
            return;
        }

        $dataMovimento = $movimento->data_movimento->toDateString();
        $parcelas = (int) ($simulation['parcelas'] ?? 1);

        $taxaFinanceiro = Financeiro::create([
            'tipo' => Financeiro::TIPO_PAGAR,
            'avulso' => true,
            // Herda a OS do título pago (quando houver) — sem isso, a despesa
            // de taxa fica "solta" (os_id nulo) e o cancelamento dela nunca
            // aciona a trava de motivo+admin de OS encerrada (ver
            // FinanceiroController::resolveOsIsEncerrada()), mesmo a OS
            // estando fechada. Mesmo padrão já usado em
            // OrderClosureService::registerCardFeeExpense().
            'os_id' => $financeiro->os_id,
            'categoria' => 'Taxa de cartão',
            'descricao' => sprintf(
                'Taxa %s - Lançamento #%d (%s%s)',
                (string) ($simulation['operadora_nome'] ?? ''),
                $financeiro->id,
                (string) ($simulation['modalidade_label'] ?? ''),
                $parcelas > 1 ? ' em '.$parcelas.'x' : ''
            ),
            'valor' => $valorTaxa,
            'status' => Financeiro::STATUS_PAGO,
            'origem_tipo' => 'financeiro_movimento_cartao',
            'origem_id' => $movimento->id,
            'grupo_dre' => 'Despesas Operacionais',
            'subgrupo_dre' => 'Taxas e impostos',
            'data_vencimento' => $dataMovimento,
            'data_pagamento' => $dataMovimento,
            'data_competencia' => $dataMovimento,
            'forma_pagamento' => ($simulation['modalidade'] ?? '') === 'debito' ? 'cartao_debito' : 'cartao_credito',
            'observacoes' => 'Despesa criada automaticamente na baixa em cartão deste lançamento, para registrar o custo da operadora.',
            'impacta_dre' => true,
            'impacta_fluxo_caixa' => true,
            'dre_fixo_mensal' => false,
        ]);

        FinanceiroMovimento::create([
            'financeiro_id' => $taxaFinanceiro->id,
            'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
            'data_movimento' => $dataMovimento,
            'valor_movimento' => $valorTaxa,
            'forma_pagamento' => $taxaFinanceiro->forma_pagamento,
            'observacoes' => 'Taxa da operadora referente ao movimento #'.$movimento->id.'.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function movementSummary(Financeiro $financeiro): array
    {
        $valorTitulo = round((float) $financeiro->valor, 2);

        $aggregate = $financeiro->movimentos()
            ->selectRaw('COUNT(*) as total_movimentos, COALESCE(SUM(valor_movimento), 0) as valor_movimentado, MAX(data_movimento) as ultimo_movimento_em')
            ->first();

        $valorMovimentado = round((float) ($aggregate->valor_movimentado ?? 0), 2);
        $valorAberto = max(0, round($valorTitulo - $valorMovimentado, 2));
        $totalMovimentos = (int) ($aggregate->total_movimentos ?? 0);

        $formasPagamento = $financeiro->movimentos()
            ->whereNotNull('forma_pagamento')
            ->distinct()
            ->pluck('forma_pagamento');

        $formaPagamentoResolvida = $totalMovimentos > 1
            ? 'multiplo'
            : ($formasPagamento->first() ?? null);

        return [
            'titulo_id' => $financeiro->id,
            'valor_titulo' => $valorTitulo,
            'valor_movimentado' => $valorMovimentado,
            'valor_aberto' => $valorAberto,
            'total_movimentos' => $totalMovimentos,
            'ultimo_movimento_em' => $aggregate->ultimo_movimento_em ?? null,
            'forma_pagamento_resolvida' => $formaPagamentoResolvida,
            'status_resolvido' => $this->resolveStatus($financeiro->status, $valorTitulo, $valorMovimentado),
            'percentual_quitado' => $valorTitulo > 0 ? min(100, round(($valorMovimentado / $valorTitulo) * 100, 2)) : 0.0,
        ];
    }

    /**
     * Detalhe da compra no cartão da assistência, exibido na tela do
     * lançamento.
     *
     * A forma de pagamento sai daqui (de cartao_modalidade) e não de
     * financeiro.forma_pagamento: essa coluna é derivada das baixas e fica
     * NULL enquanto o título está pendente, então a tela mostrava "Não
     * informada" justamente nas despesas de cartão que ainda esperam a fatura.
     *
     * @return array<string, mixed>|null
     */
    private function creditCardDetail(Financeiro $financeiro): ?array
    {
        // O recibo de pagamento de fatura (ver
        // Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO) referencia o cartão
        // só para o badge/trilha da listagem — ele não é uma compra no
        // cartão. Sem este guard, cartao_modalidade=NULL faria este método
        // rotulá-lo por engano como "Cartão de débito" ($isCredito abaixo
        // dá falso).
        if ((string) $financeiro->origem_tipo === Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO) {
            return null;
        }

        $cartao = $financeiro->cartaoCredito;

        if (! $cartao instanceof FinanceiroCartaoCredito) {
            return null;
        }

        $modalidade = (string) $financeiro->cartao_modalidade;
        $isCredito = $modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO;
        $parcelasTotal = (int) ($financeiro->cartao_parcelas_total ?? 0);

        return [
            'id' => (int) $cartao->id,
            'nome' => (string) $cartao->nome,
            'instituicao' => $cartao->instituicao,
            'final_cartao' => $cartao->final_cartao,
            'cor' => (string) $cartao->cor,
            'modalidade' => $modalidade,
            'modalidade_label' => $isCredito ? 'Cartão de crédito' : 'Cartão de débito',
            'dia_fechamento' => (int) $cartao->dia_fechamento,
            'dia_vencimento' => (int) $cartao->dia_vencimento,
            'data_compra' => $this->dateForDetail($financeiro->data_compra),
            // No crédito o vencimento do título É o da fatura; no débito o
            // valor saiu da conta no dia da compra, então não há fatura.
            'fatura_vencimento' => $isCredito ? $this->dateForDetail($financeiro->data_vencimento) : null,
            'parcela_numero' => $parcelasTotal > 1 ? (int) $financeiro->cartao_parcela_numero : null,
            'parcelas_total' => $parcelasTotal > 1 ? $parcelasTotal : null,
            'conta_financeira_nome' => $cartao->contaFinanceira?->nome,
        ];
    }

    /**
     * As despesas que o recibo de pagamento de fatura liquidou (ver
     * FinanceiroCartaoCreditoService::registerInvoicePaymentReceipt()).
     *
     * O recibo é um agregador: uma saída de caixa única que quita N compras,
     * cada uma com sua própria OS/fornecedor. Como não existe uma coluna
     * os_id capaz de representar N ordens, a tela do recibo mostrava
     * "Sem OS vinculada" mesmo quando as despesas por trás tinham OS — a
     * ligação existia, mas ficava invisível. Este bloco devolve a lista para
     * a tela conseguir exibi-la.
     *
     * O escopo é a fatura inteira (cartão + vencimento), a mesma granularidade
     * que cancelInvoicePayment() usa para estornar: recibo e fatura nascem e
     * morrem juntos, então listar a fatura é listar o que o recibo pagou.
     *
     * @return array<string, mixed>|null
     */
    private function creditCardInvoiceReceiptDetail(Financeiro $financeiro): ?array
    {
        if ((string) $financeiro->origem_tipo !== Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO) {
            return null;
        }

        $cartao = $financeiro->cartaoCredito;

        if (! $cartao instanceof FinanceiroCartaoCredito || $financeiro->data_vencimento === null) {
            return null;
        }

        $despesas = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', FinanceiroCartaoCreditoService::MODALIDADE_CREDITO)
            ->whereDate('data_vencimento', $financeiro->data_vencimento->toDateString())
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->with(['supplier', 'order'])
            ->orderBy('data_compra')
            ->orderBy('id')
            ->get();

        return [
            'cartao' => [
                'id' => (int) $cartao->id,
                'nome' => (string) $cartao->nome,
                'instituicao' => $cartao->instituicao,
                'final_cartao' => $cartao->final_cartao,
                'cor' => (string) $cartao->cor,
            ],
            'data_vencimento' => $this->dateForDetail($financeiro->data_vencimento),
            'quantidade_despesas' => $despesas->count(),
            'quantidade_com_os' => $despesas->filter(
                static fn (Financeiro $despesa): bool => (int) $despesa->os_id > 0
            )->count(),
            'valor_total' => round((float) $despesas->sum('valor'), 2),
            'despesas' => $despesas->map(function (Financeiro $despesa): array {
                $parcelasTotal = (int) ($despesa->cartao_parcelas_total ?? 0);

                return [
                    'id' => (int) $despesa->id,
                    'descricao' => (string) $despesa->descricao,
                    'categoria' => (string) $despesa->categoria,
                    'valor' => round((float) $despesa->valor, 2),
                    'status' => (string) $despesa->status,
                    'data_compra' => $this->dateForDetail($despesa->data_compra),
                    'parcela_numero' => $parcelasTotal > 1 ? (int) $despesa->cartao_parcela_numero : null,
                    'parcelas_total' => $parcelasTotal > 1 ? $parcelasTotal : null,
                    'os' => $despesa->order !== null
                        ? [
                            'id' => (int) $despesa->order->id,
                            'numero_os' => $despesa->order->numero_os,
                        ]
                        : null,
                    'fornecedor' => $despesa->supplier !== null
                        ? [
                            'id' => (int) $despesa->supplier->id,
                            'nome' => (string) ($despesa->supplier->nome_fantasia
                                ?: $despesa->supplier->razao_social),
                        ]
                        : null,
                ];
            })->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function counterpartyDetail(Financeiro $financeiro): array
    {
        if ($financeiro->tipo === Financeiro::TIPO_RECEBER) {
            $client = $financeiro->client ?? $financeiro->order?->client;

            return [
                'tipo' => 'cliente',
                'id' => $client?->id !== null ? (int) $client->id : null,
                'titulo' => 'Quem pagou',
                'nome' => $client?->nome_razao ?: null,
                'documento' => $client?->cpf_cnpj ?: null,
                'telefone' => $client?->telefone1 ?: $client?->telefone2 ?: null,
                'email' => $client?->email ?: null,
                'observacoes' => $client?->observacoes ?: null,
            ];
        }

        $supplier = $financeiro->supplier;

        return [
            'tipo' => 'fornecedor',
            'id' => $supplier?->id !== null ? (int) $supplier->id : null,
            'titulo' => 'Para quem pagou',
            'nome' => $supplier?->nome_fantasia ?: $supplier?->razao_social ?: null,
            'documento' => $supplier?->cnpj_cpf ?: null,
            'telefone' => $supplier?->telefone1 ?: $supplier?->telefone2 ?: null,
            'email' => $supplier?->email ?: null,
            'observacoes' => $supplier?->observacoes ?: null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function originDetail(Financeiro $financeiro): array
    {
        if ((string) $financeiro->origem_tipo === 'financeiro_movimento_cartao') {
            $originMovement = $financeiro->origemMovimento;
            $originFinanceiro = $originMovement?->financeiro;

            return [
                'tipo' => 'taxa_cartao',
                'titulo' => 'Taxa de cartão',
                'descricao' => 'Despesa gerada automaticamente pela baixa em cartão de outro lançamento.',
                'lancamento_origem_id' => $originFinanceiro?->id,
                'movimento_origem_id' => $originMovement?->id,
                'lancamento_origem_descricao' => $originFinanceiro?->descricao,
            ];
        }

        if ((int) $financeiro->os_id > 0) {
            return [
                'tipo' => 'os',
                'titulo' => 'Ordem de serviço',
                'descricao' => 'Lançamento vinculado ao fluxo financeiro de uma OS.',
                'os_id' => (int) $financeiro->os_id,
            ];
        }

        if ((bool) $financeiro->avulso) {
            return [
                'tipo' => 'avulso',
                'titulo' => 'Lançamento avulso',
                'descricao' => (int) $financeiro->cliente_id > 0
                    ? 'Lançamento avulso com cliente vinculado, sem ordem de serviço.'
                    : 'Lançamento avulso puro, sem ordem de serviço e sem cliente vinculado.',
            ];
        }

        return [
            'tipo' => 'manual',
            'titulo' => 'Lançamento manual',
            'descricao' => 'Lançamento financeiro sem origem operacional específica registrada.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderDetail(?Order $order): ?array
    {
        if (! $order instanceof Order) {
            return null;
        }

        $equipment = $order->equipment;
        $equipmentParts = array_values(array_filter([
            $equipment?->type?->nome,
            $equipment?->brand?->nome,
            $equipment?->model?->nome,
        ], static fn ($value): bool => trim((string) $value) !== ''));

        return [
            'id' => (int) $order->id,
            'numero_os' => $order->numero_os,
            'status' => $order->status,
            'status_nome' => $order->statusCatalog?->nome ?: $order->status,
            'datas' => [
                'abertura' => $this->dateTimeForDetail($order->data_abertura),
                'entrada' => $this->dateTimeForDetail($order->data_entrada),
                'previsao' => $this->dateForDetail($order->data_previsao),
                'conclusao' => $this->dateTimeForDetail($order->data_conclusao),
                'entrega' => $this->dateTimeForDetail($order->data_entrega),
                'baixa_tecnica' => $this->dateTimeForDetail($order->baixa_tecnica_em),
            ],
            'valores' => [
                'mao_obra' => $order->valor_mao_obra !== null ? round((float) $order->valor_mao_obra, 2) : null,
                'pecas' => $order->valor_pecas !== null ? round((float) $order->valor_pecas, 2) : null,
                'total' => $order->valor_total !== null ? round((float) $order->valor_total, 2) : null,
                'desconto' => $order->desconto !== null ? round((float) $order->desconto, 2) : null,
                'final' => $order->valor_final !== null ? round((float) $order->valor_final, 2) : null,
            ],
            'cliente' => [
                'id' => $order->client?->id,
                'nome' => $order->client?->nome_razao,
                'telefone' => $order->client?->telefone1 ?: $order->client?->telefone2,
            ],
            'equipamento' => [
                'id' => $equipment?->id,
                'label' => $equipmentParts !== [] ? implode(' ', $equipmentParts) : ($equipment?->resumo_tecnico ?: null),
                'tipo' => $equipment?->type?->nome,
                'marca' => $equipment?->brand?->nome,
                'modelo' => $equipment?->model?->nome,
                'serie' => $equipment?->numero_serie,
                'imei' => $equipment?->imei,
                'resumo_tecnico' => $equipment?->resumo_tecnico,
                'estado_fisico' => $equipment?->estado_fisico,
            ],
            'defeito' => [
                'relato_cliente' => $order->relato_cliente,
                'diagnostico_tecnico' => $order->diagnostico_tecnico,
                'solucao_aplicada' => $order->solucao_aplicada,
                'procedimentos_executados' => $order->procedimentos_executados,
            ],
            'orcamento' => $this->budgetSummaryForOrder((int) $order->id),
        ];
    }

    /**
     * Orçamento mais recente da OS vinculada — permite ao frontend oferecer o
     * atalho "Ver orçamento" nos detalhes do lançamento.
     *
     * @return array<string, mixed>|null
     */
    private function budgetSummaryForOrder(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $budget = Budget::query()
            ->where('os_id', $orderId)
            ->orderByDesc('id')
            ->first(['id', 'numero', 'status']);

        if ($budget === null) {
            return null;
        }

        return [
            'id' => (int) $budget->id,
            'numero' => $budget->numero,
            'status' => $budget->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function movementDetail(FinanceiroMovimento $movimento): array
    {
        $cartao = $movimento->cartao;

        return [
            'id' => (int) $movimento->id,
            'tipo' => $movimento->tipo_movimento,
            'tipo_label' => $this->movementTypeLabel((string) $movimento->tipo_movimento),
            'data_movimento' => $this->dateForDetail($movimento->data_movimento),
            'valor' => round((float) $movimento->valor_movimento, 2),
            'forma_pagamento' => $movimento->forma_pagamento,
            'forma_pagamento_label' => $this->paymentMethodLabel($movimento->forma_pagamento),
            'conta_financeira' => $movimento->conta ? [
                'id' => (int) $movimento->conta->id,
                'nome' => $movimento->conta->nome,
            ] : null,
            'documento_ref' => $movimento->documento_ref,
            'observacoes' => $movimento->observacoes,
            'cartao' => $cartao instanceof FinanceiroMovimentoCartao ? [
                'operadora' => $cartao->operadora?->nome,
                'bandeira' => $cartao->bandeira?->nome,
                'modalidade' => $cartao->modalidade,
                'parcelas' => (int) $cartao->parcelas,
                'valor_bruto' => round((float) $cartao->valor_bruto, 2),
                'taxa_percentual' => round((float) $cartao->taxa_percentual, 4),
                'taxa_fixa' => round((float) $cartao->taxa_fixa, 2),
                'valor_taxa' => round((float) $cartao->valor_taxa, 2),
                'valor_liquido' => round((float) $cartao->valor_liquido, 2),
                'prazo_recebimento_dias' => (int) $cartao->prazo_recebimento_dias,
                'data_competencia' => $this->dateForDetail($cartao->data_competencia),
                'data_prevista_repasse' => $this->dateForDetail($cartao->data_prevista_repasse),
                'data_prevista_recebimento' => $this->dateForDetail($cartao->data_prevista_recebimento),
                'data_credito_efetivo' => $this->dateForDetail($cartao->data_credito_efetivo),
                'credito_confirmado_em' => $this->dateTimeForDetail($cartao->credito_confirmado_em),
            ] : null,
        ];
    }

    private function financeiroTipoLabel(string $tipo): string
    {
        return $tipo === Financeiro::TIPO_RECEBER ? 'A receber' : 'A pagar';
    }

    private function financeiroStatusLabel(string $status): string
    {
        return match ($status) {
            Financeiro::STATUS_PAGO => 'Pago',
            Financeiro::STATUS_PARCIAL => 'Parcial',
            Financeiro::STATUS_CANCELADO => 'Cancelado',
            default => 'Pendente',
        };
    }

    private function movementTypeLabel(string $tipo): string
    {
        return match ($tipo) {
            FinanceiroMovimento::TIPO_ENTRADA => 'Entrada',
            FinanceiroMovimento::TIPO_SAIDA => 'Saída',
            FinanceiroMovimento::TIPO_ESTORNO => 'Estorno',
            FinanceiroMovimento::TIPO_TRANSFERENCIA => 'Transferência',
            default => ucfirst($tipo),
        };
    }

    private function paymentMethodLabel(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'dinheiro' => 'Dinheiro',
            'cartao_credito' => 'Cartão de crédito',
            'cartao_debito' => 'Cartão de débito',
            'pix' => 'Pix',
            'boleto' => 'Boleto',
            'transferencia' => 'Transferência',
            default => ucfirst(str_replace('_', ' ', $value)),
        };
    }

    private function dateForDetail(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return $this->normalizeDate($value);
    }

    private function dateTimeForDetail(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function syncFromMovements(Financeiro $financeiro): array
    {
        $summary = $this->movementSummary($financeiro);
        $status = (string) $summary['status_resolvido'];
        $clearPagamento = in_array($status, [Financeiro::STATUS_CANCELADO, Financeiro::STATUS_PENDENTE], true);

        $formaPagamento = (string) ($summary['forma_pagamento_resolvida'] ?? '');

        $financeiro->update([
            'status' => $status,
            'data_pagamento' => $clearPagamento ? null : ($summary['ultimo_movimento_em'] ?? null),
            // "multiplo" é só um rótulo informativo do resumo da API; a coluna
            // financeiro.forma_pagamento é um ENUM restrito do banco real e não
            // aceita esse valor sintético — o detalhe de cada baixa já fica
            // registrado em financeiro_movimentos.forma_pagamento (texto livre).
            // Pelo mesmo motivo, formas personalizadas do catálogo (fora do
            // ENUM) também caem aqui como null, sem perder o detalhe.
            'forma_pagamento' => (! $clearPagamento && in_array($formaPagamento, FinanceiroFormaPagamento::summaryCodes(), true))
                ? $formaPagamento
                : null,
        ]);

        return $summary;
    }

    private function resolveStatus(string $statusAtual, float $valorTitulo, float $valorMovimentado): string
    {
        if ($statusAtual === Financeiro::STATUS_CANCELADO) {
            return Financeiro::STATUS_CANCELADO;
        }

        if ($valorMovimentado <= 0) {
            return Financeiro::STATUS_PENDENTE;
        }

        if ($valorTitulo > 0 && $valorMovimentado + 0.001 < $valorTitulo) {
            return Financeiro::STATUS_PARCIAL;
        }

        return Financeiro::STATUS_PAGO;
    }

    /**
     * Depois de criar/atualizar o título, garante que o status declarado e os
     * movimentos de baixa fiquem consistentes entre si (mesma regra do legado:
     * "pago" sem movimento cria a baixa total automaticamente; "parcial" sem
     * movimento volta para "pendente").
     *
     * @param  array<string, mixed>  $payload
     */
    private function finalizeAfterSave(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);

        if ($financeiro->status === Financeiro::STATUS_CANCELADO) {
            if ((int) $summary['total_movimentos'] > 0) {
                throw new RuntimeException('Não é possível cancelar um título que já possui movimentos realizados.');
            }

            return;
        }

        if ((int) $summary['total_movimentos'] > 0) {
            $this->syncFromMovements($financeiro);

            return;
        }

        // Despesa comprada no CRÉDITO de um cartão da assistência é liquidada
        // pela fatura (FinanceiroCartaoCreditoService::payInvoice()), nunca
        // pela baixa automática do status: criar o movimento aqui marcaria a
        // despesa como paga sem a fatura ter sido paga, e ela sairia do saldo
        // em aberto da fatura sem dinheiro nenhum ter saído. Normaliza para
        // pendente — mesmo tratamento que o "parcial sem movimento" logo
        // abaixo. Só entra aqui quando não há movimento nenhum (o retorno
        // acima já tratou os títulos com baixa real), então uma despesa já
        // paga pela fatura não é afetada.
        if ($financeiro->cartao_credito_id !== null
            && $financeiro->cartao_modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO
            && in_array($financeiro->status, [Financeiro::STATUS_PAGO, Financeiro::STATUS_PARCIAL], true)
        ) {
            $financeiro->update([
                'status' => Financeiro::STATUS_PENDENTE,
                'data_pagamento' => null,
                'forma_pagamento' => null,
            ]);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PAGO) {
            $this->registerMovement($financeiro, [
                'valor_movimento' => $financeiro->valor,
                'data_movimento' => $payload['data_pagamento'] ?? $financeiro->data_pagamento,
                'forma_pagamento' => $payload['forma_pagamento'] ?? $financeiro->forma_pagamento,
                'conta_financeira_id' => $payload['conta_financeira_id'] ?? null,
                'observacoes' => $payload['observacoes'] ?? null,
            ]);

            return;
        }

        if ($financeiro->status === Financeiro::STATUS_PARCIAL) {
            $financeiro->update(['status' => Financeiro::STATUS_PENDENTE, 'data_pagamento' => null, 'forma_pagamento' => null]);

            return;
        }

        if ($financeiro->data_pagamento !== null || $financeiro->forma_pagamento !== null) {
            $financeiro->update(['data_pagamento' => null, 'forma_pagamento' => null]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function guardMutationAgainstMovements(Financeiro $financeiro, array $payload): void
    {
        $summary = $this->movementSummary($financeiro);
        if ((int) $summary['total_movimentos'] <= 0) {
            return;
        }

        if (array_key_exists('tipo', $payload) && (string) $payload['tipo'] !== $financeiro->tipo) {
            throw new RuntimeException('Não é possível alterar o tipo de um título que já possui movimentações registradas.');
        }

        if (
            array_key_exists('avulso', $payload)
            && filter_var($payload['avulso'], FILTER_VALIDATE_BOOL) !== (bool) $financeiro->avulso
        ) {
            throw new RuntimeException('Não é possível alterar o vínculo avulso de um título que já possui movimentações registradas.');
        }

        if (array_key_exists('impacta_fluxo_caixa', $payload) && ! filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)) {
            throw new RuntimeException('Um título que já possui movimentos realizados deve continuar impactando o fluxo de caixa.');
        }

        // Trocar o cartão depois da baixa moveria a despesa para outra fatura
        // (a chave da fatura é cartao_credito_id + data_vencimento), fazendo o
        // total de uma fatura já paga mudar retroativamente.
        if (array_key_exists('cartao_credito_id', $payload)) {
            $novoCartaoId = ! empty($payload['cartao_credito_id']) ? (int) $payload['cartao_credito_id'] : null;

            if ($novoCartaoId !== $financeiro->cartao_credito_id) {
                throw new RuntimeException('Não é possível trocar o cartão de crédito de um título que já possui movimentações registradas.');
            }
        }

        $statusDestino = (string) ($payload['status'] ?? $financeiro->status);
        if ($statusDestino === Financeiro::STATUS_CANCELADO) {
            throw new RuntimeException('Não é possível cancelar um título que já possui movimentações registradas.');
        }

        if (array_key_exists('valor', $payload) && round((float) $payload['valor'], 2) + 0.001 < round((float) $summary['valor_movimentado'], 2)) {
            throw new RuntimeException('O valor total do título não pode ficar menor que o valor já baixado.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveClassification(array $payload, ?Financeiro $existing): array
    {
        $merged = array_merge($existing?->toArray() ?? [], $payload);

        $tipo = strtolower(trim((string) ($merged['tipo'] ?? '')));
        $categoriaNome = trim((string) ($merged['categoria'] ?? ''));
        $categoriaConfig = $categoriaNome !== ''
            ? FinanceiroCategoria::query()
                ->whereRaw('LOWER(nome) = ?', [mb_strtolower($categoriaNome, 'UTF-8')])
                ->whereIn('tipo', array_filter([$tipo, FinanceiroCategoria::TIPO_AMBOS]))
                ->with(['dre_grupo', 'dre_subgrupo'])
                ->first()
            : null;

        $resolved = $payload;
        // A conta identifica onde a baixa foi liquidada e pertence somente a
        // financeiro_movimentos. Mantê-la no payload do título tentaria gravar
        // uma coluna inexistente em financeiro e duplicaria a fonte de verdade.
        unset($resolved['conta_financeira_id']);
        // Flags de ação (geram títulos extras no create()), não são colunas de
        // financeiro — mantê-las no payload quebraria o Financeiro::create().
        unset($resolved['repetir_proximos_meses']);
        unset($resolved['parcelas']);
        // Peças da compra viram movimentações de estoque no create(), não
        // colunas de financeiro (specs/039).
        unset($resolved['itens_estoque']);

        $resolved['tipo'] = $tipo;
        $resolved['status'] = trim((string) ($payload['status'] ?? $existing?->status ?? '')) !== ''
            ? $payload['status'] ?? $existing?->status
            : Financeiro::STATUS_PENDENTE;
        $resolved['categoria'] = $categoriaNome !== '' ? $categoriaNome : $existing?->categoria;
        $resolved['descricao'] = trim((string) ($payload['descricao'] ?? '')) !== ''
            ? $payload['descricao']
            : ($existing?->descricao ?? $categoriaNome);

        $resolved['grupo_dre'] = trim((string) ($payload['grupo_dre'] ?? '')) !== ''
            ? $payload['grupo_dre']
            : ($categoriaConfig?->dre_grupo?->nome ?? $existing?->grupo_dre);

        $resolved['subgrupo_dre'] = trim((string) ($payload['subgrupo_dre'] ?? '')) !== ''
            ? $payload['subgrupo_dre']
            : ($categoriaConfig?->dre_subgrupo?->nome ?? $existing?->subgrupo_dre);

        $resolved['impacta_dre'] = array_key_exists('impacta_dre', $payload)
            ? filter_var($payload['impacta_dre'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_dre ?? (bool) ($categoriaConfig?->impacta_dre_padrao ?? true));

        $resolved['impacta_fluxo_caixa'] = array_key_exists('impacta_fluxo_caixa', $payload)
            ? filter_var($payload['impacta_fluxo_caixa'], FILTER_VALIDATE_BOOL)
            : ($existing?->impacta_fluxo_caixa ?? (bool) ($categoriaConfig?->impacta_fluxo_caixa_padrao ?? true));

        $resolved['dre_fixo_mensal'] = $tipo === Financeiro::TIPO_PAGAR
            ? (array_key_exists('dre_fixo_mensal', $payload)
                ? filter_var($payload['dre_fixo_mensal'], FILTER_VALIDATE_BOOL)
                : ($existing?->dre_fixo_mensal ?? (bool) ($categoriaConfig?->dre_fixo_mensal_padrao ?? false)))
            : false;

        // Cartão de crédito da assistência (compra feita NO cartão, não
        // recebimento de cliente). Quando há cartão vinculado, o vencimento
        // deixa de ser digitado e passa a ser o da fatura em que a compra caiu
        // — é (cartao_credito_id, data_vencimento) que identifica a fatura na
        // listagem e na baixa em lote, então não pode depender do que o
        // formulário mandou.
        $cartaoCreditoId = array_key_exists('cartao_credito_id', $payload)
            ? (! empty($payload['cartao_credito_id']) ? (int) $payload['cartao_credito_id'] : null)
            : $existing?->cartao_credito_id;

        if ($cartaoCreditoId !== null && $tipo !== Financeiro::TIPO_PAGAR) {
            throw new RuntimeException('Só é possível vincular um cartão de crédito a uma despesa (a pagar).');
        }

        $resolved['cartao_credito_id'] = $cartaoCreditoId;

        if ($cartaoCreditoId !== null) {
            $cartao = FinanceiroCartaoCredito::query()->find($cartaoCreditoId);

            if (! $cartao instanceof FinanceiroCartaoCredito) {
                throw new RuntimeException('Cartão não encontrado.');
            }

            // Modalidade fica numa coluna própria porque
            // financeiro.forma_pagamento é derivada das baixas e volta a NULL
            // enquanto o título está pendente (ver syncFromMovements()) — na
            // edição de uma despesa pendente ela não serviria de fonte.
            $formaPagamento = trim((string) ($payload['forma_pagamento'] ?? ''));
            $modalidade = match ($formaPagamento) {
                FinanceiroCartaoCreditoService::FORMA_CREDITO => FinanceiroCartaoCreditoService::MODALIDADE_CREDITO,
                FinanceiroCartaoCreditoService::FORMA_DEBITO => FinanceiroCartaoCreditoService::MODALIDADE_DEBITO,
                default => $existing?->cartao_modalidade,
            };

            if (! in_array($modalidade, FinanceiroCartaoCreditoService::MODALIDADES, true)) {
                throw new RuntimeException('Só é possível vincular um cartão quando a forma de pagamento é cartão de crédito ou de débito.');
            }

            $dataCompra = $this->normalizeDate($payload['data_compra'] ?? null)
                ?? $existing?->data_compra?->toDateString()
                ?? now()->toDateString();

            $parcelas = $this->resolveInstallmentCount($payload, $existing, $modalidade);

            if ($parcelas > 1) {
                // O usuário digita o valor TOTAL da compra; o título que está
                // sendo criado vira a 1ª parcela e as demais são geradas em
                // create(). Por isso o valor é substituído aqui.
                $valorTotal = round((float) ($merged['valor'] ?? 0), 2);
                $resolved['valor'] = $this->financeiroCartaoCreditoService
                    ->splitInstallmentAmounts($valorTotal, $parcelas)[0];
                $resolved['cartao_parcela_numero'] = 1;
                $resolved['cartao_parcelas_total'] = $parcelas;
            } else {
                $resolved['cartao_parcela_numero'] = $existing?->cartao_parcela_numero;
                $resolved['cartao_parcelas_total'] = $existing?->cartao_parcelas_total;
            }

            $resolved['cartao_modalidade'] = $modalidade;
            $resolved['data_compra'] = $dataCompra;
            // A despesa é incorrida no dia da compra, não no vencimento da
            // fatura — sem isso o DRE por competência jogaria a compra para o
            // mês do pagamento da fatura.
            $resolved['data_competencia'] = $dataCompra;

            // Crédito entra na fatura do ciclo; débito sai da conta na hora,
            // então vence no próprio dia da compra e nunca compõe fatura.
            $resolved['data_vencimento'] = $modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO
                ? $this->financeiroCartaoCreditoService->resolveInvoiceCycle($cartao, Carbon::parse($dataCompra))['data_vencimento']
                : $dataCompra;

            // Compra não pode entrar numa fatura já paga: o usuário conferiu
            // aquele valor e quitou com o banco, e a despesa nova apareceria
            // lá depois, mudando o total de uma fatura fechada. Só barra quando
            // a compra está de fato ENTRANDO na fatura paga (título novo, ou
            // edição que mudou de fatura) — editar uma despesa que já vive numa
            // fatura paga continua liberado.
            $mudouDeFatura = $existing === null
                || $existing->data_vencimento?->toDateString() !== $resolved['data_vencimento'];

            if ($modalidade === FinanceiroCartaoCreditoService::MODALIDADE_CREDITO
                && $mudouDeFatura
                && $this->financeiroCartaoCreditoService->isInvoiceSettled($cartao, $resolved['data_vencimento'])
            ) {
                throw new RuntimeException(sprintf(
                    'A fatura que vence em %s já foi paga. Escolha uma data de compra a partir de %s, que cai numa fatura ainda aberta.',
                    Carbon::parse($resolved['data_vencimento'])->format('d/m/Y'),
                    Carbon::parse(
                        $this->financeiroCartaoCreditoService->minimumPurchaseDate($cartao) ?? $dataCompra
                    )->format('d/m/Y')
                ));
            }
        } else {
            $resolved['data_compra'] = null;
            $resolved['cartao_modalidade'] = null;
            $resolved['cartao_parcela_numero'] = null;
            $resolved['cartao_parcelas_total'] = null;
            $resolved['data_competencia'] = $this->normalizeDate($payload['data_competencia'] ?? null)
                ?? $existing?->data_competencia?->toDateString()
                ?? $this->normalizeDate($merged['data_vencimento'] ?? null);
        }

        if (($resolved['status'] ?? $existing?->status) === Financeiro::STATUS_PAGO && empty($merged['data_pagamento'])) {
            $resolved['data_pagamento'] = now()->toDateString();
        }

        $osId = (int) ($merged['os_id'] ?? 0);
        $avulso = array_key_exists('avulso', $payload)
            ? filter_var($payload['avulso'], FILTER_VALIDATE_BOOL)
            : (bool) ($existing?->avulso ?? false);

        if ($avulso && $osId > 0) {
            throw new RuntimeException('Lançamentos avulsos não podem ser vinculados a uma ordem de serviço.');
        }

        $resolved['avulso'] = $avulso;

        if ($tipo === Financeiro::TIPO_RECEBER) {
            $clienteId = (int) ($payload['cliente_id'] ?? $existing?->cliente_id ?? 0);

            if ($osId > 0) {
                $clienteOsId = (int) (Order::query()->where('id', $osId)->value('cliente_id') ?? 0);

                if ($clienteOsId <= 0) {
                    throw new RuntimeException('A ordem de serviço informada não possui um cliente válido.');
                }

                if ($clienteId > 0 && $clienteId !== $clienteOsId) {
                    throw new RuntimeException('O cliente informado não corresponde ao cliente da ordem de serviço.');
                }

                $clienteId = $clienteOsId;
            }

            if ($clienteId <= 0 && $osId <= 0 && ! $avulso) {
                throw new RuntimeException('Selecione o cliente desta cobrança ou vincule uma OS antes de salvar.');
            }

            $resolved['cliente_id'] = $clienteId > 0 ? $clienteId : null;
            $resolved['fornecedor_id'] = null;
        } else {
            $resolved['cliente_id'] = null;
            $resolved['fornecedor_id'] = ! empty($payload['fornecedor_id']) ? (int) $payload['fornecedor_id'] : null;
        }

        return $resolved;
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }
}
