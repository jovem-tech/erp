<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetStatusHistory extends Model
{
    protected $table = 'orcamento_status_historico';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'orcamento_id' => 'integer',
        'alterado_por' => 'integer',
        'created_at' => 'datetime',
    ];

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'orcamento_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'alterado_por', 'id');
    }
}
