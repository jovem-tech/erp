<?php

namespace App\Models\Files;

use App\Enums\Files\ManagedFileAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedFileEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'managed_file_events';

    protected $guarded = [];

    protected $casts = [
        'action' => ManagedFileAction::class,
        'actor_id' => 'integer',
        'context_json' => 'array',
    ];

    public function file(): BelongsTo
    {
        return $this->belongsTo(ManagedFile::class, 'file_id');
    }
}
