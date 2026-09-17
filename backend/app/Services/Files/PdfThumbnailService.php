<?php

namespace App\Services\Files;

use App\Models\Files\ManagedFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class PdfThumbnailService
{
    public function __construct(
        private readonly ManagedFileDeliveryService $delivery,
        private readonly PdfThumbnailRasterizer $rasterizer
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
        return $this->rasterizer->renderToCache($disk, $relativePath, $maxDimension, $sourcePath);
    }

    private function validCachedPath(
        FilesystemAdapter $disk,
        string $relativePath,
        int $maxDimension
    ): ?string {
        return $this->rasterizer->validCachedPath($disk, $relativePath, $maxDimension);
    }
}
