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
     * @var array<int, string>
     */
    public const FORMAS_PAGAMENTO = ['dinheiro', 'cartao_credito', 'cartao_debito', 'pix', 'boleto', 'transferencia'];

    protected $table = 'financeiro';

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'cliente_id' => 'integer',
        'fornecedor_id' => 'integer',
        'valor' => 'float',
        'data_vencimento' => 'date',
        'data_pagamento' => 'date',
        'data_competencia' => 'date',
        'origem_id' => 'integer',
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

    public static function formaPagamentoOptions(): array
    {
        return [
            ['value' => 'dinheiro', 'label' => 'Dinheiro'],
            ['value' => 'cartao_credito', 'label' => 'Cartão de crédito'],
            ['value' => 'cartao_debito', 'label' => 'Cartão de débito'],
            ['value' => 'pix', 'label' => 'Pix'],
            ['value' => 'boleto', 'label' => 'Boleto'],
            ['value' => 'transferencia', 'label' => 'Transferência'],
        ];
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

        if (array_key_exists('dre_fixo_mensal', $filters) && $filters['dre_fixo_mensal'] !== '' && $filters['dre_fixo_mensal'] !== null) {
            $query->where('dre_fixo_mensal', (bool) $filters['dre_fixo_mensal']);
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

    public function movimentos(): HasMany
    {
        return $this->hasMany(FinanceiroMovimento::class, 'financeiro_id', 'id');
    }
}
