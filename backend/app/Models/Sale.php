<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Venda de balcão — specs/027-vendas-balcao-pdv/spec.md.
 *
 * Uma venda é imutável depois de concluída: não existe rascunho no servidor nem
 * edição. Correção se faz cancelando e revendendo, o que elimina máquina de
 * estados, reserva de estoque e locks longos do fluxo de PDV.
 */
class Sale extends Model
{
    public const ADJUSTMENT_MODE_VALUE = 'valor';
    public const ADJUSTMENT_MODE_PERCENT = 'percentual';

    public const STATUS_COMPLETED = 'concluida';
    public const STATUS_CANCELLED = 'cancelada';

    public const PAYMENT_STATUS_PENDING = 'pendente';
    public const PAYMENT_STATUS_PARTIAL = 'parcial';
    public const PAYMENT_STATUS_PAID = 'pago';

    public const CHANNEL_COUNTER = 'balcao';

    /**
     * Categoria financeira criada pela migration de seed do módulo.
     *
     * Separada de "Venda de peças" (que aponta para o subgrupo DRE de OS) porque
     * receita de balcão não é receita de ordem de serviço.
     */
    public const FINANCE_CATEGORY = 'Venda de balcão';

    protected $table = 'vendas';

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'cliente_id' => 'integer',
        'vendedor_id' => 'integer',
        'criado_por' => 'integer',
        'cancelado_por' => 'integer',
        'creation_requested_by' => 'integer',
        'os_id' => 'integer',
        'financeiro_id' => 'integer',
        'data_venda' => 'date',
        'concluida_em' => 'datetime',
        'cancelado_em' => 'datetime',
        'estoque_baixado_em' => 'datetime',
        'estoque_divergente' => 'boolean',
        'subtotal' => 'float',
        'desconto' => 'float',
        'desconto_percentual' => 'float',
        'acrescimo' => 'float',
        'acrescimo_percentual' => 'float',
        'total' => 'float',
        'custo_total' => 'float',
        'margem_valor' => 'float',
        'margem_percentual' => 'float',
        'valor_pago' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'venda_id', 'id')->orderBy('ordem')->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class, 'venda_id', 'id')->orderBy('ordem')->orderBy('id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movimentacao::class, 'venda_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function isCancelled(): bool
    {
        return (string) $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Nome exibido do comprador, com fallback para consumidor final.
     */
    public function customerName(): string
    {
        $avulso = trim((string) $this->cliente_nome_avulso);

        if ($this->relationLoaded('client') && $this->client !== null) {
            return (string) $this->client->nome_razao;
        }

        if ($avulso !== '') {
            return $avulso;
        }

        return 'Consumidor final';
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public static function statusOptions(): array
    {
        return [
            ['value' => self::STATUS_COMPLETED, 'label' => 'Concluída', 'color' => '#16a34a'],
            ['value' => self::STATUS_CANCELLED, 'label' => 'Cancelada', 'color' => '#dc2626'],
        ];
    }

    /**
     * @return array<int, array{value: string, label: string, color: string}>
     */
    public static function paymentStatusOptions(): array
    {
        return [
            ['value' => self::PAYMENT_STATUS_PENDING, 'label' => 'Pendente', 'color' => '#f97316'],
            ['value' => self::PAYMENT_STATUS_PARTIAL, 'label' => 'Parcial', 'color' => '#f59e0b'],
            ['value' => self::PAYMENT_STATUS_PAID, 'label' => 'Pago', 'color' => '#16a34a'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return array_column(self::statusOptions(), 'label', 'value');
    }

    public static function statusLabel(?string $value): string
    {
        $value = trim((string) $value);

        return self::statusLabels()[$value] ?? ($value !== '' ? ucfirst($value) : 'Concluída');
    }

    /**
     * @return array<string, string>
     */
    public static function paymentStatusLabels(): array
    {
        return array_column(self::paymentStatusOptions(), 'label', 'value');
    }

    public static function paymentStatusLabel(?string $value): string
    {
        $value = trim((string) $value);

        return self::paymentStatusLabels()[$value] ?? 'Pendente';
    }

    /**
     * @return array<int, string>
     */
    public static function adjustmentModes(): array
    {
        return [self::ADJUSTMENT_MODE_VALUE, self::ADJUSTMENT_MODE_PERCENT];
    }
}
