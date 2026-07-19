<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinanceiroTransferencia extends Model
{
    public const STATUS_REALIZADA = 'realizada';

    public const STATUS_CANCELADA = 'cancelada';

    protected $table = 'financeiro_transferencias';

    protected $guarded = [];

    protected $casts = [
        'conta_origem_id' => 'integer',
        'conta_destino_id' => 'integer',
        'data_transferencia' => 'date',
        'valor' => 'float',
        'created_by' => 'integer',
        'cancelado_por' => 'integer',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function origem(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_origem_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_destino_id');
    }

    public function movimentos(): HasMany
    {
        return $this->hasMany(FinanceiroContaMovimento::class, 'transferencia_id');
    }
}
