<?php

namespace App\Models\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileScanFinding extends Model
{
    protected $table = 'file_scan_findings';

    protected $guarded = [];

    protected $casts = [
        'source_reference_json' => 'array',
        'evidence_json' => 'array',
        'resolved_by' => 'integer',
        'resolved_at' => 'immutable_datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(FileScanRun::class, 'scan_run_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(ManagedFile::class, 'file_id');
    }
}
