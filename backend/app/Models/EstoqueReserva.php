<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reserva de peca presa a um orcamento (specs/040).
 *
 * Uma linha por (orcamento, peca) — ver a UNIQUE em
 * 2026_09_10_000001_create_estoque_reservas_table.php e o porque de a chave nao
 * ser `orcamento_item_id` (syncItems() apaga e reinsere os itens a cada save).
 *
 * O saldo que a reserva efetivamente segura e o REMANESCENTE
 * (`quantidade - quantidade_consumida`), nao `quantidade`: baixa parcial na OS
 * consome parte e o resto continua prometido. Ler `quantidade` direto para somar
 * disponibilidade e o erro classico aqui — use `remanescente()`.
 */
class EstoqueReserva extends Model
{
    public const STATUS_ATIVA = 'ativa';

    public const STATUS_CONSUMIDA = 'consumida';

    public const STATUS_LIBERADA = 'liberada';

    public const ORIGEM_ORCAMENTO = 'orcamento';

    protected $table = 'estoque_reservas';

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'peca_id' => 'integer',
        'orcamento_id' => 'integer',
        'os_id' => 'integer',
        'equipamento_id' => 'integer',
        // decimal:4 pelo mesmo motivo de `pecas`: insumo fracionado.
        'quantidade' => 'decimal:4',
        'quantidade_consumida' => 'decimal:4',
        'expira_em' => 'datetime',
        'liberacao_manual' => 'boolean',
        'liberada_em' => 'datetime',
        'liberada_por' => 'integer',
    ];

    /**
     * Quanto esta reserva ainda segura de saldo.
     */
    public function remanescente(): float
    {
        return max(0.0, round(
            (float) ($this->quantidade ?? 0) - (float) ($this->quantidade_consumida ?? 0),
            4
        ));
    }

    public function scopeAtiva(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ATIVA);
    }

    public function peca(): BelongsTo
    {
        return $this->belongsTo(Peca::class, 'peca_id', 'id');
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipamento_id', 'id');
    }
}
