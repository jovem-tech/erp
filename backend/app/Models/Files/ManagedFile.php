<?php

namespace App\Models\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileMigrationStatus;
use App\Enums\Files\FileOrigin;
use App\Enums\Files\FileSecurityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedFile extends Model
{
    protected $table = 'managed_files';

    protected $guarded = [];

    protected $casts = [
        'origin' => FileOrigin::class,
        'lifecycle_status' => FileLifecycleStatus::class,
        'integrity_status' => FileIntegrityStatus::class,
        'security_status' => FileSecurityStatus::class,
        'migration_status' => FileMigrationStatus::class,
        'size_bytes' => 'integer',
        'created_by' => 'integer',
        'metadata_json' => 'array',
        'archived_at' => 'immutable_datetime',
        'trashed_at' => 'immutable_datetime',
        'purged_at' => 'immutable_datetime',
        'quarantined_at' => 'immutable_datetime',
    ];

    public function links(): HasMany
    {
        return $this->hasMany(ManagedFileLink::class, 'file_id');
    }

    public function legacyAliases(): HasMany
    {
        return $this->hasMany(ManagedFileLegacyAlias::class, 'file_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ManagedFileEvent::class, 'file_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(FileScanFinding::class, 'file_id');
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('lifecycle_status', '!=', FileLifecycleStatus::Trashed->value)
            ->where('lifecycle_status', '!=', FileLifecycleStatus::Purged->value)
            ->where('security_status', '!=', FileSecurityStatus::Quarantined->value);
    }

    public function scopeForCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}
