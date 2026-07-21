<?php

namespace App\Services\Files;

use App\Enums\Files\FileLifecycleStatus;
use App\Enums\Files\ManagedFileAction;
use App\Models\Files\ManagedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ManagedFilePurgeService
{
    public function __construct(
        private readonly ManagedFileEventRecorder $events,
        private readonly PdfThumbnailService $pdfThumbnails
    ) {}

    public function purge(
        ManagedFile $file,
        ?int $actorId,
        string $reason,
        ?int $authorizedBy,
        string $source,
        ?int $retentionDays = null
    ): ManagedFile {
        if (! (bool) config('file-manager.kill_switches.allow_permanent_deletion', false)) {
            throw new \DomainException('Exclusão definitiva desabilitada pelo kill switch.');
        }

        $lockSeconds = max(10, (int) config('file-manager.locks.seconds', 30));
        $waitSeconds = max(1, (int) config('file-manager.locks.wait_seconds', 5));

        try {
            return Cache::lock('file-manager:purge:'.(string) $file->uuid, $lockSeconds)
                ->block($waitSeconds, function () use ($file, $actorId, $reason, $authorizedBy, $source, $retentionDays): ManagedFile {
                    return DB::transaction(function () use ($file, $actorId, $reason, $authorizedBy, $source, $retentionDays): ManagedFile {
                        $locked = ManagedFile::query()->lockForUpdate()->findOrFail($file->id);
                        if ($locked->lifecycle_status === FileLifecycleStatus::Purged) {
                            return $locked;
                        }
                        if ($locked->lifecycle_status !== FileLifecycleStatus::Trashed) {
                            throw new \DomainException('Somente arquivos na lixeira podem ser excluídos definitivamente.');
                        }
                        if ((bool) data_get($locked->metadata_json, 'legal_hold', false)) {
                            throw new \DomainException('Arquivo protegido por retenção legal não pode ser excluído.');
                        }

                        [$disk, $storageKey] = $this->authorizedTarget($locked);
                        $binaryExisted = $disk->exists($storageKey);
                        if ($binaryExisted) {
                            $this->assertPhysicalContainment($disk, $storageKey);
                            if (! $disk->delete($storageKey) || $disk->exists($storageKey)) {
                                throw new \RuntimeException('O storage não confirmou a exclusão definitiva do binário.');
                            }
                        }

                        try {
                            $this->pdfThumbnails->forget($locked);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }

                        $locked->forceFill([
                            'lifecycle_status' => FileLifecycleStatus::Purged,
                            'purged_at' => now(),
                        ])->save();

                        $this->events->record(
                            ManagedFileAction::Purged,
                            'success',
                            $locked,
                            $actorId,
                            (string) $locked->category,
                            [
                                'reason' => $reason,
                                'authorized_by' => $authorizedBy,
                                'purge_source' => $source,
                                'retention_days' => $retentionDays,
                                'binary_existed' => $binaryExisted,
                                'path_hash' => hash('sha256', (string) $locked->storage_disk.'|'.$storageKey),
                                'size_bytes' => (int) $locked->size_bytes,
                            ]
                        );

                        return $locked->fresh() ?? $locked;
                    });
                });
        } catch (\Throwable $exception) {
            try {
                $this->events->record(
                    ManagedFileAction::Purged,
                    'failed',
                    $file,
                    $actorId,
                    (string) $file->category,
                    [
                        'reason' => $reason,
                        'authorized_by' => $authorizedBy,
                        'purge_source' => $source,
                        'retention_days' => $retentionDays,
                        'path_hash' => hash('sha256', (string) $file->storage_disk.'|'.(string) $file->storage_key),
                        'size_bytes' => (int) $file->size_bytes,
                    ]
                );
            } catch (\Throwable $auditException) {
                report($auditException);
            }

            throw $exception;
        }
    }

    /** @return array{0: FilesystemAdapter, 1: string} */
    private function authorizedTarget(ManagedFile $file): array
    {
        $diskName = trim((string) $file->storage_disk);
        $allowedDisks = array_values(array_unique(array_map(
            'strval',
            (array) config('file-manager.storage.allowed_disks', [])
        )));
        if (! in_array($diskName, $allowedDisks, true)) {
            throw new \RuntimeException('Disco recusado para exclusão definitiva.');
        }

        $storageKey = FilePathGuard::normalizeRelativePath((string) $file->storage_key);
        $prefixes = [];
        if ($diskName === (string) config('file-manager.storage.disk', 'local')) {
            $prefixes[] = FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.root', 'managed-files'));
        }
        foreach ((array) config('file-manager.scanner.roots', []) as $root) {
            if (is_array($root) && (string) ($root['disk'] ?? '') === $diskName) {
                $prefixes[] = FilePathGuard::normalizeRelativePath((string) ($root['path'] ?? ''));
            }
        }

        foreach (array_unique(array_filter($prefixes)) as $prefix) {
            if ($storageKey === $prefix || str_starts_with($storageKey, $prefix.'/')) {
                return [Storage::disk($diskName), $storageKey];
            }
        }

        throw new \RuntimeException('Arquivo fora dos namespaces autorizados para exclusão definitiva.');
    }

    private function assertPhysicalContainment(FilesystemAdapter $disk, string $storageKey): void
    {
        $unresolved = $disk->path($storageKey);
        if (is_link($unresolved)) {
            throw new \RuntimeException('Exclusão de link simbólico recusada.');
        }

        $root = realpath($disk->path(''));
        $candidate = realpath($unresolved);
        if (! is_string($root) || ! is_string($candidate)) {
            throw new \RuntimeException('Não foi possível validar o caminho antes da exclusão.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $candidate = str_replace('\\', '/', $candidate);
        if (! str_starts_with($candidate, $root) || ! is_file($candidate)) {
            throw new \RuntimeException('Exclusão recusada fora da raiz física autorizada.');
        }
    }
}
