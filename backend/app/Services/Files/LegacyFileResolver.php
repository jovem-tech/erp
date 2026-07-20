<?php

namespace App\Services\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileSecurityStatus;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLegacyAlias;
use Illuminate\Support\Facades\Storage;

class LegacyFileResolver
{
    public function __construct(private readonly FileManagerConfiguration $configuration) {}

    public function addAlias(
        ManagedFile $file,
        string $legacyDisk,
        string $legacyPath,
        ?string $sourceTable = null,
        ?string $sourceColumn = null,
        ?string $sourceRecordId = null
    ): ManagedFileLegacyAlias {
        if (! $this->configuration->isLegacyReadDiskAllowed($legacyDisk)) {
            throw new \InvalidArgumentException('Disco legado nao autorizado.');
        }

        $legacyPath = FilePathGuard::normalizeRelativePath($legacyPath);
        $pathHash = hash('sha256', $legacyDisk."\0".$legacyPath);

        return ManagedFileLegacyAlias::query()->firstOrCreate([
            'legacy_disk' => $legacyDisk,
            'path_hash' => $pathHash,
        ], [
            'file_id' => $file->id,
            'legacy_path' => $legacyPath,
            'source_table' => $sourceTable,
            'source_column' => $sourceColumn,
            'source_record_id' => $sourceRecordId,
            'verified_at' => Storage::disk($legacyDisk)->exists($legacyPath) ? now() : null,
        ]);
    }

    public function resolve(string $legacyDisk, string $legacyPath): ?ManagedFile
    {
        if (! $this->configuration->isLegacyReadDiskAllowed($legacyDisk)) {
            return null;
        }

        try {
            $legacyPath = FilePathGuard::normalizeRelativePath($legacyPath);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $pathHash = hash('sha256', $legacyDisk."\0".$legacyPath);
        $alias = ManagedFileLegacyAlias::query()
            ->where('legacy_disk', $legacyDisk)
            ->where('path_hash', $pathHash)
            ->whereNull('retired_at')
            ->with('file')
            ->first();
        $file = $alias?->file;

        if (! $file instanceof ManagedFile) {
            return null;
        }

        if (
            $file->lifecycle_status === FileLifecycleStatus::Trashed
            || $file->security_status !== FileSecurityStatus::Clean
            || in_array($file->integrity_status, [FileIntegrityStatus::Missing, FileIntegrityStatus::Corrupted], true)
        ) {
            return null;
        }

        return $file;
    }
}
