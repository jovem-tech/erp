<?php

namespace App\Models\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FileScanRun extends Model
{
    protected $table = 'file_scan_runs';

    protected $guarded = [];

    protected $casts = [
        'checkpoint_json' => 'array',
        'processed_count' => 'integer',
        'skipped_count' => 'integer',
        'finding_count' => 'integer',
        'failed_count' => 'integer',
        'started_by' => 'integer',
        'started_at' => 'immutable_datetime',
        'heartbeat_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];

    public function findings(): HasMany
    {
        return $this->hasMany(FileScanFinding::class, 'scan_run_id');
    }
}
