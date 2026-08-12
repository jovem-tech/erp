<?php

namespace App\Services\Sales;

use App\Models\CaixaSessao;
use App\Models\Financeiro;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SaleReturnPayment;
use App\Models\User;
use App\Services\Caixa\CaixaSessionService;
use App\Services\Financeiro\FinanceiroService;
use App\Support\CommercialAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Devolução e troca de venda — specs/029-devolucao-troca/spec.md.
 *
 * Uma venda pode ter várias devoluções parciais ao longo do tempo; este serviço
 * é o guardião do saldo devolvível e do rateio do reembolso.
 */
class SaleReturnService
{
    public function __construct(
        private readonly SaleStockService $saleStockService,
        private readonly FinanceiroService $financeiroService,
        private readonly CaixaSessionService $caixaSessionService
    ) {}

    /**
     * Saldo devolvível de cada item da venda.
     *
     * @return array<int, array<string, mixed>>
     */
    public function returnableItems(Sale $sale): array
    {
        $sale->loadMissing('items');

        $devolvido = DB::table('venda_devolucao_itens')
            ->join('venda_devolucoes', 'venda_devolucoes.id', '=', 'venda_devolucao_itens.venda_devolucao_id')
            ->where('venda_devolucoes.venda_id', (int) $sale->id)
            ->groupBy('venda_devolucao_itens.venda_item_id')
            ->selectRaw('venda_devolucao_itens.venda_item_id as item_id, SUM(venda_devolucao_itens.quantidade) as total')
            ->pluck('total', 'item_id');

        $ratio = $this->refundRatio($sale);

        return $sale->items->map(function (SaleItem $item) use ($devolvido, $ratio): array {
            $vendido = round((float) $item->quantidade, 3);
            $jaDevolvido = round((float) ($devolvido[$item->id] ?? 0), 3);
            $disponivel = max(0, round($vendido - $jaDevolvido, 3));
            $unitario = $vendido > 0 ? round((float) $item->total / $vendido, 2) : 0.0;

            return [
                'venda_item_id' => (int) $item->id,
                'descricao' => (string) $item->descricao,
                'codigo' => (string) ($item->codigo_snapshot ?? ''),
                'tipo_item' => (string) $item->tipo_item,
                'tipo_item_label' => SaleItem::typeLabel($item->tipo_item),
                'quantidade_vendida' => $vendido,
                'quantidade_devolvida' => $jaDevolvido,
                'quantidade_disponivel' => $disponivel,
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                // Quanto o cliente recebe por unidade, já com o desconto geral
                // da venda rateado.
                'reembolso_unitario' => round($unitario * $ratio, 2),
                // Só peça que baixou estoque na venda volta à prateleira.
                'retorna_estoque' => (bool) $item->baixa_estoque,
                'referencia_id' => $item->referencia_id !== null ? (int) $item->referencia_id : null,
            ];
        })->all();
    }

    /**
     * Devolver depois deste prazo exige credencial de administrador.
     */
    public function requiresAdminApproval(Sale $sale): bool
    {
        $data = $sale->data_venda;

        if ($data === null) {
            return false;
        }

        return $data->diffInDays(now()) > SaleReturn::PRAZO_LIVRE_DIAS;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(User $actor, Sale $sale, array $attributes, ?User $authorizedBy = null): array
    {
        if ($sale->isCancelled()) {
            throw new RuntimeException('Esta venda foi cancelada; não há o que devolver.');
        }

        $lines = $this->normalizeLines($sale, is_array($attributes['itens'] ?? null) ? $attributes['itens'] : []);

        if ($lines === []) {
            throw new RuntimeException('Informe ao menos um item devolvido.');
        }

        $idempotencyKey = trim((string) ($attributes['creation_request_id'] ?? ''));
        $fingerprint = hash('sha256', json_encode([
            'venda' => (int) $sale->id,
            'itens' => $lines,
        ], JSON_THROW_ON_ERROR));

        if ($idempotencyKey !== '') {
            $replay = $this->resolveCreationReplay($actor, $idempotencyKey, $fingerprint);

            if ($replay !== null) {
                return $replay;
            }
        }

        $devolucao = DB::transaction(function () use ($actor, $sale, $attributes, $lines, $idempotencyKey, $fingerprint, $authorizedBy): SaleReturn {
            $locked = Sale::query()->lockForUpdate()->findOrFail((int) $sale->id);

            // Revalida o saldo dentro da transação: duas devoluções simultâneas
            // da mesma venda não podem passar do que foi vendido.
            $this->assertLinesAreAvailable($locked, $lines);

            $ratio = $this->refundRatio($locked);
            $subtotal = 0.0;
            $credito = 0.0;
            $custo = 0.0;

            foreach ($lines as $index => $line) {
                $subtotal += $line['valor_total'];
                $custo += $line['custo_total'];
                $lines[$index]['valor_reembolsado'] = round($line['valor_total'] * $ratio, 2);
                $credito += $lines[$index]['valor_reembolsado'];
            }

            $subtotal = round($subtotal, 2);
            $credito = round($credito, 2);
            $custo = round($custo, 2);

            // Venda fiada: não se devolve dinheiro que o cliente nunca pagou.
            // O crédito que sobra abate a dívida em aberto.
            $disponivelParaReembolso = $this->refundableCash($locked);
            $reembolso = round(min($credito, $disponivelParaReembolso), 2);
            $abatimento = round($credito - $reembolso, 2);

            $devolucao = new SaleReturn();
            $devolucao->numero = $this->nextReturnNumber();
            $devolucao->venda_id = (int) $locked->id;
            $devolucao->status = SaleReturn::STATUS_CONCLUIDA;
            $devolucao->data_devolucao = now()->toDateString();
            $devolucao->motivo = trim((string) ($attributes['motivo'] ?? ''));
            $devolucao->subtotal_itens = $subtotal;
            $devolucao->valor_devolvido = $credito;
            $devolucao->valor_reembolsado = $reembolso;
            $devolucao->valor_abatido = $abatimento;
            $devolucao->custo_devolvido = $custo;
            $devolucao->criado_por = (int) $actor->id;
            $devolucao->autorizado_por = $authorizedBy?->id;

            if ($idempotencyKey !== '') {
                $devolucao->creation_request_id = $idempotencyKey;
                $devolucao->creation_request_fingerprint = $fingerprint;
            }

            $devolucao->save();

            $this->persistItems($devolucao, $lines);
            $this->creditStock($devolucao, $locked, $lines, (int) $actor->id);

            $taxaPerdida = $this->processRefund($devolucao, $locked, $reembolso, $actor);

            if ($abatimento > 0) {
                $this->reduceOpenReceivable($locked, $abatimento);
            }

            $devolucao->forceFill(['valor_taxa_nao_estornada' => $taxaPerdida])->save();

            $this->refreshSaleAfterReturn($locked);

            return $devolucao;
        });

        return [
            'result' => 'ok',
            'devolucao' => $this->mapDetail($this->loadOrFail((int) $devolucao->id)),
            'idempotent_replay' => false,
        ];
    }

    /**
     * Vincula a devolução à venda nova que o cliente levou no lugar.
     *
     * Troca é composição, não entidade nova: devolução + venda encadeadas.
     */
    public function linkExchange(SaleReturn $devolucao, Sale $novaVenda): SaleReturn
    {
        if ((int) ($devolucao->venda_troca_id ?? 0) > 0) {
            throw new RuntimeException('Esta devolução já está vinculada a uma troca.');
        }

        if ((int) $novaVenda->id === (int) $devolucao->venda_id) {
            throw new RuntimeException('A venda da troca não pode ser a mesma venda devolvida.');
        }

        $devolucao->forceFill(['venda_troca_id' => (int) $novaVenda->id])->save();

        return $devolucao->refresh();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SaleReturn>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = SaleReturn::query()->with(['sale.client', 'creator']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->whereRaw('LOWER(venda_devolucoes.numero) LIKE ?', [$like])
                    ->orWhereIn('venda_devolucoes.venda_id', Sale::query()
                        ->whereRaw('LOWER(numero) LIKE ?', [$like])
                        ->select('id'));
            });
        }

        $saleId = (int) ($filters['venda_id'] ?? 0);
        if ($saleId > 0) {
            $query->where('venda_id', $saleId);
        }

        $from = $this->normalizeDate($filters['data_inicio'] ?? null);
        if ($from !== null) {
            $query->whereDate('data_devolucao', '>=', $from);
        }

        $to = $this->normalizeDate($filters['data_fim'] ?? null);
        if ($to !== null) {
            $query->whereDate('data_devolucao', '<=', $to);
        }

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));

        $paginator = $query
            ->orderByDesc('data_devolucao')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(fn (SaleReturn $d): array => $this->mapSummary($d));

        return $paginator;
    }

    public function loadOrFail(int $id): SaleReturn
    {
        $devolucao = SaleReturn::query()
            ->with(['items.saleItem', 'payments', 'sale.client', 'creator', 'exchangeSale'])
            ->find($id);

        if (! $devolucao instanceof SaleReturn) {
            abort(404);
        }

        return $devolucao;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapDetail(SaleReturn $devolucao): array
    {
        return array_merge($this->mapSummary($devolucao), [
            'subtotal_itens' => round((float) $devolucao->subtotal_itens, 2),
            'custo_devolvido' => round((float) $devolucao->custo_devolvido, 2),
            'caixa_sessao_id' => $devolucao->caixa_sessao_id !== null ? (int) $devolucao->caixa_sessao_id : null,
            'financeiro_id' => $devolucao->financeiro_id !== null ? (int) $devolucao->financeiro_id : null,
            'venda_troca_id' => $devolucao->venda_troca_id !== null ? (int) $devolucao->venda_troca_id : null,
            'venda_troca_numero' => $devolucao->relationLoaded('exchangeSale')
                ? (string) ($devolucao->exchangeSale?->numero ?? '')
                : '',
            'itens' => $devolucao->items->map(static fn (SaleReturnItem $item): array => [
                'id' => (int) $item->id,
                'venda_item_id' => (int) $item->venda_item_id,
                'descricao' => (string) ($item->saleItem?->descricao ?? ''),
                'codigo' => (string) ($item->saleItem?->codigo_snapshot ?? ''),
                'quantidade' => round((float) $item->quantidade, 3),
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                'valor_total' => round((float) $item->valor_total, 2),
                'valor_reembolsado' => round((float) $item->valor_reembolsado, 2),
                'retorna_estoque' => (bool) $item->retorna_estoque,
                'observacoes' => $item->observacoes,
            ])->all(),
            'pagamentos' => $devolucao->payments->map(static fn (SaleReturnPayment $p): array => [
                'id' => (int) $p->id,
                'forma_pagamento' => (string) $p->forma_pagamento,
                'valor' => round((float) $p->valor, 2),
                'valor_taxa_nao_estornada' => round((float) $p->valor_taxa_nao_estornada, 2),
                'conta_financeira_id' => $p->conta_financeira_id !== null ? (int) $p->conta_financeira_id : null,
                'movimento_id' => $p->movimento_id !== null ? (int) $p->movimento_id : null,
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSummary(SaleReturn $devolucao): array
    {
        return [
            'id' => (int) $devolucao->id,
            'numero' => (string) $devolucao->numero,
            'status' => (string) $devolucao->status,
            'venda_id' => (int) $devolucao->venda_id,
            'venda_numero' => (string) ($devolucao->sale?->numero ?? ''),
            'cliente_nome' => $devolucao->sale?->customerName() ?? '',
            'data_devolucao' => $devolucao->data_devolucao?->toDateString(),
            'motivo' => (string) $devolucao->motivo,
            'valor_devolvido' => round((float) $devolucao->valor_devolvido, 2),
            'valor_reembolsado' => round((float) $devolucao->valor_reembolsado, 2),
            'valor_abatido' => round((float) $devolucao->valor_abatido, 2),
            'valor_taxa_nao_estornada' => round((float) $devolucao->valor_taxa_nao_estornada, 2),
            'criado_por_nome' => (string) ($devolucao->creator?->nome ?? ''),
            'created_at' => $devolucao->created_at?->toIso8601String(),
        ];
    }

    /* ------------------------------------------------------------------ */

    /**
     * Proporção entre o que o cliente pagou e o valor de lista dos itens.
     *
     * Numa venda de subtotal 100 com desconto geral de 10 (total 90), devolver
     * um item de 50 devolve 45 — que foi o que ele efetivamente pagou por ele.
     */
    private function refundRatio(Sale $sale): float
    {
        $subtotal = round((float) $sale->subtotal, 2);

        if ($subtotal <= 0) {
            return 1.0;
        }

        return round((float) $sale->total / $subtotal, 10);
    }

    /**
     * Dinheiro ainda reembolsável: o que foi pago menos o que já voltou em
     * devoluções anteriores.
     */
    private function refundableCash(Sale $sale): float
    {
        $jaReembolsado = (float) SaleReturn::query()
            ->where('venda_id', (int) $sale->id)
            ->sum('valor_reembolsado');

        return max(0, round((float) $sale->valor_pago - $jaReembolsado, 2));
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(Sale $sale, array $items): array
    {
        $disponivel = collect($this->returnableItems($sale))->keyBy('venda_item_id');
        $saleItems = $sale->items->keyBy('id');
        $lines = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemId = (int) ($item['venda_item_id'] ?? 0);
            $quantidade = CommercialAdjustment::decimal($item['quantidade'] ?? 0, 3);

            if ($itemId <= 0 || $quantidade <= 0) {
                continue;
            }

            $info = $disponivel->get($itemId);
            $saleItem = $saleItems->get($itemId);

            if ($info === null || ! $saleItem instanceof SaleItem) {
                throw new RuntimeException('Um dos itens informados não pertence a esta venda.');
            }

            if ($quantidade > (float) $info['quantidade_disponivel'] + 0.0001) {
                throw new RuntimeException(sprintf(
                    'Só é possível devolver %s de "%s" (já devolvido: %s).',
                    rtrim(rtrim(number_format((float) $info['quantidade_disponivel'], 3, ',', '.'), '0'), ','),
                    $info['descricao'],
                    rtrim(rtrim(number_format((float) $info['quantidade_devolvida'], 3, ',', '.'), '0'), ',')
                ));
            }

            $vendida = round((float) $saleItem->quantidade, 3);
            $unitario = $vendida > 0 ? round((float) $saleItem->total / $vendida, 2) : 0.0;
            $custoUnitario = round((float) $saleItem->custo_unitario, 2);
            $retornaEstoque = (bool) $saleItem->baixa_estoque;

            // Estoque conta em unidades inteiras (movimentacoes.quantidade é INT).
            if ($retornaEstoque && floor($quantidade) !== $quantidade) {
                throw new RuntimeException(
                    'Itens que voltam ao estoque precisam de quantidade inteira: "'.$saleItem->descricao.'".'
                );
            }

            $lines[] = [
                'venda_item_id' => $itemId,
                'peca_id' => (int) ($saleItem->referencia_id ?? 0),
                'quantidade' => $quantidade,
                'valor_unitario' => round((float) $saleItem->valor_unitario, 2),
                'valor_total' => round($unitario * $quantidade, 2),
                'custo_unitario' => $custoUnitario,
                'custo_total' => round($custoUnitario * $quantidade, 2),
                'retorna_estoque' => $retornaEstoque,
                'observacoes' => trim((string) ($item['observacoes'] ?? '')) ?: null,
            ];
        }

        return $lines;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertLinesAreAvailable(Sale $sale, array $lines): void
    {
        $disponivel = collect($this->returnableItems($sale))->keyBy('venda_item_id');

        foreach ($lines as $line) {
            $info = $disponivel->get($line['venda_item_id']);

            if ($info === null || (float) $line['quantidade'] > (float) $info['quantidade_disponivel'] + 0.0001) {
                throw new RuntimeException(
                    'O saldo devolvível mudou enquanto a devolução era registrada. Refaça a operação.'
                );
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function persistItems(SaleReturn $devolucao, array $lines): void
    {
        $now = now();
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'venda_devolucao_id' => (int) $devolucao->id,
                'venda_item_id' => $line['venda_item_id'],
                'quantidade' => $line['quantidade'],
                'valor_unitario' => $line['valor_unitario'],
                'valor_total' => $line['valor_total'],
                'valor_reembolsado' => $line['valor_reembolsado'],
                'custo_unitario' => $line['custo_unitario'],
                'custo_total' => $line['custo_total'],
                'retorna_estoque' => $line['retorna_estoque'],
                'observacoes' => $line['observacoes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SaleReturnItem::query()->insert($rows);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function creditStock(SaleReturn $devolucao, Sale $sale, array $lines, int $actorId): void
    {
        $stockLines = [];

        foreach ($lines as $line) {
            if (! $line['retorna_estoque'] || $line['peca_id'] <= 0) {
                continue;
            }

            $stockLines[] = [
                'peca_id' => $line['peca_id'],
                'venda_item_id' => $line['venda_item_id'],
                'quantidade' => (int) round($line['quantidade']),
            ];
        }

        if ($stockLines === []) {
            return;
        }

        $this->saleStockService->creditForReturn($sale, (string) $devolucao->numero, $stockLines, $actorId);
    }

    /**
     * Devolve o dinheiro pela mesma forma em que entrou e retorna a taxa de
     * cartão que a operadora não estorna.
     */
    private function processRefund(SaleReturn $devolucao, Sale $sale, float $reembolso, User $actor): float
    {
        if ($reembolso <= 0) {
            return 0.0;
        }

        $allocations = $this->allocateRefund($sale, $reembolso);

        if ($allocations === []) {
            return 0.0;
        }

        $temDinheiro = collect($allocations)->contains(
            static fn (array $a): bool => $a['forma_pagamento'] === 'dinheiro'
        );

        // Dinheiro sai da gaveta de AGORA, não do turno em que a venda ocorreu.
        $sessao = $temDinheiro ? $this->caixaSessionService->ensureOpenSessionOrNull($actor) : null;

        if ($sessao instanceof CaixaSessao) {
            $devolucao->forceFill(['caixa_sessao_id' => (int) $sessao->id])->save();
        }

        $titulo = $this->createPayable($devolucao, $sale, $reembolso);
        $devolucao->forceFill(['financeiro_id' => (int) $titulo->id])->save();

        $now = now();
        $taxaPerdida = 0.0;
        $rows = [];

        foreach ($allocations as $allocation) {
            $conta = $allocation['conta_financeira_id'];

            if ($conta === null && $allocation['forma_pagamento'] === 'dinheiro' && $sessao instanceof CaixaSessao) {
                $conta = (int) $sessao->conta_financeira_id;
            }

            // A baixa do título a pagar é a saída do dinheiro. `operadora_id`
            // não é repassado de propósito: a taxa de cartão já foi lançada
            // como despesa na venda e NÃO deve ser cobrada de novo aqui.
            $summary = $this->financeiroService->registerMovement($titulo, [
                'valor_movimento' => $allocation['valor'],
                'forma_pagamento' => $allocation['forma_pagamento'],
                'conta_financeira_id' => $conta,
                'data_movimento' => $devolucao->data_devolucao?->toDateString() ?? $now->toDateString(),
                'documento_ref' => 'Devolução '.$devolucao->numero,
                'observacoes' => 'Estorno da venda '.$sale->numero,
            ]);

            $taxaPerdida += $allocation['valor_taxa_nao_estornada'];

            $rows[] = [
                'venda_devolucao_id' => (int) $devolucao->id,
                'venda_pagamento_id' => $allocation['venda_pagamento_id'],
                'forma_pagamento' => $allocation['forma_pagamento'],
                'conta_financeira_id' => $conta,
                'valor' => $allocation['valor'],
                'valor_taxa_nao_estornada' => $allocation['valor_taxa_nao_estornada'],
                'movimento_id' => ! empty($summary['movement_id']) ? (int) $summary['movement_id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SaleReturnPayment::query()->insert($rows);

        return round($taxaPerdida, 2);
    }

    /**
     * Rateia o reembolso entre os pagamentos originais, proporcionalmente ao
     * valor de cada um e limitado ao que ainda resta reembolsar em cada forma.
     *
     * A sobra de arredondamento vai para a maior parcela, para o rateio fechar
     * no centavo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function allocateRefund(Sale $sale, float $reembolso): array
    {
        $sale->loadMissing('payments');

        $jaDevolvidoPorPagamento = DB::table('venda_devolucao_pagamentos')
            ->join('venda_devolucoes', 'venda_devolucoes.id', '=', 'venda_devolucao_pagamentos.venda_devolucao_id')
            ->where('venda_devolucoes.venda_id', (int) $sale->id)
            ->whereNotNull('venda_devolucao_pagamentos.venda_pagamento_id')
            ->groupBy('venda_devolucao_pagamentos.venda_pagamento_id')
            ->selectRaw('venda_devolucao_pagamentos.venda_pagamento_id as pid, SUM(venda_devolucao_pagamentos.valor) as total')
            ->pluck('total', 'pid');

        $candidatos = $sale->payments
            ->map(function (SalePayment $payment) use ($jaDevolvidoPorPagamento): array {
                $pago = round((float) $payment->valor, 2);
                $devolvido = round((float) ($jaDevolvidoPorPagamento[$payment->id] ?? 0), 2);

                return [
                    'payment' => $payment,
                    'disponivel' => max(0, round($pago - $devolvido, 2)),
                    'pago' => $pago,
                ];
            })
            ->filter(static fn (array $c): bool => $c['disponivel'] > 0)
            ->values();

        if ($candidatos->isEmpty()) {
            return [];
        }

        $totalDisponivel = round((float) $candidatos->sum('disponivel'), 2);
        $restante = round(min($reembolso, $totalDisponivel), 2);
        $allocations = [];
        $alocado = 0.0;

        foreach ($candidatos as $index => $candidato) {
            $payment = $candidato['payment'];
            $isUltimo = $index === $candidatos->count() - 1;

            $valor = $isUltimo
                ? round($restante - $alocado, 2)
                : round($restante * ($candidato['disponivel'] / $totalDisponivel), 2);

            $valor = round(min($valor, $candidato['disponivel']), 2);

            if ($valor <= 0) {
                continue;
            }

            $alocado = round($alocado + $valor, 2);

            // Taxa proporcional ao que está voltando daquele cartão. Apenas
            // informativa: a despesa já existe desde a venda.
            $taxa = $candidato['pago'] > 0
                ? round((float) $payment->valor_taxa * ($valor / $candidato['pago']), 2)
                : 0.0;

            $allocations[] = [
                'venda_pagamento_id' => (int) $payment->id,
                'forma_pagamento' => (string) $payment->forma_pagamento,
                'conta_financeira_id' => $payment->conta_financeira_id !== null
                    ? (int) $payment->conta_financeira_id
                    : null,
                'valor' => $valor,
                'valor_taxa_nao_estornada' => $taxa,
            ];
        }

        // Sobra de centavo por arredondamento vai para a maior parcela.
        $diferenca = round($restante - $alocado, 2);

        if (abs($diferenca) >= 0.01 && $allocations !== []) {
            $maiorIndex = 0;
            foreach ($allocations as $i => $a) {
                if ($a['valor'] > $allocations[$maiorIndex]['valor']) {
                    $maiorIndex = $i;
                }
            }
            $allocations[$maiorIndex]['valor'] = round($allocations[$maiorIndex]['valor'] + $diferenca, 2);
        }

        return $allocations;
    }

    private function createPayable(SaleReturn $devolucao, Sale $sale, float $valor): Financeiro
    {
        $clientId = (int) ($sale->cliente_id ?? 0);

        return $this->financeiroService->create([
            'venda_id' => (int) $sale->id,
            // Título a pagar não aceita cliente (resolveClassification limpa o
            // campo), então o vínculo com a venda é o que dá rastreabilidade.
            'avulso' => $clientId <= 0,
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => SaleReturn::FINANCE_CATEGORY,
            'descricao' => 'Devolução '.$devolucao->numero.' da venda '.$sale->numero,
            'valor' => $valor,
            'data_vencimento' => $devolucao->data_devolucao?->toDateString() ?? now()->toDateString(),
            'data_competencia' => $devolucao->data_devolucao?->toDateString() ?? now()->toDateString(),
            'origem_tipo' => 'venda_devolucao',
            'observacoes' => 'Estorno gerado pela devolução '.$devolucao->numero.'.',
        ]);
    }

    /**
     * Abate a dívida em aberto quando o cliente devolve algo que ainda não
     * tinha pago (venda fiada).
     */
    private function reduceOpenReceivable(Sale $sale, float $abatimento): void
    {
        $titulo = Financeiro::query()
            ->where('venda_id', (int) $sale->id)
            ->where('tipo', Financeiro::TIPO_RECEBER)
            ->where('status', '!=', Financeiro::STATUS_CANCELADO)
            ->first();

        if (! $titulo instanceof Financeiro) {
            return;
        }

        $novoValor = max(0, round((float) $titulo->valor - $abatimento, 2));

        $this->financeiroService->update($titulo, ['valor' => $novoValor]);
    }

    /**
     * Recalcula os totais da venda depois da devolução, para a listagem e os
     * relatórios refletirem o que de fato ficou com o cliente.
     */
    private function refreshSaleAfterReturn(Sale $sale): void
    {
        $devolvido = (float) SaleReturn::query()->where('venda_id', (int) $sale->id)->sum('valor_devolvido');
        $custoDevolvido = (float) SaleReturn::query()->where('venda_id', (int) $sale->id)->sum('custo_devolvido');

        $totalLiquido = max(0, round((float) $sale->total - $devolvido, 2));
        $custoLiquido = max(0, round((float) $sale->custo_total - $custoDevolvido, 2));
        $margem = round($totalLiquido - $custoLiquido, 2);

        $sale->forceFill([
            'total_devolvido' => round($devolvido, 2),
            'margem_valor' => $margem,
            'margem_percentual' => $totalLiquido > 0 ? round(($margem / $totalLiquido) * 100, 2) : 0.0,
        ])->save();
    }

    /**
     * Numeração DV-YYMM-NNNNNN, com o mesmo cuidado de corrida da venda.
     */
    private function nextReturnNumber(): string
    {
        $prefix = 'DV-'.now()->format('ym').'-';

        $last = SaleReturn::query()
            ->where('numero', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('numero');

        $sequence = 1;
        if (is_string($last) && Str::startsWith($last, $prefix)) {
            $sequence = max(1, (int) substr($last, strlen($prefix)) + 1);
        }

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCreationReplay(User $actor, string $key, string $fingerprint): ?array
    {
        $devolucao = SaleReturn::query()->where('creation_request_id', $key)->first();

        if (! $devolucao instanceof SaleReturn) {
            return null;
        }

        if (! hash_equals((string) ($devolucao->creation_request_fingerprint ?? ''), $fingerprint)) {
            return ['result' => 'idempotency_conflict'];
        }

        return [
            'result' => 'ok',
            'devolucao' => $this->mapDetail($this->loadOrFail((int) $devolucao->id)),
            'idempotent_replay' => true,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
