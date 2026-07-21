<?php

namespace App\Services\Files;

use App\Contracts\Files\PdfThumbnailRenderer;
use App\Models\Files\ManagedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfThumbnailService
{
    public function __construct(
        private readonly ManagedFileDeliveryService $delivery,
        private readonly PdfThumbnailRenderer $renderer
    ) {}

    /** @return array{absolute_path: string, etag: string, cache_seconds: int} */
    public function firstPage(ManagedFile $file, bool $allowTrashedPreview = false): array
    {
        if (! (bool) config('file-manager.pdf_thumbnails.enabled', false)) {
            throw new \RuntimeException('Miniaturas PDF desabilitadas.');
        }

        $source = $this->delivery->locate($file, $allowTrashedPreview);
        if ($source['mime_type'] !== 'application/pdf') {
            throw new \UnexpectedValueException('Miniatura disponivel apenas para PDF.');
        }

        $sha256 = strtolower(trim((string) $file->sha256));
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new \RuntimeException('Hash do PDF invalido para cache.');
        }

        $diskName = (string) config('file-manager.pdf_thumbnails.disk', 'local');
        if (! in_array($diskName, (array) config('file-manager.storage.allowed_disks', []), true)) {
            throw new \RuntimeException('Disco de miniaturas nao autorizado.');
        }

        $root = FilePathGuard::normalizeRelativePath(
            (string) config('file-manager.pdf_thumbnails.root', 'file-thumbnails/pdf')
        );
        $maxDimension = (int) config('file-manager.pdf_thumbnails.max_dimension', 480);
        $relativePath = $root.'/'.substr($sha256, 0, 2).'/'.$sha256.'-'.$maxDimension.'.png';
        $disk = Storage::disk($diskName);

        $cached = $this->validCachedPath($disk, $relativePath, $maxDimension);
        if ($cached === null) {
            $lockSeconds = (int) config('file-manager.pdf_thumbnails.lock_seconds', 20);
            $waitSeconds = (int) config('file-manager.pdf_thumbnails.lock_wait_seconds', 5);
            $cached = Cache::lock('file-manager:pdf-thumbnail:'.$sha256, $lockSeconds)
                ->block($waitSeconds, function () use ($disk, $relativePath, $maxDimension, $source): string {
                    return $this->validCachedPath($disk, $relativePath, $maxDimension)
                        ?? $this->render($disk, $relativePath, $maxDimension, $source['absolute_path']);
                });
        }

        return [
            'absolute_path' => $cached,
            'etag' => 'pdf-p1-'.$sha256.'-'.$maxDimension,
            'cache_seconds' => (int) config('file-manager.pdf_thumbnails.browser_cache_seconds', 86400),
        ];
    }

    public function forget(ManagedFile $file): void
    {
        $sha256 = strtolower(trim((string) $file->sha256));
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return;
        }

        $disk = Storage::disk((string) config('file-manager.pdf_thumbnails.disk', 'local'));
        $root = FilePathGuard::normalizeRelativePath(
            (string) config('file-manager.pdf_thumbnails.root', 'file-thumbnails/pdf')
        );
        $directory = $root.'/'.substr($sha256, 0, 2);
        foreach ($disk->files($directory) as $candidate) {
            if (str_starts_with(basename($candidate), $sha256.'-')) {
                $disk->delete($candidate);
            }
        }
    }

    private function render(
        FilesystemAdapter $disk,
        string $relativePath,
        int $maxDimension,
        string $sourcePath
    ): string {
        $directory = dirname($relativePath);
        if (! $disk->makeDirectory($directory) && ! $disk->directoryExists($directory)) {
            throw new \RuntimeException('Nao foi possivel preparar o cache de miniaturas.');
        }

        $absoluteDirectory = $this->containedDirectory($disk, $directory);
        $targetPath = $absoluteDirectory.DIRECTORY_SEPARATOR.basename($relativePath);
        $temporaryPath = $targetPath.'.'.Str::random(20).'.tmp.png';

        try {
            $this->renderer->render(
                $sourcePath,
                $temporaryPath,
                $maxDimension,
                (int) config('file-manager.pdf_thumbnails.timeout_seconds', 10)
            );
            $this->assertValidPng($temporaryPath, $maxDimension);
            @chmod($temporaryPath, 0640);

            if (! @rename($temporaryPath, $targetPath)) {
                throw new \RuntimeException('Nao foi possivel promover a miniatura PDF.');
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return $this->validCachedPath($disk, $relativePath, $maxDimension)
            ?? throw new \RuntimeException('Miniatura PDF invalida apos renderizacao.');
    }

    private function validCachedPath(
        FilesystemAdapter $disk,
        string $relativePath,
        int $maxDimension
    ): ?string {
        if (! $disk->exists($relativePath)) {
            return null;
        }

        $root = realpath($disk->path(''));
        $candidate = realpath($disk->path($relativePath));
        if (! is_string($root) || ! is_string($candidate)) {
            return null;
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedCandidate = str_replace('\\', '/', $candidate);
        if (! str_starts_with($normalizedCandidate, $root) || ! is_file($candidate) || ! is_readable($candidate)) {
            return null;
        }

        try {
            $this->assertValidPng($candidate, $maxDimension);
        } catch (\RuntimeException) {
            return null;
        }

        return $candidate;
    }

    private function containedDirectory(FilesystemAdapter $disk, string $relativeDirectory): string
    {
        $root = realpath($disk->path(''));
        $directory = realpath($disk->path($relativeDirectory));
        if (! is_string($root) || ! is_string($directory)) {
            throw new \RuntimeException('Diretorio de cache indisponivel.');
        }

        $root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/').'/';
        if (! str_starts_with($normalizedDirectory, $root)) {
            throw new \RuntimeException('Diretorio de cache fora da raiz autorizada.');
        }

        return $directory;
    }

    private function assertValidPng(string $path, int $maxDimension): void
    {
        $size = @filesize($path);
        $maximumBytes = (int) config('file-manager.pdf_thumbnails.max_bytes', 2_097_152);
        if (! is_int($size) || $size < 16 || $size > $maximumBytes) {
            throw new \RuntimeException('Tamanho da miniatura PDF invalido.');
        }

        $image = @getimagesize($path);
        $width = is_array($image) ? (int) ($image[0] ?? 0) : 0;
        $height = is_array($image) ? (int) ($image[1] ?? 0) : 0;
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        if (
            $mime !== 'image/png'
            || $width < 1
            || $height < 1
            || $width > $maxDimension
            || $height > $maxDimension
        ) {
            throw new \RuntimeException('Conteudo da miniatura PDF invalido.');
        }
    }
}
