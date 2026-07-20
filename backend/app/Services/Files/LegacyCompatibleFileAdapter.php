<?php

namespace App\Services\Files;

use App\Contracts\Files\FileCatalog;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use App\Enums\Files\FileManagerMode;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LegacyCompatibleFileAdapter
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FilePolicyRegistry $policies,
        private readonly FileCatalog $catalog,
        private readonly LegacyFileResolver $legacyResolver,
        private readonly LegacyFileObservationService $observer,
        private readonly FileManagerMetrics $metrics
    ) {}

    public function synchronizeExisting(
        FileContext $context,
        string $diskName,
        string $storagePath,
        ?string $sourceTable = null,
        ?string $sourceColumn = null,
        ?string $sourceRecordId = null
    ): ?ManagedFile {
        $mode = $this->configuration->mode();
        if ($mode === FileManagerMode::Off || ! $this->configuration->isCategoryEnabled($context->category)) {
            return null;
        }

        $this->observer->observeStored($context->category, $diskName, $storagePath);
        if ($mode === FileManagerMode::Observe) {
            return null;
        }

        try {
            if ($mode === FileManagerMode::Hybrid) {
                $this->configuration->assertCanWrite($context->category);
            }

            $file = $this->catalogExisting($context, $diskName, $storagePath);
            $this->legacyResolver->addAlias(
                $file,
                $diskName,
                $storagePath,
                $sourceTable,
                $sourceColumn,
                $sourceRecordId
            );

            if ($mode === FileManagerMode::Shadow) {
                $this->metrics->increment($context->category, 'shadow_catalog_success');
            }

            return $file;
        } catch (\Throwable $exception) {
            if ($mode === FileManagerMode::Shadow) {
                $this->metrics->increment($context->category, 'shadow_catalog_failure');
                Log::warning('[FILE_MANAGER] Falha isolada durante catalogacao shadow.', [
                    'category' => $context->category->value,
                    'operation_key_hash' => hash('sha256', $context->operationKey),
                    'error_type' => $exception::class,
                ]);

                return null;
            }

            throw $exception;
        }
    }

    private function catalogExisting(
        FileContext $context,
        string $diskName,
        string $storagePath
    ): ManagedFile {
        if (! $this->configuration->isLegacyReadDiskAllowed($diskName)) {
            throw new \InvalidArgumentException('Disco compativel nao autorizado.');
        }

        $storagePath = FilePathGuard::normalizeRelativePath($storagePath);
        $disk = Storage::disk($diskName);
        if (! $disk->exists($storagePath)) {
            throw new \RuntimeException('Arquivo compativel nao existe no storage.');
        }

        $absolutePath = $disk->path($storagePath);
        $mimeType = (string) ($disk->mimeType($storagePath) ?: 'application/octet-stream');
        $descriptor = new FileDescriptor($absolutePath, basename($storagePath), $mimeType);
        $validated = $this->policies->validate($descriptor, $context->category);
        $sha256 = hash_file('sha256', $absolutePath);
        if (! is_string($sha256)) {
            throw new \RuntimeException('Nao foi possivel calcular o hash do arquivo compativel.');
        }

        return $this->catalog->register($descriptor, $context, new StoredFileResult(
            disk: $diskName,
            storageKey: $storagePath,
            safeDownloadName: FilePathGuard::safeFileName(basename($storagePath), $validated['extension']),
            extension: $validated['extension'],
            detectedMimeType: $validated['detected_mime_type'],
            sizeBytes: $validated['size_bytes'],
            sha256: $sha256
        ));
    }
}
