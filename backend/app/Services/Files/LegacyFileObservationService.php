<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use App\Enums\Files\ManagedFileAction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LegacyFileObservationService
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly ManagedFileEventRecorder $events
    ) {}

    public function observeStored(FileCategory $category, string $diskName, string $storagePath): void
    {
        try {
            if (
                ! $this->configuration->mode()->observesLegacyFlow()
                || ! in_array($category->value, (array) config('file-manager.enabled_categories', []), true)
                || ! in_array($diskName, (array) config('file-manager.storage.legacy_read_disks', []), true)
                || ! Schema::hasTable('managed_file_events')
            ) {
                return;
            }

            $storagePath = FilePathGuard::normalizeRelativePath($storagePath);
            $disk = Storage::disk($diskName);
            if (! $disk->exists($storagePath)) {
                return;
            }

            $this->events->record(
                ManagedFileAction::LegacyObserved,
                'success',
                module: $category->value,
                context: [
                    'category' => $category->value,
                    'origin' => 'legacy_flow',
                    'path_hash' => hash('sha256', $diskName."\0".$storagePath),
                    'size_bytes' => $disk->size($storagePath),
                    'detected_mime_type' => $disk->mimeType($storagePath) ?: null,
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('[FILE_MANAGER][OBSERVE] Falha isolada ao observar boundary legado.', [
                'category' => $category->value,
                'error_type' => $exception::class,
            ]);
        }
    }
}
