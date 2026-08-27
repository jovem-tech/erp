<?php

namespace App\Services\Sales;

use App\Models\Client;
use App\Models\Peca;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Servico;
use App\Models\User;
use App\Services\Caixa\CaixaSessionService;
use App\Support\CommercialAdjustment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orquestra a venda de balcão de ponta a ponta.
 *
 * Uma venda nasce concluída e imutável: itens, estoque e financeiro entram numa
 * única transação. Ver specs/027-vendas-balcao-pdv/spec.md.
 */
class SaleWorkflowService
{
    public function __construct(
        private readonly SaleStockService $saleStockService,
        private readonly SalePaymentService $salePaymentService,
        private readonly CaixaSessionService $caixaSessionService
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $payments
     */
    private function hasCashPayment(array $payments): bool
    {
        foreach ($payments as $payment) {
            if (trim((string) ($payment['forma_pagamento'] ?? '')) === 'dinheiro') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function create(User $actor, array $attributes): array
    {
        $items = $this->normalizeItems(is_array($attributes['itens'] ?? null) ? $attributes['itens'] : []);

        if ($items === []) {
            throw new RuntimeException('Inclua ao menos um item na venda.');
        }

        $payments = $this->normalizePayments(
            is_array($attributes['pagamentos'] ?? null) ? $attributes['pagamentos'] : [],
            $attributes['data_venda'] ?? null
        );

        $idempotencyKey = trim((string) ($attributes['creation_request_id'] ?? ''));
        $fingerprint = $this->fingerprint($items, $payments, $attributes);

        if ($idempotencyKey !== '') {
            $replay = $this->resolveCreationReplay($actor, $idempotencyKey, $fingerprint);

            if ($replay !== null) {
                return $replay;
            }
        }

        // Fora da transação de propósito: recusar uma taxa inexistente antes de
        // baixar estoque é mais barato do que estornar depois.
        $simulations = $this->salePaymentService->simulateCards($payments);

        $allowNegative = filter_var(
            $attributes['confirmar_estoque_insuficiente'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        if (! $allowNegative) {
            $shortages = $this->saleStockService->previewShortages($items);

            if ($shortages !== []) {
                throw new InsufficientStockException($shortages);
            }
        }

        $sale = DB::transaction(function () use ($actor, $attributes, $items, $payments, $simulations, $allowNegative, $idempotencyKey, $fingerprint): Sale {
            $sale = $this->persistSale($actor, $attributes, $idempotencyKey, $fingerprint);

            $persistedItems = $this->syncItems($sale, $items);
            $this->recalculateFinancials($sale, $attributes, $persistedItems);

            $this->saleStockService->debitForSale($sale, $persistedItems, (int) $actor->id, $allowNegative);

            // Venda em dinheiro pertence a um turno de caixa
            // (specs/028-caixa-sessoes). Se o caixa estiver fechado, a primeira
            // venda o abre — bloquear travaria o balcão por esquecimento.
            $session = $this->hasCashPayment($payments)
                ? $this->caixaSessionService->ensureOpenSessionOrNull($actor)
                : null;

            if ($session !== null) {
                $sale->forceFill(['caixa_sessao_id' => (int) $session->id])->save();
            }

            $summary = $this->salePaymentService->process(
                $sale,
                $payments,
                $simulations,
                (int) $actor->id,
                $session !== null ? (int) $session->conta_financeira_id : null
            );

            $sale->forceFill([
                'valor_pago' => (float) $summary['valor_pago'],
                'status_pagamento' => (string) $summary['status_pagamento'],
                'concluida_em' => now(),
            ])->save();

            return $sale;
        });

        return [
            'result' => 'ok',
            'sale' => $this->mapDetail($this->loadSaleOrFail((int) $sale->id)),
            'idempotent_replay' => false,
        ];
    }

    /**
     * Cancela a venda, estornando estoque e financeiro.
     *
     * @return array<string, mixed>
     */
    public function cancel(User $actor, Sale $sale, string $reason): array
    {
        if ($sale->isCancelled()) {
            throw new RuntimeException('Esta venda já está cancelada.');
        }

        DB::transaction(function () use ($actor, $sale, $reason): void {
            $locked = Sale::query()->lockForUpdate()->findOrFail((int) $sale->id);

            if ($locked->isCancelled()) {
                throw new RuntimeException('Esta venda já está cancelada.');
            }

            $this->salePaymentService->cancelForSale($locked);
            $this->saleStockService->creditForSaleCancellation($locked, (int) $actor->id);

            $locked->forceFill([
                'status' => Sale::STATUS_CANCELLED,
                'status_pagamento' => Sale::PAYMENT_STATUS_PENDING,
                'valor_pago' => 0,
                'cancelado_em' => now(),
                'cancelado_por' => (int) $actor->id,
                'motivo_cancelamento' => $reason,
            ])->save();
        });

        return [
            'result' => 'ok',
            'sale' => $this->mapDetail($this->loadSaleOrFail((int) $sale->id)),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{paginator: LengthAwarePaginator, summary: array<string, mixed>}
     */
    public function paginate(array $filters): array
    {
        $query = $this->baseQuery($filters);

        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 15)));

        $paginator = (clone $query)
            ->orderByDesc('data_venda')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $paginator->getCollection()->transform(fn (Sale $sale): array => $this->mapSummary($sale));

        return [
            'paginator' => $paginator,
            'summary' => $this->summarize($filters),
        ];
    }

    /**
     * Totais do período filtrado, para os cards da listagem.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(array $filters): array
    {
        $aggregate = $this->baseQuery($filters)
            ->where('vendas.status', Sale::STATUS_COMPLETED)
            ->selectRaw('COUNT(*) as total_vendas')
            ->selectRaw('COALESCE(SUM(vendas.total), 0) as total_vendido')
            ->selectRaw('COALESCE(SUM(vendas.custo_total), 0) as total_custo')
            ->selectRaw('COALESCE(SUM(vendas.margem_valor), 0) as total_margem')
            ->selectRaw('COALESCE(SUM(vendas.valor_pago), 0) as total_recebido')
            ->first();

        $count = (int) ($aggregate->total_vendas ?? 0);
        $revenue = round((float) ($aggregate->total_vendido ?? 0), 2);
        $margin = round((float) ($aggregate->total_margem ?? 0), 2);

        return [
            'total_vendas' => $count,
            'total_vendido' => $revenue,
            'total_custo' => round((float) ($aggregate->total_custo ?? 0), 2),
            'total_margem' => $margin,
            'total_recebido' => round((float) ($aggregate->total_recebido ?? 0), 2),
            'ticket_medio' => $count > 0 ? round($revenue / $count, 2) : 0.0,
            'margem_percentual' => $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0.0,
        ];
    }

    /**
     * Busca de itens do PDV: nome, código interno ou código de barras.
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchItems(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $like = '%'.mb_strtolower($term).'%';

        $parts = Peca::query()
            ->where('ativo', 1)
            ->where(function (Builder $builder) use ($like, $term): void {
                $builder
                    ->whereRaw('LOWER(COALESCE(nome, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(codigo, "")) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(codigo_fabricante, "")) LIKE ?', [$like])
                    // Código de barras casa exato: leitor não digita parcial.
                    ->orWhere('codigo_barras', $term);
            })
            ->orderBy('nome')
            ->limit($limit)
            ->get();

        $services = Servico::query()
            ->where('status', 'ativo')
            ->whereRaw('LOWER(COALESCE(nome, "")) LIKE ?', [$like])
            ->orderBy('nome')
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($parts as $part) {
            $sale = (float) ($part->preco_venda ?? 0);
            $cost = (float) ($part->preco_custo ?? 0);

            $results[] = [
                'tipo_item' => SaleItem::TYPE_PART,
                'referencia_id' => (int) $part->id,
                'codigo' => (string) ($part->codigo ?? ''),
                'codigo_barras' => (string) ($part->codigo_barras ?? ''),
                'descricao' => (string) ($part->nome ?? ''),
                'categoria' => (string) ($part->categoria ?? ''),
                'unidade' => (string) ($part->unidade ?? 'UN'),
                'valor_unitario' => $sale > 0 ? $sale : $cost,
                'custo_unitario' => $cost,
                // float: saldo 1,25 aparecia como 1 na busca do PDV.
            'saldo' => (float) ($part->quantidade_atual ?? 0),
                'controla_estoque' => true,
            ];
        }

        foreach ($services as $service) {
            $results[] = [
                'tipo_item' => SaleItem::TYPE_SERVICE,
                'referencia_id' => (int) $service->id,
                'codigo' => '',
                'codigo_barras' => '',
                'descricao' => (string) ($service->nome ?? ''),
                'categoria' => 'Serviço',
                'unidade' => 'UN',
                'valor_unitario' => (float) ($service->valor ?? 0),
                'custo_unitario' => (float) ($service->custo_direto_padrao ?? 0),
                'saldo' => null,
                'controla_estoque' => false,
            ];
        }

        return $results;
    }

    /**
     * Seletor de cliente do PDV.
     *
     * Mesma consulta de BudgetWorkflowService::paginateClientOptions(), repetida
     * aqui de propósito: injetar o serviço de orçamentos no controller de vendas
     * criaria acoplamento entre dois domínios só por um autocomplete. Orçamentos
     * e OS já convivem com essa duplicação pelo mesmo motivo.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Client>
     */
    public function paginateClientOptions(array $filters = []): LengthAwarePaginator
    {
        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(20, (int) ($filters['per_page'] ?? 15)));

        $query = Client::query()->select(['id', 'nome_razao', 'cpf_cnpj', 'telefone1', 'email']);

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';

            $query->where(static function (Builder $builder) use ($term): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(nome_razao, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(cpf_cnpj, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(telefone1, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ?", [$term]);
            });
        }

        return $query
            ->orderBy('nome_razao')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function loadSaleOrFail(int $saleId): Sale
    {
        $sale = Sale::query()
            ->with(['items', 'payments', 'client', 'seller', 'order', 'receivable'])
            ->find($saleId);

        if (! $sale instanceof Sale) {
            abort(404);
        }

        return $sale;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapDetail(Sale $sale): array
    {
        $summary = $this->mapSummary($sale);

        return array_merge($summary, [
            'cliente_documento_avulso' => $sale->cliente_documento_avulso,
            'telefone_contato' => $sale->telefone_contato,
            'email_contato' => $sale->email_contato,
            'observacoes' => $sale->observacoes,
            'motivo_cancelamento' => $sale->motivo_cancelamento,
            'cancelado_em' => $sale->cancelado_em?->toIso8601String(),
            'os_id' => $sale->os_id !== null ? (int) $sale->os_id : null,
            'numero_os' => $sale->relationLoaded('order') ? ($sale->order?->numero_os) : null,
            'desconto_tipo' => (string) $sale->desconto_tipo,
            'desconto_percentual' => $sale->desconto_percentual !== null
                ? round((float) $sale->desconto_percentual, 4)
                : null,
            'acrescimo_tipo' => (string) $sale->acrescimo_tipo,
            'acrescimo_percentual' => $sale->acrescimo_percentual !== null
                ? round((float) $sale->acrescimo_percentual, 4)
                : null,
            'itens' => $sale->items->map(fn (SaleItem $item): array => [
                'id' => (int) $item->id,
                'tipo_item' => (string) $item->tipo_item,
                'tipo_item_label' => SaleItem::typeLabel($item->tipo_item),
                'referencia_id' => $item->referencia_id !== null ? (int) $item->referencia_id : null,
                'codigo' => (string) ($item->codigo_snapshot ?? ''),
                'descricao' => (string) $item->descricao,
                'quantidade' => round((float) $item->quantidade, 3),
                'valor_unitario' => round((float) $item->valor_unitario, 2),
                'desconto' => round((float) $item->desconto, 2),
                'desconto_tipo' => (string) $item->desconto_tipo,
                'desconto_percentual' => $item->desconto_percentual !== null
                    ? round((float) $item->desconto_percentual, 4)
                    : null,
                'acrescimo' => round((float) $item->acrescimo, 2),
                'acrescimo_tipo' => (string) $item->acrescimo_tipo,
                'acrescimo_percentual' => $item->acrescimo_percentual !== null
                    ? round((float) $item->acrescimo_percentual, 4)
                    : null,
                'total' => round((float) $item->total, 2),
                'custo_unitario' => round((float) $item->custo_unitario, 2),
                'custo_total' => round((float) $item->custo_total, 2),
                'baixa_estoque' => (bool) $item->baixa_estoque,
                'observacoes' => $item->observacoes,
                'ordem' => (int) $item->ordem,
            ])->all(),
            'pagamentos' => $sale->payments->map(static fn ($payment): array => [
                'id' => (int) $payment->id,
                'forma_pagamento' => (string) $payment->forma_pagamento,
                'conta_financeira_id' => $payment->conta_financeira_id !== null
                    ? (int) $payment->conta_financeira_id
                    : null,
                'valor' => round((float) $payment->valor, 2),
                'valor_recebido' => $payment->valor_recebido !== null
                    ? round((float) $payment->valor_recebido, 2)
                    : null,
                'troco' => round((float) $payment->troco, 2),
                'parcelas' => (int) $payment->parcelas,
                'modalidade' => $payment->modalidade,
                'operadora_id' => $payment->operadora_id !== null ? (int) $payment->operadora_id : null,
                'bandeira_id' => $payment->bandeira_id !== null ? (int) $payment->bandeira_id : null,
                'valor_taxa' => round((float) $payment->valor_taxa, 2),
                'valor_liquido' => $payment->valor_liquido !== null
                    ? round((float) $payment->valor_liquido, 2)
                    : null,
                'movimento_id' => $payment->movimento_id !== null ? (int) $payment->movimento_id : null,
                'data_pagamento' => $payment->data_pagamento?->toDateString(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function mapSummary(Sale $sale): array
    {
        return [
            'id' => (int) $sale->id,
            'numero' => (string) $sale->numero,
            'status' => (string) $sale->status,
            'status_label' => Sale::statusLabel($sale->status),
            'status_color' => $this->statusColor((string) $sale->status),
            'status_pagamento' => (string) $sale->status_pagamento,
            'status_pagamento_label' => Sale::paymentStatusLabel($sale->status_pagamento),
            'cliente_id' => $sale->cliente_id !== null ? (int) $sale->cliente_id : null,
            'cliente_nome' => $sale->customerName(),
            'vendedor_id' => $sale->vendedor_id !== null ? (int) $sale->vendedor_id : null,
            'vendedor_nome' => $sale->relationLoaded('seller') ? (string) ($sale->seller?->nome ?? '') : '',
            'data_venda' => $sale->data_venda?->toDateString(),
            'subtotal' => round((float) $sale->subtotal, 2),
            'desconto' => round((float) $sale->desconto, 2),
            'acrescimo' => round((float) $sale->acrescimo, 2),
            'total' => round((float) $sale->total, 2),
            'custo_total' => round((float) $sale->custo_total, 2),
            'margem_valor' => round((float) $sale->margem_valor, 2),
            'margem_percentual' => round((float) $sale->margem_percentual, 2),
            'valor_pago' => round((float) $sale->valor_pago, 2),
            'valor_aberto' => max(0, round((float) $sale->total - (float) $sale->valor_pago, 2)),
            'financeiro_id' => $sale->financeiro_id !== null ? (int) $sale->financeiro_id : null,
            'estoque_divergente' => (bool) $sale->estoque_divergente,
            'total_itens' => $sale->relationLoaded('items') ? $sale->items->count() : null,
            'created_at' => $sale->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Sale>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Sale::query()->from('vendas')->with(['client', 'seller']);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->whereRaw('LOWER(vendas.numero) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(vendas.cliente_nome_avulso, "")) LIKE ?', [$like])
                    ->orWhereIn('vendas.cliente_id', Client::query()
                        ->whereRaw('LOWER(COALESCE(nome_razao, "")) LIKE ?', [$like])
                        ->select('id'));
            });
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $query->where('vendas.status', $status);
        }

        $paymentStatus = trim((string) ($filters['status_pagamento'] ?? ''));
        if ($paymentStatus !== '') {
            $query->where('vendas.status_pagamento', $paymentStatus);
        }

        $sellerId = (int) ($filters['vendedor_id'] ?? 0);
        if ($sellerId > 0) {
            $query->where('vendas.vendedor_id', $sellerId);
        }

        $clientId = (int) ($filters['cliente_id'] ?? 0);
        if ($clientId > 0) {
            $query->where('vendas.cliente_id', $clientId);
        }

        $from = $this->normalizeDate($filters['data_inicio'] ?? null);
        if ($from !== null) {
            $query->whereDate('vendas.data_venda', '>=', $from);
        }

        $to = $this->normalizeDate($filters['data_fim'] ?? null);
        if ($to !== null) {
            $query->whereDate('vendas.data_venda', '<=', $to);
        }

        $paymentMethod = trim((string) ($filters['forma_pagamento'] ?? ''));
        if ($paymentMethod !== '') {
            $query->whereExists(function ($sub) use ($paymentMethod): void {
                $sub->selectRaw('1')
                    ->from('venda_pagamentos')
                    ->whereColumn('venda_pagamentos.venda_id', 'vendas.id')
                    ->where('venda_pagamentos.forma_pagamento', $paymentMethod);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistSale(User $actor, array $attributes, string $idempotencyKey, string $fingerprint): Sale
    {
        $clientId = (int) ($attributes['cliente_id'] ?? 0);
        $orderId = (int) ($attributes['os_id'] ?? 0);

        $sale = new Sale();
        $sale->numero = $this->nextSaleNumber();
        $sale->status = Sale::STATUS_COMPLETED;
        $sale->canal = Sale::CHANNEL_COUNTER;
        $sale->cliente_id = $clientId > 0 ? $clientId : null;
        $sale->cliente_nome_avulso = $clientId > 0
            ? null
            : (trim((string) ($attributes['cliente_nome_avulso'] ?? '')) ?: 'Consumidor final');
        $sale->cliente_documento_avulso = $clientId > 0
            ? null
            : (trim((string) ($attributes['cliente_documento_avulso'] ?? '')) ?: null);
        $sale->telefone_contato = trim((string) ($attributes['telefone_contato'] ?? '')) ?: null;
        $sale->email_contato = trim((string) ($attributes['email_contato'] ?? '')) ?: null;
        $sale->vendedor_id = (int) ($attributes['vendedor_id'] ?? 0) > 0
            ? (int) $attributes['vendedor_id']
            : (int) $actor->id;
        $sale->criado_por = (int) $actor->id;
        $sale->os_id = $orderId > 0 ? $orderId : null;
        $sale->data_venda = $this->normalizeDate($attributes['data_venda'] ?? null) ?? now()->toDateString();
        $sale->observacoes = trim((string) ($attributes['observacoes'] ?? '')) ?: null;
        $sale->status_pagamento = Sale::PAYMENT_STATUS_PENDING;
        $sale->total = 0;

        if ($idempotencyKey !== '') {
            $sale->creation_request_id = $idempotencyKey;
            $sale->creation_request_fingerprint = $fingerprint;
            $sale->creation_requested_by = (int) $actor->id;
        }

        $sale->save();

        return $sale;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return Collection<int, SaleItem>
     */
    private function syncItems(Sale $sale, array $items): Collection
    {
        $now = now();
        $rows = [];

        foreach ($items as $index => $item) {
            $quantity = round((float) $item['quantidade'], 3);
            $unitPrice = round((float) $item['valor_unitario'], 2);
            $base = round($quantity * $unitPrice, 2);

            $discount = CommercialAdjustment::resolve(
                $base,
                $item['desconto_tipo'] ?? null,
                $item['desconto_percentual'] ?? null,
                $item['desconto'] ?? null
            );
            $addition = CommercialAdjustment::resolve(
                $base,
                $item['acrescimo_tipo'] ?? null,
                $item['acrescimo_percentual'] ?? null,
                $item['acrescimo'] ?? null
            );

            $unitCost = round((float) ($item['custo_unitario'] ?? 0), 2);

            $rows[] = [
                'venda_id' => (int) $sale->id,
                'tipo_item' => (string) $item['tipo_item'],
                'referencia_id' => $item['referencia_id'],
                'codigo_snapshot' => $item['codigo_snapshot'],
                'descricao' => (string) $item['descricao'],
                'quantidade' => $quantity,
                'valor_unitario' => $unitPrice,
                'desconto' => round($discount['amount'], 2),
                'desconto_tipo' => $discount['mode'],
                'desconto_percentual' => $discount['percent'],
                'acrescimo' => round($addition['amount'], 2),
                'acrescimo_tipo' => $addition['mode'],
                'acrescimo_percentual' => $addition['percent'],
                'total' => round(max(0, $base - $discount['amount'] + $addition['amount']), 2),
                'custo_unitario' => $unitCost,
                'custo_total' => round($unitCost * $quantity, 2),
                'preco_venda_referencia' => $item['preco_venda_referencia'],
                'baixa_estoque' => (bool) $item['baixa_estoque'],
                'ordem' => $index,
                'observacoes' => $item['observacoes'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        SaleItem::query()->insert($rows);

        return SaleItem::query()
            ->where('venda_id', (int) $sale->id)
            ->orderBy('ordem')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Collection<int, SaleItem>  $items
     */
    private function recalculateFinancials(Sale $sale, array $attributes, Collection $items): void
    {
        $subtotal = round((float) $items->sum('total'), 2);
        $cost = round((float) $items->sum('custo_total'), 2);

        $discount = CommercialAdjustment::resolve(
            $subtotal,
            $attributes['desconto_tipo'] ?? null,
            $attributes['desconto_percentual'] ?? null,
            $attributes['desconto'] ?? null
        );
        $addition = CommercialAdjustment::resolve(
            $subtotal,
            $attributes['acrescimo_tipo'] ?? null,
            $attributes['acrescimo_percentual'] ?? null,
            $attributes['acrescimo'] ?? null
        );

        $total = round(max(0, $subtotal - $discount['amount'] + $addition['amount']), 2);
        $margin = round($total - $cost, 2);

        $sale->forceFill([
            'subtotal' => $subtotal,
            'desconto' => round($discount['amount'], 2),
            'desconto_tipo' => $discount['mode'],
            'desconto_percentual' => $discount['percent'],
            'acrescimo' => round($addition['amount'], 2),
            'acrescimo_tipo' => $addition['mode'],
            'acrescimo_percentual' => $addition['percent'],
            'total' => $total,
            'custo_total' => $cost,
            'margem_valor' => $margin,
            'margem_percentual' => $total > 0 ? round(($margin / $total) * 100, 2) : 0.0,
        ])->save();
    }

    /**
     * Normaliza e enriquece os itens com dados do catálogo.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = strtolower(trim((string) ($item['tipo_item'] ?? SaleItem::TYPE_LOOSE)));
            if (! in_array($type, SaleItem::types(), true)) {
                $type = SaleItem::TYPE_LOOSE;
            }

            $referenceId = (int) ($item['referencia_id'] ?? 0);
            $reference = $this->resolveItemReference($type, $referenceId > 0 ? $referenceId : null);

            $description = trim((string) ($item['descricao'] ?? ''));
            if ($description === '') {
                $description = (string) ($reference['descricao'] ?? '');
            }

            if ($description === '') {
                throw new RuntimeException('Informe a descrição de todos os itens da venda.');
            }

            $quantity = CommercialAdjustment::decimal($item['quantidade'] ?? 1, 3);
            if ($quantity <= 0) {
                $quantity = 1.0;
            }

            $unitPrice = array_key_exists('valor_unitario', $item) && $item['valor_unitario'] !== null && $item['valor_unitario'] !== ''
                ? CommercialAdjustment::money($item['valor_unitario'])
                : (float) ($reference['valor_unitario'] ?? 0);

            $unitCost = array_key_exists('custo_unitario', $item) && $item['custo_unitario'] !== null && $item['custo_unitario'] !== ''
                ? CommercialAdjustment::money($item['custo_unitario'])
                : (float) ($reference['custo_unitario'] ?? 0);

            // Só peça cadastrada movimenta saldo; o resto é sempre falso mesmo
            // que o cliente da API mande true.
            $stock = $type === SaleItem::TYPE_PART
                && $referenceId > 0
                && $reference !== []
                && filter_var($item['baixa_estoque'] ?? true, FILTER_VALIDATE_BOOL);

            if ($stock && floor($quantity) !== $quantity) {
                throw new RuntimeException(
                    'Itens com baixa de estoque precisam de quantidade inteira: "'.$description.'".'
                );
            }

            $normalized[] = [
                'tipo_item' => $type,
                'referencia_id' => $referenceId > 0 ? $referenceId : null,
                'codigo_snapshot' => $reference['codigo'] ?? null,
                'descricao' => $description,
                'quantidade' => $quantity,
                'valor_unitario' => $unitPrice,
                'custo_unitario' => $unitCost,
                'preco_venda_referencia' => $reference['preco_venda_referencia'] ?? null,
                'desconto' => $item['desconto'] ?? null,
                'desconto_tipo' => $item['desconto_tipo'] ?? null,
                'desconto_percentual' => $item['desconto_percentual'] ?? null,
                'acrescimo' => $item['acrescimo'] ?? null,
                'acrescimo_tipo' => $item['acrescimo_tipo'] ?? null,
                'acrescimo_percentual' => $item['acrescimo_percentual'] ?? null,
                'baixa_estoque' => $stock,
                'observacoes' => trim((string) ($item['observacoes'] ?? '')) ?: null,
            ];
        }

        return $normalized;
    }

    /**
     * Dados congelados do catálogo — mesma ideia de
     * BudgetWorkflowService::resolveItemReferenceData().
     *
     * @return array<string, mixed>
     */
    private function resolveItemReference(string $type, ?int $referenceId): array
    {
        if ($referenceId === null) {
            return [];
        }

        if ($type === SaleItem::TYPE_PART) {
            $part = Peca::query()->find($referenceId);

            if ($part instanceof Peca) {
                $cost = (float) ($part->preco_custo ?? 0);
                $sale = (float) ($part->preco_venda ?? 0);

                return [
                    'codigo' => (string) ($part->codigo ?? ''),
                    'descricao' => (string) ($part->nome ?? ''),
                    'valor_unitario' => $sale > 0 ? $sale : $cost,
                    'custo_unitario' => $cost,
                    'preco_venda_referencia' => $sale,
                ];
            }
        }

        if ($type === SaleItem::TYPE_SERVICE) {
            $service = Servico::query()->find($referenceId);

            if ($service instanceof Servico) {
                $value = (float) ($service->valor ?? 0);

                return [
                    'codigo' => null,
                    'descricao' => (string) ($service->nome ?? ''),
                    'valor_unitario' => $value,
                    'custo_unitario' => (float) ($service->custo_direto_padrao ?? 0),
                    'preco_venda_referencia' => $value,
                ];
            }
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $payments
     * @return array<int, array<string, mixed>>
     */
    private function normalizePayments(array $payments, mixed $saleDate): array
    {
        $normalized = [];
        $fallbackDate = $this->normalizeDate($saleDate) ?? now()->toDateString();

        foreach ($payments as $payment) {
            if (! is_array($payment)) {
                continue;
            }

            $value = CommercialAdjustment::money($payment['valor'] ?? 0);

            if ($value <= 0) {
                continue;
            }

            $normalized[] = [
                'forma_pagamento' => trim((string) ($payment['forma_pagamento'] ?? '')),
                'valor' => $value,
                'valor_recebido' => array_key_exists('valor_recebido', $payment) && $payment['valor_recebido'] !== null && $payment['valor_recebido'] !== ''
                    ? CommercialAdjustment::money($payment['valor_recebido'])
                    : null,
                'conta_financeira_id' => (int) ($payment['conta_financeira_id'] ?? 0) ?: null,
                'operadora_id' => (int) ($payment['operadora_id'] ?? 0) ?: null,
                'bandeira_id' => (int) ($payment['bandeira_id'] ?? 0) ?: null,
                'modalidade' => trim((string) ($payment['modalidade'] ?? '')) ?: null,
                'parcelas' => max(1, (int) ($payment['parcelas'] ?? 1)),
                'data_pagamento' => $this->normalizeDate($payment['data_pagamento'] ?? null) ?? $fallbackDate,
                'observacoes' => trim((string) ($payment['observacoes'] ?? '')) ?: null,
            ];
        }

        return $normalized;
    }

    /**
     * Numeração VD-YYMM-NNNNNN.
     *
     * Diferente de BudgetWorkflowService::nextBudgetNumber(), o SELECT do último
     * número trava a linha: dois caixas finalizando ao mesmo tempo colidiriam.
     * O UNIQUE de `vendas.numero` continua sendo a rede de segurança, e o
     * chamador reexecuta em caso de colisão.
     */
    private function nextSaleNumber(): string
    {
        $prefix = 'VD-'.now()->format('ym').'-';

        $last = Sale::query()
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
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<int, array<string, mixed>>  $payments
     * @param  array<string, mixed>  $attributes
     */
    private function fingerprint(array $items, array $payments, array $attributes): string
    {
        return hash('sha256', json_encode([
            'itens' => $items,
            'pagamentos' => $payments,
            'cliente_id' => (int) ($attributes['cliente_id'] ?? 0),
            'desconto' => $attributes['desconto'] ?? null,
            'desconto_tipo' => $attributes['desconto_tipo'] ?? null,
            'desconto_percentual' => $attributes['desconto_percentual'] ?? null,
            'acrescimo' => $attributes['acrescimo'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCreationReplay(User $actor, string $idempotencyKey, string $fingerprint): ?array
    {
        $sale = Sale::query()->where('creation_request_id', $idempotencyKey)->first();

        if (! $sale instanceof Sale) {
            return null;
        }

        if ((int) ($sale->creation_requested_by ?? 0) !== (int) $actor->id
            || ! hash_equals((string) ($sale->creation_request_fingerprint ?? ''), $fingerprint)) {
            return ['result' => 'idempotency_conflict'];
        }

        return [
            'result' => 'ok',
            'sale' => $this->mapDetail($this->loadSaleOrFail((int) $sale->id)),
            'idempotent_replay' => true,
        ];
    }

    private function statusColor(string $status): string
    {
        foreach (Sale::statusOptions() as $option) {
            if ((string) ($option['value'] ?? '') === $status) {
                return (string) ($option['color'] ?? '#64748b');
            }
        }

        return '#64748b';
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
