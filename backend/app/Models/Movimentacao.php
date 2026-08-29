<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Order;
use App\Models\User;
use App\Models\Peca;

class Movimentacao extends Model
{
    protected $table = 'movimentacoes';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'peca_id' => 'integer',
        'os_id' => 'integer',
        'financeiro_id' => 'integer',
        'responsavel_id' => 'integer',
        // Ver Peca::$casts: DECIMAL(14,4) desde 2026_08_27_000001.
        'quantidade' => 'decimal:4',
        // Custo congelado no momento do movimento (specs/039). Preenchido so em
        // entrada: em saida o custo e o medio, que ainda e da 036 Bloco B.
        'custo_unitario' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function peca(): BelongsTo
    {
        return $this->belongsTo(Peca::class, 'peca_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id', 'id');
    }

    /**
     * Lancamento financeiro que gerou este movimento (specs/039).
     *
     * Vale tanto para a entrada da compra quanto para a saida de estorno gerada
     * ao cancelar o lancamento — as duas apontam para o mesmo titulo.
     */
    public function financeiro(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }
}
