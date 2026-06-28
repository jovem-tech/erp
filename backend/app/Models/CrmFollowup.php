<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmFollowup extends Model
{
    public const STATUS_PENDENTE = 'pendente';
    public const STATUS_CONCLUIDO = 'concluido';

    protected $table = 'crm_followups';

    protected $guarded = [];

    protected $casts = [
        'cliente_id' => 'integer',
        'os_id' => 'integer',
        'data_prevista' => 'datetime',
        'usuario_responsavel' => 'integer',
        'concluido_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'cliente_id', 'id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_responsavel', 'id');
    }
}
