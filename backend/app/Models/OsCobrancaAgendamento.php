<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsCobrancaAgendamento extends Model
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_ENVIADO = 'enviado';
    public const STATUS_CANCELADO = 'cancelado';
    public const STATUS_ERRO = 'erro';

    protected $table = 'os_cobranca_agendamentos';

    protected $guarded = [];

    protected $casts = [
        'os_id' => 'integer',
        'financeiro_id' => 'integer',
        'cliente_id' => 'integer',
        'prazo_dias' => 'integer',
        'enviar_em' => 'datetime',
        'ultima_tentativa_em' => 'datetime',
        'enviado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function financeiro(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }
}
