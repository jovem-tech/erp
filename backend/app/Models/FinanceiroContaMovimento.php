<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceiroContaMovimento extends Model
{
    public const TIPO_SALDO_INICIAL = 'saldo_inicial';

    public const TIPO_AJUSTE = 'ajuste';

    public const TIPO_TRANSFERENCIA = 'transferencia';

    public const NATUREZA_ENTRADA = 'entrada';

    public const NATUREZA_SAIDA = 'saida';

    public const STATUS_REALIZADO = 'realizado';

    public const STATUS_CANCELADO = 'cancelado';

    protected $table = 'financeiro_conta_movimentos';

    protected $guarded = [];

    protected $casts = [
        'conta_financeira_id' => 'integer',
        'transferencia_id' => 'integer',
        'data_movimento' => 'date',
        'valor' => 'float',
        'created_by' => 'integer',
        'cancelado_por' => 'integer',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function conta(): BelongsTo
    {
        return $this->belongsTo(FinanceiroConta::class, 'conta_financeira_id');
    }

    public function transferencia(): BelongsTo
    {
        return $this->belongsTo(FinanceiroTransferencia::class, 'transferencia_id');
    }
}
