<?php

namespace App\Models\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedFileLink extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'managed_file_links';

    protected $guarded = [];

    protected $casts = [
        'subject_id' => 'integer',
        'is_current' => 'boolean',
        'created_by' => 'integer',
        'metadata_json' => 'array',
        'unlinked_at' => 'immutable_datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ManagedFile::class, 'file_id');
    }
}
