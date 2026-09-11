<?php

namespace App\Models;

use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Models\Files\ManagedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceiroAnexo extends Model
{
    use SoftDeletes;

    protected $table = 'financeiro_anexos';

    protected $guarded = [];

    protected $hidden = [
        'arquivo',
        'hash_sha256',
        'managed_file_uuid',
    ];

    protected $appends = [
        'management_status',
    ];

    protected $casts = [
        'id' => 'integer',
        'financeiro_id' => 'integer',
        'tamanho_bytes' => 'integer',
        'usuario_id' => 'integer',
        'file_manager_synced_at' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function financeiro(): BelongsTo
    {
        return $this->belongsTo(Financeiro::class, 'financeiro_id', 'id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id', 'id');
    }

    public function managedFile(): BelongsTo
    {
        return $this->belongsTo(ManagedFile::class, 'managed_file_uuid', 'uuid');
    }

    public function getManagementStatusAttribute(): string
    {
        if (trim((string) $this->managed_file_uuid) === '') {
            return 'pending';
        }

        $managed = $this->relationLoaded('managedFile')
            ? $this->getRelation('managedFile')
            : $this->managedFile()->first();

        if (! $managed instanceof ManagedFile) {
            return 'integrity_error';
        }
        if (in_array($managed->integrity_status, [FileIntegrityStatus::Missing, FileIntegrityStatus::Corrupted], true)) {
            return 'integrity_error';
        }

        return match ($managed->lifecycle_status) {
            FileLifecycleStatus::Active, FileLifecycleStatus::Archived => 'active',
            FileLifecycleStatus::Trashed => 'trashed',
            FileLifecycleStatus::Purged => 'purged',
        };
    }
}
