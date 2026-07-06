<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinanceiroMovimento extends Model
{
    public const TIPO_ENTRADA = 'entrada';
    public const TIPO_SAIDA = 'saida';
    public const TIPO_ESTORNO = 'estorno';
    public const TIPO_TRANSFERENCIA = 'transferencia';

    protected $table = 'financeiro_movimentos';

    protected $guarded = [];

    protected $casts = [
        'financeiro_id' => 'integer',
        'data_movimento' => 'date',
        'valor_movimento' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function financeiro(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }

    public function cartao(): HasOne
    {
        return $this->hasOne(FinanceiroMovimentoCartao::class, 'movimento_id', 'id');
    }
}
