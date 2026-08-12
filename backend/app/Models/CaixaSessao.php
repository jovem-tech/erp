<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno de caixa — specs/028-caixa-sessoes/spec.md.
 *
 * A camada que faltava sobre a máquina financeira: um período com operador,
 * abertura declarada, movimentos rastreáveis e contagem no fechamento.
 */
class CaixaSessao extends Model
{
    public const STATUS_ABERTA = 'aberta';
    public const STATUS_FECHADA = 'fechada';

    protected $table = 'caixa_sessoes';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'conta_financeira_id' => 'integer',
        'operador_id' => 'integer',
        'aberto_por' => 'integer',
        'fechado_por' => 'integer',
        'aberto_em' => 'datetime',
        'fechado_em' => 'datetime',
        'abertura_automatica' => 'boolean',
        'valor_abertura' => 'float',
        'valor_esperado' => 'float',
        'valor_informado' => 'float',
        'diferenca' => 'float',
        'total_vendas_dinheiro' => 'float',
        'total_suprimentos' => 'float',
        'total_sangrias' => 'float',
        'total_devolucoes_dinheiro' => 'float',
        'quantidade_vendas' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(CaixaMovimento::class, 'caixa_sessao_id', 'id')->orderBy('id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'caixa_sessao_id', 'id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_financeira_id', 'id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operador_id', 'id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechado_por', 'id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ABERTA);
    }

    public function isOpen(): bool
    {
        return (string) $this->status === self::STATUS_ABERTA;
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_ABERTA, 'label' => 'Aberta', 'color' => '#16a34a'],
            ['value' => self::STATUS_FECHADA, 'label' => 'Fechada', 'color' => '#6b7280'],
        ];
    }

    public static function statusLabel(?string $value): string
    {
        return array_column(self::statusOptions(), 'label', 'value')[trim((string) $value)] ?? 'Aberta';
    }
}
