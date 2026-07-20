<?php

namespace App\Services\Files;

use App\Contracts\Files\FileCatalog;
use App\Contracts\Files\FileStorage;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FileManagerFacade
{
    public function __construct(
        private readonly FileManagerConfiguration $configuration,
        private readonly FilePolicyRegistry $policies,
        private readonly FileStorage $storage,
        private readonly FileCatalog $catalog
    ) {}

    public function store(FileDescriptor $descriptor, FileContext $context): ManagedFile
    {
        $existing = $this->catalog->findByOperationKey($context->operationKey);
        if ($existing instanceof ManagedFile) {
            return $existing;
        }

        $this->configuration->assertCanWrite($context->category);

        return Cache::lock(
            'file-manager:operation:'.hash('sha256', $context->operationKey),
            (int) config('file-manager.locks.seconds', 30)
        )->block((int) config('file-manager.locks.wait_seconds', 5), function () use ($descriptor, $context): ManagedFile {
            $existing = $this->catalog->findByOperationKey($context->operationKey);
            if ($existing instanceof ManagedFile) {
                return $existing;
            }

            $validated = $this->policies->validate($descriptor, $context->category);
            $stored = $this->storage->store($descriptor, $context, $validated['extension']);

            if (
                $stored->sizeBytes !== $validated['size_bytes']
                || $stored->detectedMimeType !== $validated['detected_mime_type']
            ) {
                $this->storage->deleteForCompensation($stored->disk, $stored->storageKey);
                throw new \RuntimeException('Resultado do storage diverge da validacao.');
            }

            try {
                $file = $this->catalog->register($descriptor, $context, $stored);
            } catch (\Throwable $exception) {
                try {
                    $cataloged = $this->catalog->findByOperationKey($context->operationKey);
                } catch (\Throwable $lookupException) {
                    Log::critical('[FILE_MANAGER] Blob preservado apos falha ambigua do catalogo.', [
                        'operation_key_hash' => hash('sha256', $context->operationKey),
                        'storage_key_hash' => hash('sha256', $stored->disk."\0".$stored->storageKey),
                        'catalog_error_type' => $exception::class,
                        'lookup_error_type' => $lookupException::class,
                    ]);

                    throw $exception;
                }

                if ($cataloged instanceof ManagedFile) {
                    if ($cataloged->storage_disk !== $stored->disk || $cataloged->storage_key !== $stored->storageKey) {
                        $this->deleteCandidateForCompensation($stored, $context);
                    }

                    return $cataloged;
                }

                $this->deleteCandidateForCompensation($stored, $context);
                throw $exception;
            }

            if ($file->storage_disk !== $stored->disk || $file->storage_key !== $stored->storageKey) {
                $this->storage->deleteForCompensation($stored->disk, $stored->storageKey);
            }

            return $file;
        });
    }

    private function deleteCandidateForCompensation(StoredFileResult $stored, FileContext $context): void
    {
        try {
            $this->storage->deleteForCompensation($stored->disk, $stored->storageKey);
        } catch (\Throwable $cleanupException) {
            Log::error('[FILE_MANAGER] Falha ao remover candidato nao catalogado.', [
                'operation_key_hash' => hash('sha256', $context->operationKey),
                'storage_key_hash' => hash('sha256', $stored->disk."\0".$stored->storageKey),
                'error_type' => $cleanupException::class,
            ]);
        }
    }
}
