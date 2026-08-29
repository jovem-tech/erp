<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Financeiro extends Model
{
    public const TIPO_RECEBER = 'receber';
    public const TIPO_PAGAR = 'pagar';

    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_PARCIAL = 'parcial';
    public const STATUS_PAGO = 'pago';
    public const STATUS_CANCELADO = 'cancelado';

    /**
     * origem_tipo do lançamento sintético que
     * FinanceiroCartaoCreditoService::payInvoice() cria para representar,
     * numa linha só, o pagamento em lote de uma fatura de cartão da
     * assistência. Não é despesa fixa nem variável — totaisFixoVariavel() e
     * scopeWithFilters() excluem este origem_tipo dos totais/filtros.
     */
    public const ORIGEM_TIPO_FATURA_CARTAO_CREDITO = 'fatura_cartao_credito';

    /**
     * Lista histórica das formas de pagamento. Desde o catálogo gerenciável
     * (`financeiro_formas_pagamento`) esta constante serve apenas como semente
     * da migration e fallback quando a tabela ainda não existe. Para validar ou
     * listar formas, use FinanceiroFormaPagamento::validCodes()/options().
     *
     * Coincide com os valores do ENUM legado de `financeiro.forma_pagamento`.
     *
     * @var array<int, string>
     */
    public const FORMAS_PAGAMENTO = ['dinheiro', 'cartao_credito', 'cartao_debito', 'pix', 'boleto', 'transferencia'];

    protected $table = 'financeiro';

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'cliente_id' => 'integer',
        'fornecedor_id' => 'integer',
        'cartao_credito_id' => 'integer',
        'valor' => 'float',
        'data_vencimento' => 'date',
        'data_compra' => 'date',
        'data_pagamento' => 'date',
        'data_competencia' => 'date',
        'origem_id' => 'integer',
        'avulso' => 'boolean',
        'impacta_dre' => 'boolean',
        'impacta_fluxo_caixa' => 'boolean',
        'dre_fixo_mensal' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_PENDENTE, 'label' => 'Pendente'],
            ['value' => self::STATUS_PARCIAL, 'label' => 'Parcial'],
            ['value' => self::STATUS_PAGO, 'label' => 'Pago'],
            ['value' => self::STATUS_CANCELADO, 'label' => 'Cancelado'],
        ];
    }

    /**
     * Opções vindas do catálogo gerenciável (Configurações Financeiras >
     * Formas de Pagamento). A constante FORMAS_PAGAMENTO acima permanece só
     * como semente/fallback — ver FinanceiroFormaPagamento::options().
     */
    public static function formaPagamentoOptions(): array
    {
        return FinanceiroFormaPagamento::options();
    }

    /**
     * O que conta como CUSTO FIXO no DRE.
     *
     * Definicao unica, compartilhada entre o DRE gerencial
     * (FinanceiroReportService) e o custo-hora produtiva
     * (CustoHoraService). Duplicar "o que e custo fixo" em dois lugares
     * significaria, mais cedo ou mais tarde, um ponto de equilibrio que nao
     * bate com o preco cobrado — exatamente o descompasso que specs/037 veio
     * corrigir.
     *
     * A JANELA fica de fora de proposito: os dois consumidores precisam de
     * recortes diferentes. O DRE soma todo fixo com vencimento ate o fim do
     * mes (heuristica de "recorrente ainda vigente"); o custo-hora precisa de
     * limite inferior, ou somaria anos de aluguel num mes so.
     */
    public function scopeFixasDre(Builder $query): Builder
    {
        return $query
            ->where('tipo', self::TIPO_PAGAR)
            ->where('status', '!=', self::STATUS_CANCELADO)
            ->where('impacta_dre', true)
            ->where('dre_fixo_mensal', true);
    }

    public function scopeWithFilters(Builder $query, array $filters): Builder
    {
        $tipo = trim((string) ($filters['tipo'] ?? ''));
        if ($tipo !== '' && $tipo !== 'todos') {
            $query->where('tipo', $tipo);
        }

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'todos') {
            $query->where('status', $status);
        }

        $clienteId = (int) ($filters['cliente_id'] ?? 0);
        if ($clienteId > 0) {
            $query->where('cliente_id', $clienteId);
        }

        $cartaoCreditoId = (int) ($filters['cartao_credito_id'] ?? 0);
        if ($cartaoCreditoId > 0) {
            $query->where('cartao_credito_id', $cartaoCreditoId);
        }

        if (array_key_exists('dre_fixo_mensal', $filters) && $filters['dre_fixo_mensal'] !== '' && $filters['dre_fixo_mensal'] !== null) {
            $query->where('dre_fixo_mensal', (bool) $filters['dre_fixo_mensal']);

            // Recibo de pagamento de fatura (ver
            // ORIGEM_TIPO_FATURA_CARTAO_CREDITO; dre_fixo_mensal=false nele só
            // porque a coluna é NOT NULL) não é nem fixo nem variável — fica
            // de fora sempre que o usuário filtra explicitamente por um dos
            // dois.
            $query->where(function (Builder $q): void {
                $q->whereNull('origem_tipo')
                    ->orWhere('origem_tipo', '!=', self::ORIGEM_TIPO_FATURA_CARTAO_CREDITO);
            });
        }

        // Filtro de período simples por data_vencimento — deliberadamente
        // diferente do mecanismo de "fixo mensal reaparece em meses
        // futuros" usado só pelo DRE por Competência (ver
        // FinanceiroReportService::groupByCompetencia()), que é específico
        // daquele relatório.
        $mes = trim((string) ($filters['mes'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $mes) === 1) {
            [$ano, $mesNumero] = explode('-', $mes);
            $query->whereYear('data_vencimento', (int) $ano)->whereMonth('data_vencimento', (int) $mesNumero);
        }

        // Visão padrão da tela de Despesas (fixas e variáveis, sem
        // mês/status/tipo de despesa escolhidos pelo usuário): mês corrente
        // (qualquer status) + pendências de meses anteriores ainda em
        // aberto. Nunca inclui meses futuros — esses só aparecem se o
        // usuário pedir explicitamente pelo filtro de mês.
        if (filter_var($filters['periodo_atual_e_atrasadas'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $inicioMesAtual = now()->startOfMonth()->toDateString();
            $fimMesAtual = now()->endOfMonth()->toDateString();

            $query->where(function (Builder $q) use ($inicioMesAtual, $fimMesAtual): void {
                $q->whereBetween('data_vencimento', [$inicioMesAtual, $fimMesAtual])
                    ->orWhere(function (Builder $qq) use ($inicioMesAtual): void {
                        $qq->where('data_vencimento', '<', $inicioMesAtual)
                            ->whereIn('status', [self::STATUS_PENDENTE, self::STATUS_PARCIAL]);
                    });
            });
        }

        return $query;
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    /**
     * Venda de balcão que originou o título.
     *
     * Coluna própria (`venda_id`), espelhando `os_id`: `origem_id` NÃO serve
     * para isso porque, apesar do nome genérico, é um
     * belongsTo(FinanceiroMovimento) — gravar o id da venda ali carregaria um
     * movimento alheio de mesmo id. Ver specs/027-vendas-balcao-pdv/spec.md.
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'venda_id', 'id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'fornecedor_id', 'id');
    }

    public function cartaoCredito(): BelongsTo
    {
        return $this->belongsTo(FinanceiroCartaoCredito::class, 'cartao_credito_id', 'id');
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(FinanceiroMovimento::class, 'financeiro_id', 'id');
    }

    public function origemMovimento(): BelongsTo
    {
        return $this->belongsTo(FinanceiroMovimento::class, 'origem_id', 'id');
    }
}
