<?php

namespace App\Services\Files;

use App\Enums\Files\FileIntegrityStatus;
use App\Models\Files\ManagedFile;
use Illuminate\Support\Facades\Storage;

class FileIntegrityService
{
    public function __construct(private readonly FileStateMachine $states) {}

    /**
     * @return array{processed: int, valid: int, missing: int, corrupted: int}
     */
    public function checkBatch(int $limit = 500): array
    {
        $counters = ['processed' => 0, 'valid' => 0, 'missing' => 0, 'corrupted' => 0];

        ManagedFile::query()
            ->orderBy('id')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->each(function (ManagedFile $file) use (&$counters): void {
                $counters['processed']++;
                $disk = Storage::disk($file->storage_disk);

                if (! $disk->exists($file->storage_key)) {
                    $this->states->markIntegrity($file, FileIntegrityStatus::Missing);
                    $counters['missing']++;

                    return;
                }

                [$size, $sha256] = $this->fingerprint($file->storage_disk, $file->storage_key);
                $status = $size === $file->size_bytes && hash_equals($file->sha256, $sha256)
                    ? FileIntegrityStatus::Valid
                    : FileIntegrityStatus::Corrupted;
                $this->states->markIntegrity($file, $status);
                $counters[$status->value]++;
            });

        return $counters;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function fingerprint(string $diskName, string $storageKey): array
    {
        $stream = Storage::disk($diskName)->readStream($storageKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Arquivo indisponivel durante verificacao de integridade.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new \RuntimeException('Falha de leitura durante verificacao de integridade.');
            }
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);

        return [$size, hash_final($hash)];
    }
}
