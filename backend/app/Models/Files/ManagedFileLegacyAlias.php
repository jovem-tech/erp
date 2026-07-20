<?php

namespace App\Models\Files;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedFileLegacyAlias extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'managed_file_legacy_aliases';

    protected $guarded = [];

    protected $casts = [
        'verified_at' => 'immutable_datetime',
        'retired_at' => 'immutable_datetime',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ManagedFile::class, 'file_id');
    }
}
