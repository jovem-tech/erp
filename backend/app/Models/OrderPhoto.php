<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPhoto extends Model
{
    public const TIPO_RECEPCAO = 'recepcao';

    public const TIPO_DIAGNOSTICO = 'diagnostico';

    public const TIPO_ENTREGA = 'entrega';

    /** Espelha o enum de `os_fotos.tipo` no banco do legado — nao ha outro valor possivel. */
    public const TIPOS = [
        self::TIPO_RECEPCAO,
        self::TIPO_DIAGNOSTICO,
        self::TIPO_ENTREGA,
    ];

    protected $table = 'os_fotos';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'os_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'os_id', 'id');
    }
}
