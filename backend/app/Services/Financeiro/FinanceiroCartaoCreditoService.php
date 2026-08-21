<?php

namespace App\Services\Financeiro;

use App\Models\Financeiro;
use App\Models\FinanceiroCartaoCredito;
use App\Models\FinanceiroFormaPagamento;
use App\Models\FinanceiroMovimento;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Cartões de crédito da própria assistência — os que ela usa para COMPRAR
 * (peça de cliente, plano de celular, cola, etc.), agrupando despesas por
 * fatura.
 *
 * NÃO confundir com FinanceiroCartaoService (operadora/bandeira/taxa), que é
 * o outro lado do balcão: RECEBER pagamento do cliente na maquininha. São
 * domínios distintos e não compartilham tabela, rota nem tela.
 */
class FinanceiroCartaoCreditoService
{
    /**
     * Só compra no CRÉDITO entra em fatura. No débito o dinheiro sai da conta
     * na hora da compra — agrupar isso numa fatura criaria uma "fatura" de um
     * dia só e deixaria como "em aberto" algo que já foi pago.
     */
    public const FORMA_CREDITO = 'cartao_credito';

    public const FORMA_DEBITO = 'cartao_debito';

    /** Formas de pagamento que aceitam vínculo com um cartão cadastrado. */
    public const FORMAS_CARTAO = [self::FORMA_CREDITO, self::FORMA_DEBITO];

    /**
     * Gravado em financeiro.cartao_modalidade. É esta coluna — e não
     * financeiro.forma_pagamento — que diz se a compra entra em fatura, porque
     * forma_pagamento é derivada das baixas e fica NULL enquanto o título está
     * pendente (ver FinanceiroService::syncFromMovements()).
     */
    public const MODALIDADE_CREDITO = 'credito';

    public const MODALIDADE_DEBITO = 'debito';

    public const MODALIDADES = [self::MODALIDADE_CREDITO, self::MODALIDADE_DEBITO];

    /**
     * Descobre a qual fatura uma compra pertence, a partir do ciclo real do
     * cartão (dia de fechamento -> dia de vencimento), e não do mês
     * calendário.
     *
     * Duas regras, nesta ordem:
     *   1. Fecha no mês da própria compra se o dia da compra ainda não passou
     *      do fechamento; senão entra na fatura que fecha no mês seguinte.
     *   2. O vencimento cai no mesmo mês do fechamento quando o dia de vencer
     *      é maior que o de fechar (ex.: fecha 5, vence 15). Quando é menor ou
     *      igual, o vencimento é no mês seguinte ao fechamento (ex.: fecha 28,
     *      vence 5).
     *
     * Dias 29/30/31 são reduzidos ao último dia real do mês (fev, meses de 30)
     * — por isso os dois lados usam min(dia, diasNoMes).
     *
     * Como o mês de vencimento cresce junto com o de fechamento, dois ciclos
     * diferentes do mesmo cartão nunca caem no mesmo vencimento: é isso que
     * torna o par (cartao_credito_id, data_vencimento) uma chave confiável
     * para agrupar a fatura e para a baixa em lote.
     *
     * Método puro (sem I/O) de propósito — é a parte com mais casos de borda
     * da feature e precisa ser testável isoladamente.
     *
     * @return array{ciclo_fechamento: string, data_vencimento: string}
     */
    public function resolveInvoiceCycle(FinanceiroCartaoCredito $cartao, CarbonInterface $dataCompra): array
    {
        $diaFechamento = max(1, min(31, (int) $cartao->dia_fechamento));
        $diaVencimento = max(1, min(31, (int) $cartao->dia_vencimento));

        $compra = CarbonImmutable::parse($dataCompra->toDateString());

        $fechamentoNoMesDaCompra = min($diaFechamento, (int) $compra->daysInMonth);
        $mesFechamento = $compra->day <= $fechamentoNoMesDaCompra
            ? $compra->startOfMonth()
            : $compra->startOfMonth()->addMonthNoOverflow();

        $mesVencimento = $diaVencimento > $diaFechamento
            ? $mesFechamento
            : $mesFechamento->addMonthNoOverflow();

        return [
            'ciclo_fechamento' => $mesFechamento
                ->setUnit('day', min($diaFechamento, (int) $mesFechamento->daysInMonth))
                ->toDateString(),
            'data_vencimento' => $mesVencimento
                ->setUnit('day', min($diaVencimento, (int) $mesVencimento->daysInMonth))
                ->toDateString(),
        ];
    }

    /**
     * Uma fatura está liquidada quando tem despesas e nenhuma delas ainda está
     * em aberto — é o mesmo critério de invoiceList() (status 'paga'), só que
     * direto, sem montar a lista inteira.
     *
     * Fatura sem despesa nenhuma NÃO é liquidada: é um ciclo futuro que ainda
     * não recebeu compra.
     */
    public function isInvoiceSettled(FinanceiroCartaoCredito $cartao, string $dataVencimento): bool
    {
        $base = fn () => Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->whereDate('data_vencimento', $dataVencimento)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO);

        if ($base()->doesntExist()) {
            return false;
        }

        return $base()
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL])
            ->doesntExist();
    }

    /**
     * Primeira data de compra ainda aceitável para o cartão: lançar uma compra
     * numa fatura já paga mexeria num valor que o usuário já conferiu e quitou
     * com o banco.
     *
     * É o dia seguinte ao FECHAMENTO da fatura paga mais recente (não ao
     * vencimento dela): é o fechamento que separa um ciclo do outro — comprar
     * no próprio dia do fechamento ainda cai naquela fatura.
     *
     * Retorna null quando nenhuma fatura do cartão foi paga — aí não há
     * restrição nenhuma.
     */
    public function minimumPurchaseDate(FinanceiroCartaoCredito $cartao): ?string
    {
        $fechamentoDaUltimaPaga = null;

        foreach ($this->invoiceList($cartao) as $fatura) {
            if (($fatura['status'] ?? '') === 'paga') {
                $fechamentoDaUltimaPaga = $fatura['data_fechamento'];
            }
        }

        if ($fechamentoDaUltimaPaga === null) {
            return null;
        }

        return CarbonImmutable::parse($fechamentoDaUltimaPaga)->addDay()->toDateString();
    }

    /**
     * Data em que a fatura de um dado vencimento fechou.
     *
     * É o inverso de resolveInvoiceCycle(): lá o mês de vencimento sai do mês
     * de fechamento; aqui o caminho é de volta. Quando o dia de vencer é maior
     * que o de fechar, fechamento e vencimento caem no mesmo mês (fecha 5,
     * vence 15); caso contrário o fechamento é no mês anterior ao vencimento
     * (fecha 28, vence 5).
     *
     * Derivado do vencimento — e não guardado numa coluna — de propósito: a
     * fatura é identificada por (cartao_credito_id, data_vencimento), então ter
     * o fechamento persistido abriria espaço para os dois divergirem.
     */
    public function closingDateForDueDate(FinanceiroCartaoCredito $cartao, CarbonInterface $dataVencimento): string
    {
        $diaFechamento = max(1, min(31, (int) $cartao->dia_fechamento));
        $diaVencimento = max(1, min(31, (int) $cartao->dia_vencimento));

        $mesVencimento = CarbonImmutable::parse($dataVencimento->toDateString())->startOfMonth();

        $mesFechamento = $diaVencimento > $diaFechamento
            ? $mesVencimento
            : $mesVencimento->subMonthNoOverflow();

        return $mesFechamento
            ->setUnit('day', min($diaFechamento, (int) $mesFechamento->daysInMonth))
            ->toDateString();
    }

    /**
     * Primeiro dia em que a fatura de um dado vencimento aceitou compras: o dia
     * seguinte ao fechamento do ciclo ANTERIOR.
     *
     * Junto com closingDateForDueDate() forma a janela [abertura, fechamento]
     * do ciclo — é ela que o calendário do formulário usa para não deixar
     * escolher uma data que cairia noutra fatura.
     *
     * O mês anterior é obtido com subMonthNoOverflow() + clamp ao último dia
     * real: fechamento 31 em março tem que virar 28/29 em fevereiro, não
     * transbordar para março de novo.
     */
    public function openingDateForDueDate(FinanceiroCartaoCredito $cartao, CarbonInterface $dataVencimento): string
    {
        $diaFechamento = max(1, min(31, (int) $cartao->dia_fechamento));

        $fechamentoAnterior = CarbonImmutable::parse(
            $this->closingDateForDueDate($cartao, $dataVencimento)
        )->startOfMonth()->subMonthNoOverflow();

        return $fechamentoAnterior
            ->setUnit('day', min($diaFechamento, (int) $fechamentoAnterior->daysInMonth))
            ->addDay()
            ->toDateString();
    }

    /**
     * Vencimentos das N parcelas de uma compra parcelada.
     *
     * A 1ª parcela cai na fatura do ciclo da compra; as seguintes vão para as
     * faturas consecutivas seguintes.
     *
     * Cuidado que motiva este método existir: NÃO dá para obter a parcela i
     * recalculando o ciclo a partir de "data da compra + i meses". Com
     * fechamento 28 e compra no dia 29, por exemplo, a compra + 1 mês cai em
     * 28/fev (dia limite do mês curto), que fecha no MESMO ciclo da 1ª parcela
     * — duas parcelas na mesma fatura. Andar de mês em mês sobre o vencimento
     * já resolvido é determinístico e nunca colide.
     *
     * @return array<int, string>
     */
    public function installmentDueDates(FinanceiroCartaoCredito $cartao, CarbonInterface $dataCompra, int $parcelas): array
    {
        $parcelas = max(1, $parcelas);
        $diaVencimento = max(1, min(31, (int) $cartao->dia_vencimento));

        $primeira = CarbonImmutable::parse(
            $this->resolveInvoiceCycle($cartao, $dataCompra)['data_vencimento']
        );

        $datas = [];

        for ($i = 0; $i < $parcelas; $i++) {
            $mes = $primeira->startOfMonth()->addMonthsNoOverflow($i);
            $datas[] = $mes->setUnit('day', min($diaVencimento, (int) $mes->daysInMonth))->toDateString();
        }

        return $datas;
    }

    /**
     * Divide o valor total entre as parcelas sem perder centavos: o resto da
     * divisão vai para a 1ª parcela (mesma convenção das faturas de cartão),
     * de modo que a soma das parcelas devolve exatamente o total.
     *
     * @return array<int, float>
     */
    public function splitInstallmentAmounts(float $total, int $parcelas): array
    {
        $parcelas = max(1, $parcelas);
        $totalCentavos = (int) round($total * 100);
        $base = intdiv($totalCentavos, $parcelas);
        $resto = $totalCentavos - ($base * $parcelas);

        $valores = array_fill(0, $parcelas, $base);
        $valores[0] += $resto;

        return array_map(static fn (int $centavos): float => round($centavos / 100, 2), $valores);
    }

    /** @return array<int, array<string, mixed>> */
    public function list(bool $onlyActive = false): array
    {
        $query = FinanceiroCartaoCredito::query();

        if ($onlyActive) {
            $query->active();
        }

        return $query
            ->orderByDesc('ativo')
            ->orderBy('nome')
            ->get()
            ->map(fn (FinanceiroCartaoCredito $cartao): array => $this->serialize($cartao))
            ->all();
    }

    /**
     * Cartões com o resumo da fatura em aberto — o número que o usuário quer
     * ver sem precisar abrir cada cartão ("quanto já gastei nesse cartão neste
     * ciclo").
     *
     * @return array<int, array<string, mixed>>
     */
    public function dashboard(): array
    {
        $cartoes = FinanceiroCartaoCredito::query()
            ->orderByDesc('ativo')
            ->orderBy('nome')
            ->get();

        return $cartoes->map(function (FinanceiroCartaoCredito $cartao): array {
            $faturas = $this->invoiceList($cartao);
            $abertas = array_values(array_filter(
                $faturas,
                static fn (array $fatura): bool => $fatura['status'] === 'aberta'
            ));

            $faturaAtual = $this->currentInvoice($faturas);

            return array_merge($this->serialize($cartao), [
                'fatura_atual' => $faturaAtual,
                'total_em_aberto' => round(array_sum(array_column($abertas, 'total')), 2),
                'faturas_abertas' => count($abertas),
            ]);
        })->all();
    }

    /**
     * A fatura "atual": a aberta de vencimento mais próximo. Como invoiceList()
     * devolve em ordem crescente, é a primeira aberta da lista. Se estiver tudo
     * pago, cai para a última fatura registrada (a mais recente).
     *
     * @param  array<int, array<string, mixed>>  $faturas
     * @return array<string, mixed>|null
     */
    public function currentInvoice(array $faturas): ?array
    {
        foreach ($faturas as $fatura) {
            if (($fatura['status'] ?? '') === 'aberta') {
                return $fatura;
            }
        }

        return $faturas !== [] ? $faturas[array_key_last($faturas)] : null;
    }

    /** @param array<string, mixed> $payload */
    public function create(array $payload, ?int $actorId): FinanceiroCartaoCredito
    {
        $cartao = FinanceiroCartaoCredito::query()->create([
            'nome' => trim((string) $payload['nome']),
            'instituicao' => $this->nullableString($payload['instituicao'] ?? null),
            'conta_financeira_id' => ! empty($payload['conta_financeira_id']) ? (int) $payload['conta_financeira_id'] : null,
            'final_cartao' => $this->nullableString($payload['final_cartao'] ?? null),
            'dia_fechamento' => (int) $payload['dia_fechamento'],
            'dia_vencimento' => (int) $payload['dia_vencimento'],
            'cor' => $payload['cor'] ?? '#3868B0',
            'ativo' => (bool) ($payload['ativo'] ?? true),
            'observacoes' => $this->nullableString($payload['observacoes'] ?? null),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $cartao->refresh();
    }

    /** @param array<string, mixed> $payload */
    public function update(FinanceiroCartaoCredito $cartao, array $payload, ?int $actorId): FinanceiroCartaoCredito
    {
        // Desativar só é permitido sem fatura em aberto — senão o gasto do
        // ciclo corrente sumiria da tela sem ter sido pago. Mesmo espírito do
        // guard de FinanceiroContaService::updateAccount().
        if (array_key_exists('ativo', $payload) && ! (bool) $payload['ativo'] && (bool) $cartao->ativo) {
            $abertas = array_filter(
                $this->invoiceList($cartao),
                static fn (array $fatura): bool => $fatura['status'] === 'aberta'
            );

            if ($abertas !== []) {
                throw new RuntimeException('Pague ou cancele as faturas em aberto antes de desativar este cartão.');
            }
        }

        $allowed = ['nome', 'instituicao', 'conta_financeira_id', 'final_cartao', 'dia_fechamento', 'dia_vencimento', 'cor', 'ativo', 'observacoes'];
        $updates = array_intersect_key($payload, array_flip($allowed));

        if (array_key_exists('conta_financeira_id', $updates)) {
            $updates['conta_financeira_id'] = ! empty($updates['conta_financeira_id'])
                ? (int) $updates['conta_financeira_id']
                : null;
        }

        foreach (['instituicao', 'final_cartao', 'observacoes'] as $nullable) {
            if (array_key_exists($nullable, $updates)) {
                $updates[$nullable] = $this->nullableString($updates[$nullable]);
            }
        }

        $updates['updated_by'] = $actorId;
        $cartao->update($updates);

        return $cartao->refresh();
    }

    /**
     * Faturas do cartão (da mais recente para a mais antiga), agrupadas por
     * data_vencimento — a chave de fatura definida em resolveInvoiceCycle().
     *
     * Cancelados ficam de fora do total, igual em totaisFixoVariavel(): um
     * título cancelado não é dívida.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoiceList(FinanceiroCartaoCredito $cartao, array $filtros = []): array
    {
        $query = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO);

        $mes = trim((string) ($filtros['mes'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            [$ano, $mesNumero] = explode('-', $mes);
            $query->whereYear('data_vencimento', (int) $ano)->whereMonth('data_vencimento', (int) $mesNumero);
        }

        $de = $this->normalizeDateFilter($filtros['vencimento_de'] ?? null);
        if ($de !== null) {
            $query->whereDate('data_vencimento', '>=', $de);
        }

        $ate = $this->normalizeDateFilter($filtros['vencimento_ate'] ?? null);
        if ($ate !== null) {
            $query->whereDate('data_vencimento', '<=', $ate);
        }

        $rows = $query
            ->selectRaw('data_vencimento, dre_fixo_mensal, status, SUM(valor) as total, COUNT(*) as quantidade')
            ->groupBy('data_vencimento', 'dre_fixo_mensal', 'status')
            // Crescente: a fatura corrente vem primeiro e a lista segue para as
            // futuras. Ver a próxima a vencer é o uso do dia a dia; o histórico
            // antigo fica para os filtros.
            ->orderBy('data_vencimento')
            ->get()
            ->groupBy(static fn ($row): string => CarbonImmutable::parse($row->data_vencimento)->toDateString());

        $faturas = [];
        $hoje = CarbonImmutable::today();
        $abertoPorVencimento = $this->openBalanceByDueDate($cartao);
        $pagoEmPorVencimento = $this->settlementDateByDueDate($cartao);

        foreach ($rows as $dataVencimento => $linhas) {
            $fixas = 0.0;
            $variaveis = 0.0;
            $quantidade = 0;
            $aberta = false;

            foreach ($linhas as $linha) {
                $total = round((float) $linha->total, 2);

                if ((bool) $linha->dre_fixo_mensal) {
                    $fixas += $total;
                } else {
                    $variaveis += $total;
                }

                $quantidade += (int) $linha->quantidade;

                if (in_array($linha->status, [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL], true)) {
                    $aberta = true;
                }
            }

            // Fatura vencida e ainda em aberto: na vida real o saldo é
            // postergado para a próxima fatura com juros do rotativo. O sistema
            // não faz esse rolo automaticamente (juros dependem do contrato de
            // cada cartão) — mas precisa avisar, senão a fatura fica em aberto
            // para sempre sem ninguém notar.
            $vencida = $aberta && CarbonImmutable::parse($dataVencimento)->lt($hoje);

            $faturas[] = [
                'data_vencimento' => $dataVencimento,
                'data_fechamento' => $this->closingDateForDueDate(
                    $cartao,
                    CarbonImmutable::parse($dataVencimento)
                ),
                // Janela em que esta fatura aceitou compras — usada pelo
                // calendário do lançamento de despesa esquecida.
                'data_abertura' => $this->openingDateForDueDate(
                    $cartao,
                    CarbonImmutable::parse($dataVencimento)
                ),
                'total' => round($fixas + $variaveis, 2),
                'total_fixas' => round($fixas, 2),
                'total_variaveis' => round($variaveis, 2),
                'quantidade_despesas' => $quantidade,
                'status' => $aberta ? 'aberta' : 'paga',
                // Só faz sentido numa fatura quitada: numa parcial a data do
                // último movimento seria "quando pagaram um pedaço", que
                // anunciada como "paga em" enganaria.
                'data_pagamento' => $aberta ? null : ($pagoEmPorVencimento[$dataVencimento] ?? null),
                'vencida' => $vencida,
                // Saldo real a baixar: desconta o que já foi pago em títulos
                // parciais. Sem isso o botão "Pagar fatura" anunciaria um valor
                // maior do que o que a baixa em lote de fato liquida.
                'total_em_aberto' => round((float) ($abertoPorVencimento[$dataVencimento] ?? 0), 2),
            ];
        }

        $situacao = trim((string) ($filtros['situacao'] ?? ''));
        if (in_array($situacao, ['aberta', 'paga', 'vencida'], true)) {
            $faturas = array_values(array_filter(
                $faturas,
                static fn (array $fatura): bool => $situacao === 'vencida'
                    ? $fatura['vencida']
                    : $fatura['status'] === $situacao
            ));
        }

        return $faturas;
    }

    /**
     * Saldo em aberto por vencimento (valor dos títulos abertos menos o que já
     * foi baixado neles).
     *
     * São dois agregados separados de propósito: juntar financeiro com
     * financeiro_movimentos num JOIN faria o SUM(valor) do título repetir uma
     * vez por movimento (fan-out), inflando o total de qualquer título com mais
     * de uma baixa parcial.
     *
     * @return array<string, float>
     */
    private function openBalanceByDueDate(FinanceiroCartaoCredito $cartao): array
    {
        $base = fn () => Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL]);

        $titulos = $base()
            ->selectRaw('data_vencimento, SUM(valor) as total')
            ->groupBy('data_vencimento')
            ->pluck('total', 'data_vencimento');

        $baixado = $base()
            ->join('financeiro_movimentos', 'financeiro_movimentos.financeiro_id', '=', 'financeiro.id')
            ->selectRaw('financeiro.data_vencimento, SUM(financeiro_movimentos.valor_movimento) as total')
            ->groupBy('financeiro.data_vencimento')
            ->pluck('total', 'data_vencimento');

        $saldos = [];

        foreach ($titulos as $dataVencimento => $total) {
            $chave = CarbonImmutable::parse($dataVencimento)->toDateString();
            $saldos[$chave] = max(0, round((float) $total - (float) ($baixado[$dataVencimento] ?? 0), 2));
        }

        return $saldos;
    }

    /**
     * Quando cada fatura foi paga: data do movimento mais recente entre as
     * despesas dela.
     *
     * O MAX é proposital — uma fatura pode ser liquidada em mais de uma
     * chamada (retry de baixa parcial, ou despesa esquecida lançada depois), e
     * o que interessa é a data em que ela ficou de fato quitada.
     *
     * Uma query agregada para o cartão inteiro, e não uma por fatura: a
     * listagem mostra o histórico completo do cartão e viraria N+1.
     *
     * @return array<string, string>
     */
    private function settlementDateByDueDate(FinanceiroCartaoCredito $cartao): array
    {
        $linhas = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->join('financeiro_movimentos', 'financeiro_movimentos.financeiro_id', '=', 'financeiro.id')
            ->selectRaw('financeiro.data_vencimento, MAX(financeiro_movimentos.data_movimento) as pago_em')
            ->groupBy('financeiro.data_vencimento')
            ->pluck('pago_em', 'data_vencimento');

        $datas = [];

        foreach ($linhas as $dataVencimento => $pagoEm) {
            if ($pagoEm === null) {
                continue;
            }

            $datas[CarbonImmutable::parse($dataVencimento)->toDateString()] = CarbonImmutable::parse($pagoEm)->toDateString();
        }

        return $datas;
    }

    /**
     * Despesas de uma fatura específica. Sem paginação de propósito: uma
     * fatura é um mês de compras de um cartão.
     *
     * @return array<string, mixed>
     */
    public function invoiceDetail(FinanceiroCartaoCredito $cartao, string $dataVencimento): array
    {
        $despesas = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->whereDate('data_vencimento', $dataVencimento)
            ->with(['supplier', 'order'])
            ->orderBy('data_compra')
            ->orderBy('id')
            ->get();

        $faturas = $this->invoiceList($cartao);
        $fatura = array_values(array_filter(
            $faturas,
            static fn (array $f): bool => $f['data_vencimento'] === $dataVencimento
        ))[0] ?? [
            'data_vencimento' => $dataVencimento,
            'total' => 0.0,
            'total_fixas' => 0.0,
            'total_variaveis' => 0.0,
            'quantidade_despesas' => 0,
            'status' => 'paga',
            'data_pagamento' => null,
            'vencida' => false,
            'total_em_aberto' => 0.0,
        ];

        // Mesma regra de elegibilidade de payInvoice() e da listagem de
        // faturas (faturas.blade.php): só a fatura corrente ou uma vencida
        // pode ser paga — as futuras ainda estão recebendo compras e o total
        // muda até o fechamento. Calculado aqui (fonte única) para o botão
        // "Marcar fatura como paga" desta tela seguir a mesma regra, não
        // importa se o usuário chegou pela lista de faturas ou pela listagem
        // de despesas (financeiro/index — despesa de cartão linka direto
        // para cá).
        $faturaAtual = $this->currentInvoice($faturas);
        $ehAtual = $faturaAtual !== null && ($faturaAtual['data_vencimento'] ?? null) === $dataVencimento;
        $fatura['eh_atual'] = $ehAtual;
        $fatura['pode_pagar'] = ($fatura['status'] ?? '') === 'aberta'
            && ($ehAtual || (bool) ($fatura['vencida'] ?? false))
            && (float) ($fatura['total_em_aberto'] ?? 0) > 0;

        // Só faz sentido oferecer "cancelar a baixa" quando existe baixa: pelo
        // menos uma despesa da fatura já liquidada (total ou parcialmente).
        // Ver cancelInvoicePayment().
        $fatura['pode_cancelar_baixa'] = $despesas->contains(
            fn (Financeiro $despesa): bool => in_array(
                (string) $despesa->status,
                [Financeiro::STATUS_PAGO, Financeiro::STATUS_PARCIAL],
                true
            )
        );

        return [
            'cartao' => $this->serialize($cartao),
            'fatura' => $fatura,
            'despesas' => $despesas->map(fn (Financeiro $despesa): array => [
                'id' => (int) $despesa->id,
                'descricao' => (string) $despesa->descricao,
                'categoria' => (string) $despesa->categoria,
                'valor' => round((float) $despesa->valor, 2),
                'status' => (string) $despesa->status,
                'dre_fixo_mensal' => (bool) $despesa->dre_fixo_mensal,
                'parcela_numero' => $despesa->cartao_parcela_numero,
                'parcelas_total' => $despesa->cartao_parcelas_total,
                'data_compra' => $despesa->data_compra?->toDateString(),
                'data_vencimento' => $despesa->data_vencimento?->toDateString(),
                'fornecedor' => $despesa->supplier?->nome_fantasia ?? $despesa->supplier?->razao_social,
                // A compra pode ter nascido de uma OS (peça comprada para um
                // conserto). Sem isto a fatura mostrava só a descrição livre,
                // e a ligação com a OS ficava invisível aqui e no recibo de
                // pagamento — ver FinanceiroService::creditCardInvoiceReceiptDetail().
                'os' => $despesa->order !== null
                    ? [
                        'id' => (int) $despesa->order->id,
                        'numero_os' => $despesa->order->numero_os,
                    ]
                    : null,
            ])->all(),
        ];
    }

    /**
     * Baixa toda a fatura de uma vez: o usuário paga um valor único no banco,
     * mas por trás são N despesas (fixas e variáveis) que precisam ser
     * liquidadas individualmente para o DRE continuar correto.
     *
     * Reaproveita FinanceiroService::registerMovement() — a MESMA rotina da
     * baixa individual — em vez de escrever movimentos na mão, para não
     * duplicar as regras de saldo/conta/status. Cada título é uma transação
     * própria (registerMovement já abre a sua): uma despesa problemática não
     * pode impedir a baixa das outras, mesmo padrão de
     * OrderWorkflowService::updateStatusBatch().
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function payInvoice(FinanceiroCartaoCredito $cartao, string $dataVencimento, array $payload): array
    {
        $titulos = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->whereDate('data_vencimento', $dataVencimento)
            ->whereIn('status', [Financeiro::STATUS_PENDENTE, Financeiro::STATUS_PARCIAL])
            ->orderBy('id')
            ->get();

        if ($titulos->isEmpty()) {
            throw new RuntimeException('Não há despesas em aberto nesta fatura.');
        }

        $dataPagamento = trim((string) ($payload['data_pagamento'] ?? '')) !== ''
            ? $payload['data_pagamento']
            : now()->toDateString();

        $succeeded = [];
        $failed = [];

        foreach ($titulos as $titulo) {
            try {
                $aberto = round((float) $this->financeiroService()->movementSummary($titulo)['valor_aberto'], 2);

                if ($aberto <= 0) {
                    continue;
                }

                $this->financeiroService()->registerMovement($titulo, [
                    'valor_movimento' => $aberto,
                    'data_movimento' => $dataPagamento,
                    'forma_pagamento' => $this->nullableString($payload['forma_pagamento'] ?? null),
                    'conta_financeira_id' => $payload['conta_financeira_id'] ?? null,
                    'observacoes' => $this->nullableString($payload['observacoes'] ?? null)
                        ?? sprintf(
                            'Baixa em lote — fatura %s com vencimento em %s',
                            $cartao->nome,
                            CarbonImmutable::parse($dataVencimento)->format('d/m/Y')
                        ),
                ]);

                $succeeded[] = [
                    'financeiro_id' => (int) $titulo->id,
                    'descricao' => (string) $titulo->descricao,
                    'valor' => $aberto,
                ];
            } catch (Throwable $exception) {
                $failed[] = [
                    'financeiro_id' => (int) $titulo->id,
                    'descricao' => (string) $titulo->descricao,
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        $valorBaixado = round(array_sum(array_column($succeeded, 'valor')), 2);

        // Um recibo por chamada bem-sucedida, valendo só o que ELA liquidou —
        // não o total da fatura. Se a fatura for paga aos poucos (retry de uma
        // baixa parcial, ou despesa lançada tarde numa fatura já fechada),
        // cada chamada gera seu próprio recibo, e a soma deles reconcilia com
        // a fatura. Nada pago nesta chamada = nenhum recibo de R$ 0,00.
        $agregadorId = $valorBaixado > 0
            ? $this->registerInvoicePaymentReceipt(
                $cartao,
                $dataVencimento,
                $valorBaixado,
                $dataPagamento,
                $payload,
                count($succeeded)
            )->id
            : null;

        return [
            'result' => $failed === [] ? 'ok' : 'partial',
            'succeeded' => $succeeded,
            'failed' => $failed,
            'succeeded_count' => count($succeeded),
            'failed_count' => count($failed),
            'valor_baixado' => $valorBaixado,
            'financeiro_agregador_id' => $agregadorId,
        ];
    }

    /**
     * Lança numa fatura JÁ PAGA uma compra que o banco cobrou mas ninguém
     * registrou aqui — o caso "esqueci de lançar e só percebi depois de pagar".
     *
     * A despesa nasce já quitada, com movimento na mesma data/forma/conta em
     * que a fatura foi paga: aquele dinheiro saiu junto com o resto da fatura,
     * então marcá-la pendente inventaria uma dívida que não existe e reabriria
     * uma fatura que o banco já cobrou. O total da fatura se corrige e ela
     * continua "Paga".
     *
     * É a válvula de escape do bloqueio de resolveClassification() (compra não
     * entra em fatura paga): em vez de obrigar a cancelar a baixa, lançar e
     * pagar de novo, entra por aqui — explicitamente, e só para fatura paga.
     *
     * @param  array<string, mixed>  $payload
     */
    public function registerForgottenExpense(
        FinanceiroCartaoCredito $cartao,
        string $dataVencimento,
        array $payload
    ): Financeiro {
        if (! $this->isInvoiceSettled($cartao, $dataVencimento)) {
            throw new RuntimeException(
                'Esta fatura ainda não foi paga — lance a despesa pelo cadastro normal, em Novo lançamento.'
            );
        }

        $dataCompra = trim((string) ($payload['data_compra'] ?? ''));
        if ($dataCompra === '') {
            throw new RuntimeException('Informe a data da compra.');
        }

        // A compra tem que pertencer ao ciclo DESTA fatura: sem isso o título
        // ficaria com data_vencimento de uma fatura e data_compra de outra, e a
        // trilha "em qual fatura isso caiu" deixaria de fechar.
        $cicloDaCompra = $this->resolveInvoiceCycle($cartao, CarbonImmutable::parse($dataCompra))['data_vencimento'];
        if ($cicloDaCompra !== $dataVencimento) {
            throw new RuntimeException(sprintf(
                'A compra de %s cai na fatura que vence em %s, não nesta. Escolha uma data entre %s e %s.',
                CarbonImmutable::parse($dataCompra)->format('d/m/Y'),
                CarbonImmutable::parse($cicloDaCompra)->format('d/m/Y'),
                CarbonImmutable::parse($this->openingDateForDueDate($cartao, CarbonImmutable::parse($dataVencimento)))
                    ->format('d/m/Y'),
                CarbonImmutable::parse($this->closingDateForDueDate($cartao, CarbonImmutable::parse($dataVencimento)))
                    ->format('d/m/Y')
            ));
        }

        $baixa = $this->settlementReferenceFor($cartao, $dataVencimento);

        return DB::transaction(function () use ($cartao, $dataVencimento, $dataCompra, $payload, $baixa): Financeiro {
            $valor = round((float) ($payload['valor'] ?? 0), 2);

            $despesa = Financeiro::create([
                'tipo' => Financeiro::TIPO_PAGAR,
                'avulso' => true,
                'fornecedor_id' => ! empty($payload['fornecedor_id']) ? (int) $payload['fornecedor_id'] : null,
                'cartao_credito_id' => $cartao->id,
                'cartao_modalidade' => self::MODALIDADE_CREDITO,
                'categoria' => (string) $payload['categoria'],
                'descricao' => (string) $payload['descricao'],
                'valor' => $valor,
                'status' => Financeiro::STATUS_PAGO,
                'data_compra' => $dataCompra,
                'data_vencimento' => $dataVencimento,
                // A despesa foi incorrida na compra, não no pagamento da fatura
                // — mesma regra do lançamento normal de cartão.
                'data_competencia' => $dataCompra,
                'data_pagamento' => $baixa['data_movimento'],
                'forma_pagamento' => $baixa['forma_pagamento_enum'],
                'dre_fixo_mensal' => filter_var($payload['dre_fixo_mensal'] ?? false, FILTER_VALIDATE_BOOL),
                'impacta_dre' => true,
                'impacta_fluxo_caixa' => true,
                'observacoes' => $this->nullableString($payload['observacoes'] ?? null),
            ]);

            // Movimento na mesma data/conta da baixa da fatura: o dinheiro saiu
            // junto com ela, então é lá que precisa aparecer no fluxo de caixa.
            FinanceiroMovimento::create([
                'financeiro_id' => $despesa->id,
                'conta_financeira_id' => $baixa['conta_financeira_id'],
                'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
                'data_movimento' => $baixa['data_movimento'],
                'valor_movimento' => $valor,
                'forma_pagamento' => $baixa['forma_pagamento'],
                'observacoes' => 'Despesa lançada depois do pagamento da fatura, quitada junto com ela.',
            ]);

            return $despesa;
        });
    }

    /**
     * Como a fatura foi paga: data, forma e conta do movimento mais recente
     * entre as despesas dela. É o que a despesa esquecida precisa copiar para
     * cair no mesmo ponto do fluxo de caixa que o resto da fatura.
     *
     * @return array{data_movimento: string, forma_pagamento: ?string, forma_pagamento_enum: ?string, conta_financeira_id: ?int}
     */
    private function settlementReferenceFor(FinanceiroCartaoCredito $cartao, string $dataVencimento): array
    {
        $movimento = FinanceiroMovimento::query()
            ->whereIn('financeiro_id', Financeiro::query()
                ->where('cartao_credito_id', $cartao->id)
                ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
                ->whereDate('data_vencimento', $dataVencimento)
                ->select('id'))
            ->orderByDesc('data_movimento')
            ->orderByDesc('id')
            ->first();

        $forma = $movimento?->forma_pagamento;

        return [
            'data_movimento' => $movimento?->data_movimento?->toDateString() ?? $dataVencimento,
            'forma_pagamento' => $forma,
            // financeiro.forma_pagamento é ENUM do banco legado; o movimento
            // aceita texto livre. Mesmo filtro de registerInvoicePaymentReceipt().
            'forma_pagamento_enum' => in_array((string) $forma, FinanceiroFormaPagamento::summaryCodes(), true)
                ? $forma
                : null,
            'conta_financeira_id' => $movimento?->conta_financeira_id !== null
                ? (int) $movimento->conta_financeira_id
                : null,
        ];
    }

    /**
     * Estorna a baixa em lote de uma fatura: devolve cada despesa liquidada
     * para "pendente" (apagando os movimentos que a baixa criou) e cancela os
     * recibos daquela fatura. A fatura volta a ficar "aberta" e pode ser paga
     * de novo — a nova baixa gera um recibo novo, e o cancelado fica no
     * histórico.
     *
     * Estorna a despesa em vez de cancelá-la: a compra continua devida, o que
     * deixou de valer foi o pagamento. Cancelar o título (ver
     * FinanceiroService::cancel()) é outra operação, com outro significado.
     *
     * Como a baixa individual é bloqueada para despesa de crédito (ver
     * FinanceiroController::resolveIsCartaoFatura()), todo movimento nesses
     * títulos veio de um payInvoice() — por isso apagar todos é o estorno
     * completo, sem risco de derrubar uma baixa avulsa legítima.
     *
     * @return array<string, mixed>
     */
    public function cancelInvoicePayment(FinanceiroCartaoCredito $cartao, string $dataVencimento): array
    {
        $titulos = Financeiro::query()
            ->where('cartao_credito_id', $cartao->id)
            ->where('cartao_modalidade', self::MODALIDADE_CREDITO)
            ->whereDate('data_vencimento', $dataVencimento)
            ->whereIn('status', [Financeiro::STATUS_PAGO, Financeiro::STATUS_PARCIAL])
            ->orderBy('id')
            ->get();

        if ($titulos->isEmpty()) {
            throw new RuntimeException('Esta fatura não tem baixa registrada para cancelar.');
        }

        return DB::transaction(function () use ($cartao, $dataVencimento, $titulos): array {
            $valorEstornado = 0.0;

            foreach ($titulos as $titulo) {
                $movimentoIds = $titulo->movimentos()->pluck('id');

                // Defensivo: payInvoice() nunca passa operadora_id, então não
                // gera despesa de taxa. Mas um título antigo, pago
                // individualmente antes do bloqueio da baixa avulsa, pode ter
                // uma — e ela não pode ficar órfã pesando no DRE depois que a
                // baixa que a originou sumiu. Mesmo tratamento de
                // FinanceiroService::cancel().
                if ($movimentoIds->isNotEmpty()) {
                    Financeiro::query()
                        ->where('origem_tipo', 'financeiro_movimento_cartao')
                        ->whereIn('origem_id', $movimentoIds)
                        ->where('status', '!=', Financeiro::STATUS_CANCELADO)
                        ->get()
                        ->each(function (Financeiro $taxa): void {
                            $taxa->movimentos()->delete();
                            $taxa->update([
                                'status' => Financeiro::STATUS_CANCELADO,
                                'data_pagamento' => null,
                                'forma_pagamento' => null,
                            ]);
                        });
                }

                $valorEstornado += round((float) $titulo->movimentos()->sum('valor_movimento'), 2);

                $titulo->movimentos()->delete();
                $titulo->update([
                    'status' => Financeiro::STATUS_PENDENTE,
                    'data_pagamento' => null,
                    'forma_pagamento' => null,
                ]);
            }

            // Os recibos daquela fatura deixam de valer junto. Ficam como
            // cancelados (não são apagados) para o histórico continuar
            // mostrando que houve um pagamento e que ele foi estornado.
            $recibosCancelados = 0;
            Financeiro::query()
                ->where('cartao_credito_id', $cartao->id)
                ->where('origem_tipo', Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO)
                ->whereDate('data_vencimento', $dataVencimento)
                ->where('status', '!=', Financeiro::STATUS_CANCELADO)
                ->get()
                ->each(function (Financeiro $recibo) use (&$recibosCancelados): void {
                    $recibo->movimentos()->delete();
                    $recibo->update([
                        'status' => Financeiro::STATUS_CANCELADO,
                        'data_pagamento' => null,
                        'forma_pagamento' => null,
                    ]);
                    $recibosCancelados++;
                });

            return [
                'despesas_estornadas' => $titulos->count(),
                'recibos_cancelados' => $recibosCancelados,
                'valor_estornado' => round($valorEstornado, 2),
            ];
        });
    }

    /**
     * Registra o pagamento da fatura como lançamento PRÓPRIO — o "recibo" do
     * que esta chamada de payInvoice() liquidou. Sem isso, o dinheiro que saiu
     * da conta para pagar N despesas nunca aparecia como título nenhum na tela
     * de Lançamentos: só as despesas individuais que o compõem apareciam.
     *
     * Não é despesa fixa nem variável — é um agregador. Por isso:
     * - cartao_modalidade fica NULL (nunca 'credito'): invoiceList(),
     *   payInvoice() e openBalanceByDueDate() filtram por
     *   cartao_modalidade='credito', então este recibo nunca entra na própria
     *   fatura que resume, nem seria reprocessado por um payInvoice() futuro.
     * - impacta_dre=false e impacta_fluxo_caixa=false: o custo e a saída de
     *   caixa já foram contados uma vez em cada despesa individual (cada uma
     *   com seu próprio movimento). Contar de novo aqui dobraria o DRE, o
     *   Fluxo de Caixa e Contas e Saldos.
     * - origem_tipo marca a linha para FinanceiroService::totaisFixoVariavel()
     *   e Financeiro::scopeWithFilters() a excluírem do fixo x variável.
     *
     * cartao_credito_id fica preenchido de propósito (mesmo com modalidade
     * NULL): é o que faz a badge "Cartão" da listagem aparecer e linkar de
     * volta para esta fatura, sem precisar tocar no template.
     *
     * Cria o título já no estado final (pago) + o movimento na mão, em vez de
     * passar por FinanceiroService::registerMovement(): mesmo padrão de
     * FinanceiroService::registerCardFeeExpense(), porque é um lançamento
     * sintético que nasce liquidado, sem os efeitos colaterais de uma baixa
     * iniciada pelo usuário (lock, OrderEvent, simulação de taxa).
     *
     * @param  array<string, mixed>  $payload
     */
    private function registerInvoicePaymentReceipt(
        FinanceiroCartaoCredito $cartao,
        string $dataVencimento,
        float $valorBaixado,
        string $dataPagamento,
        array $payload,
        int $quantidadeDespesas
    ): Financeiro {
        return DB::transaction(function () use ($cartao, $dataVencimento, $valorBaixado, $dataPagamento, $payload, $quantidadeDespesas): Financeiro {
            // financeiro.forma_pagamento é um ENUM restrito do banco, mas o
            // payload de payInvoice() aceita texto livre (formas
            // personalizadas do catálogo). Mesmo tratamento de
            // FinanceiroService::syncFromMovements(): a coluna ENUM só recebe
            // código conhecido; o texto original vai inteiro para
            // financeiro_movimentos.forma_pagamento, que é string comum.
            $formaPagamentoBruta = trim((string) ($payload['forma_pagamento'] ?? ''));
            $formaPagamentoEnum = in_array($formaPagamentoBruta, FinanceiroFormaPagamento::summaryCodes(), true)
                ? $formaPagamentoBruta
                : null;

            $agregador = Financeiro::create([
                'tipo' => Financeiro::TIPO_PAGAR,
                'avulso' => true,
                'cartao_credito_id' => $cartao->id,
                'cartao_modalidade' => null,
                'categoria' => 'Fatura de cartão de crédito',
                'descricao' => sprintf(
                    'Pagamento da fatura %s — venc. %s (%d %s)',
                    $cartao->nome,
                    CarbonImmutable::parse($dataVencimento)->format('d/m/Y'),
                    $quantidadeDespesas,
                    $quantidadeDespesas === 1 ? 'despesa' : 'despesas'
                ),
                'valor' => $valorBaixado,
                'status' => Financeiro::STATUS_PAGO,
                'data_vencimento' => $dataVencimento,
                'data_pagamento' => $dataPagamento,
                'data_competencia' => $dataPagamento,
                'forma_pagamento' => $formaPagamentoEnum,
                'origem_tipo' => Financeiro::ORIGEM_TIPO_FATURA_CARTAO_CREDITO,
                'impacta_dre' => false,
                'impacta_fluxo_caixa' => false,
                'dre_fixo_mensal' => false,
                'observacoes' => $this->nullableString($payload['observacoes'] ?? null),
            ]);

            FinanceiroMovimento::create([
                'financeiro_id' => $agregador->id,
                'tipo_movimento' => FinanceiroMovimento::TIPO_SAIDA,
                'data_movimento' => $dataPagamento,
                'valor_movimento' => $valorBaixado,
                'forma_pagamento' => $formaPagamentoBruta !== '' ? $formaPagamentoBruta : null,
                'observacoes' => 'Recibo gerado automaticamente pela baixa em lote da fatura.',
            ]);

            return $agregador;
        });
    }

    /**
     * Resolvido sob demanda (e não injetado no construtor) porque
     * FinanceiroService já depende deste serviço para calcular o ciclo no
     * save — injeção mútua no construtor viraria dependência circular.
     */
    private function financeiroService(): FinanceiroService
    {
        return app(FinanceiroService::class);
    }

    /** @return array<string, mixed> */
    private function serialize(FinanceiroCartaoCredito $cartao): array
    {
        $cartao->loadMissing('contaFinanceira');

        return [
            'id' => (int) $cartao->id,
            'nome' => (string) $cartao->nome,
            'instituicao' => $cartao->instituicao,
            'conta_financeira_id' => $cartao->conta_financeira_id,
            'conta_financeira_nome' => $cartao->contaFinanceira?->nome,
            'final_cartao' => $cartao->final_cartao,
            'dia_fechamento' => (int) $cartao->dia_fechamento,
            'dia_vencimento' => (int) $cartao->dia_vencimento,
            'cor' => (string) $cartao->cor,
            'ativo' => (bool) $cartao->ativo,
            'observacoes' => $cartao->observacoes,
        ];
    }

    private function normalizeDateFilter(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
