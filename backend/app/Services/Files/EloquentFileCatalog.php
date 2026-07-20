<?php

namespace App\Services\Files;

use App\Contracts\Files\FileCatalog;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use App\Enums\Files\FileIntegrityStatus;
use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\FileMigrationStatus;
use App\Enums\Files\FileSecurityStatus;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use App\Models\Files\ManagedFileLink;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EloquentFileCatalog implements FileCatalog
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly ManagedFileEventRecorder $events
    ) {}

    public function findByOperationKey(string $operationKey): ?ManagedFile
    {
        return ManagedFile::query()->where('operation_key', $operationKey)->first();
    }

    public function register(FileDescriptor $descriptor, FileContext $context, StoredFileResult $stored): ManagedFile
    {
        $lockName = $context->subjectType !== null
            ? 'file-manager:subject:'.hash('sha256', $context->subjectType.'|'.$context->subjectId.'|'.$context->relation)
            : 'file-manager:catalog:'.hash('sha256', $context->operationKey);

        return Cache::lock($lockName, (int) config('file-manager.locks.seconds', 30))
            ->block((int) config('file-manager.locks.wait_seconds', 5), function () use ($descriptor, $context, $stored): ManagedFile {
                return DB::transaction(function () use ($descriptor, $context, $stored): ManagedFile {
                    $existing = ManagedFile::query()
                        ->where('operation_key', $context->operationKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof ManagedFile) {
                        return $existing;
                    }

                    if ($context->subjectType !== null && ! $this->configuration->isSubjectTypeAllowed($context->subjectType)) {
                        throw new \InvalidArgumentException('subject_type nao autorizado.');
                    }

                    $file = ManagedFile::query()->create([
                        'uuid' => Str::uuid()->toString(),
                        'operation_key' => $context->operationKey,
                        'original_name' => FilePathGuard::safeFileName($descriptor->originalName, $stored->extension),
                        'safe_download_name' => $stored->safeDownloadName,
                        'extension' => $stored->extension,
                        'declared_mime_type' => $descriptor->declaredMimeType,
                        'detected_mime_type' => $stored->detectedMimeType,
                        'size_bytes' => $stored->sizeBytes,
                        'sha256' => $stored->sha256,
                        'storage_disk' => $stored->disk,
                        'storage_key' => $stored->storageKey,
                        'category' => $context->category->value,
                        'origin' => $context->origin,
                        'lifecycle_status' => FileLifecycleStatus::Active,
                        'integrity_status' => FileIntegrityStatus::Valid,
                        'security_status' => FileSecurityStatus::Clean,
                        'migration_status' => str_starts_with(
                            $stored->storageKey,
                            FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.root')).'/'
                        ) ? FileMigrationStatus::Native : FileMigrationStatus::Cataloged,
                        'visibility' => 'private',
                        'confidentiality' => 'confidential',
                        'created_by' => $context->createdBy,
                        'metadata_json' => $context->metadata !== [] ? $context->metadata : null,
                    ]);

                    $this->events->record(
                        ManagedFileAction::Registered,
                        'success',
                        $file,
                        $context->createdBy,
                        $context->category->value,
                        ['category' => $context->category->value, 'origin' => $context->origin->value]
                    );

                    if ($context->subjectType !== null && $context->subjectId !== null && $context->relation !== null) {
                        ManagedFileLink::query()
                            ->where('subject_type', $context->subjectType)
                            ->where('subject_id', $context->subjectId)
                            ->where('relation', $context->relation)
                            ->where('is_current', true)
                            ->update(['is_current' => false, 'unlinked_at' => now()]);

                        ManagedFileLink::query()->firstOrCreate([
                            'file_id' => $file->id,
                            'subject_type' => $context->subjectType,
                            'subject_id' => $context->subjectId,
                            'relation' => $context->relation,
                        ], [
                            'is_current' => true,
                            'created_by' => $context->createdBy,
                            'metadata_json' => null,
                        ]);

                        $this->events->record(
                            ManagedFileAction::Linked,
                            'success',
                            $file,
                            $context->createdBy,
                            $context->category->value,
                            ['relation' => $context->relation]
                        );
                    }

                    return $file->fresh(['links', 'events']) ?? $file;
                }, attempts: 3);
            });
    }
}
