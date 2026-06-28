<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MobileNotification extends Model
{
    protected $table = 'mobile_notifications';

    protected $primaryKey = 'id';

    protected $fillable = [
        'usuario_id',
        'tipo_evento',
        'titulo',
        'corpo',
        'rota_destino',
        'payload_json',
        'lida_em',
        'enviada_push_em',
    ];

    protected function casts(): array
    {
        return [
            'usuario_id' => 'integer',
            'lida_em' => 'datetime',
            'enviada_push_em' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
