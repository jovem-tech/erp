<?php

namespace App\Services\Files;

use App\Contracts\Files\FileStorage;
use App\DTO\Files\FileContext;
use App\DTO\Files\FileDescriptor;
use App\DTO\Files\StoredFileResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class LocalFileStorage implements FileStorage
{
    public function store(FileDescriptor $descriptor, FileContext $context, string $extension): StoredFileResult
    {
        $diskName = (string) config('file-manager.storage.disk', 'local');
        $disk = Storage::disk($diskName);
        $uuid = Str::uuid()->toString();
        $root = FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.root'));
        $stagingRoot = FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.staging_root'));
        $storageKey = FilePathGuard::normalizeRelativePath(
            $root.'/'.$context->category->value.'/'.now()->format('Y/m/d').'/'.$uuid.'.'.$extension
        );
        $stagingKey = FilePathGuard::normalizeRelativePath($stagingRoot.'/'.$uuid.'.part');
        $promoted = false;

        try {
            $source = fopen($descriptor->sourcePath, 'rb');
            if (! is_resource($source)) {
                throw new \RuntimeException('Falha ao abrir arquivo para staging.');
            }

            $written = $disk->writeStream($stagingKey, $source);
            fclose($source);
            if (! $written) {
                throw new \RuntimeException('Falha ao gravar arquivo em staging.');
            }

            [$sizeBytes, $sha256] = $this->fingerprint($diskName, $stagingKey);
            $lock = Cache::lock(
                'file-manager:promote:'.hash('sha256', $diskName.'|'.$storageKey),
                (int) config('file-manager.locks.seconds', 30)
            );
            $lock->block((int) config('file-manager.locks.wait_seconds', 5), function () use ($disk, $stagingKey, $storageKey, &$promoted): void {
                if ($disk->exists($storageKey)) {
                    throw new \RuntimeException('Tentativa de sobrescrever chave imutavel.');
                }

                if (! $disk->move($stagingKey, $storageKey)) {
                    throw new \RuntimeException('Falha ao promover arquivo de staging.');
                }

                $promoted = true;
            });

            [$verifiedSize, $verifiedHash] = $this->fingerprint($diskName, $storageKey);
            if ($verifiedSize !== $sizeBytes || ! hash_equals($sha256, $verifiedHash)) {
                throw new \RuntimeException('Arquivo promovido diverge do staging.');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $absolutePath = $disk->path($storageKey);

            return new StoredFileResult(
                disk: $diskName,
                storageKey: $storageKey,
                safeDownloadName: FilePathGuard::safeFileName($descriptor->originalName, $extension),
                extension: $extension,
                detectedMimeType: (string) ($finfo->file($absolutePath) ?: 'application/octet-stream'),
                sizeBytes: $verifiedSize,
                sha256: $verifiedHash
            );
        } catch (\Throwable $exception) {
            foreach ([$stagingKey, $promoted ? $storageKey : null] as $candidate) {
                if (is_string($candidate) && $disk->exists($candidate)) {
                    $disk->delete($candidate);
                }
            }

            throw $exception;
        }
    }

    public function readStream(StoredFileResult $file)
    {
        $stream = Storage::disk($file->disk)->readStream($file->storageKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Arquivo central indisponivel para leitura.');
        }

        return $stream;
    }

    public function exists(string $disk, string $storageKey): bool
    {
        return Storage::disk($disk)->exists(FilePathGuard::normalizeRelativePath($storageKey));
    }

    public function deleteForCompensation(string $disk, string $storageKey): void
    {
        $configuredDisk = (string) config('file-manager.storage.disk');
        $root = FilePathGuard::normalizeRelativePath((string) config('file-manager.storage.root'));
        $storageKey = FilePathGuard::normalizeRelativePath($storageKey);

        if ($disk !== $configuredDisk || ! str_starts_with($storageKey, $root.'/')) {
            throw new \RuntimeException('Compensacao recusada fora do namespace central.');
        }

        Storage::disk($disk)->delete($storageKey);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function fingerprint(string $diskName, string $storageKey): array
    {
        $stream = Storage::disk($diskName)->readStream($storageKey);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Nao foi possivel ler o arquivo para calcular integridade.');
        }

        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new \RuntimeException('Falha ao calcular integridade do arquivo.');
            }

            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);

        return [$size, hash_final($hash)];
    }
}
