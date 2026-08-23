<?php

namespace App\Models\Backups;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRestore extends Model
{
    protected $table = 'backup_restauracoes';

    protected $guarded = [];

    protected $casts = [
        'escopo_json' => 'array',
        'relatorio_json' => 'array',
        'backup_seguranca_id' => 'integer',
        'solicitado_por' => 'integer',
        'autorizado_por' => 'integer',
        'iniciado_em' => 'immutable_datetime',
        'concluido_em' => 'immutable_datetime',
    ];

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_id');
    }

    public function safetyBackup(): BelongsTo
    {
        return $this->belongsTo(Backup::class, 'backup_seguranca_id');
    }
}
