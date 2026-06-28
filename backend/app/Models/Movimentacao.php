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
        'responsavel_id' => 'integer',
        'quantidade' => 'integer',
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
}
