<?php

namespace App\Models\Backups;

use App\Enums\Backups\DestinationDriver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupDelivery extends Model
{
    protected $table = 'backup_destinos_envios';

    protected $guarded = [];

    protected $casts = [
        'destino' => DestinationDriver::class,
        'bytes_enviados' => 'integer',
        'sha256_verificado' => 'boolean',
        'tentativas' => 'integer',
        'iniciado_em' => 'immutable_datetime',
        'concluido_em' => 'immutable_datetime',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }
}
