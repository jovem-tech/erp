<?php

namespace App\Services\Orders\Documents;

use App\Models\OrderDocument;
use App\Services\Files\PdfThumbnailRasterizer;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Miniatura (1ª página) de uma versão documental da OS sem depender de
 * managed_files: documento sob demanda não é catalogado no gerenciador. O
 * PNG vive no disco de cache de render, chaveado pelo render do snapshot
 * (ou pelo sha256 do arquivo em disco), e o PDF só é materializado em
 * temporário pelo tempo do pdftocairo.
 */
final class OrderDocumentThumbnailService
{
    public function __construct(private readonly PdfThumbnailRasterizer $rasterizer)
    {
    }

    /** @return array{absolute_path: string, etag: string, cache_seconds: int} */
    public function firstPage(OrderDocument $document, ResolvedDocumentFile $file, ?string $renderCacheKey = null): array
    {
        if (! (bool) config('document-rendering.thumbnails.enabled', false)) {
            throw new \RuntimeException('Miniaturas PDF desabilitadas.');
        }

        if (strtolower($file->mimeType) !== 'application/pdf') {
            throw new \UnexpectedValueException('Miniatura disponivel apenas para PDF.');
        }

        $key = strtolower(trim((string) ($renderCacheKey ?? '')));
        if (preg_match('/^[0-9a-f]{64}$/', $key) !== 1) {
            $key = $file->isOnDisk() ? $file->sha256() : hash('sha256', 'doc:'.(int) $document->id.':'.$file->source);
        }

        $maxDimension = (int) config('document-rendering.thumbnails.max_dimension', 480);
        $disk = $this->disk();
        $relativePath = 'thumbs/'.substr($key, 0, 2).'/'.$key.'-'.$maxDimension.'.png';

        $cached = $this->rasterizer->validCachedPath($disk, $relativePath, $maxDimension);
        if ($cached === null) {
            $lockSeconds = (int) config('file-manager.pdf_thumbnails.lock_seconds', 20);
            $waitSeconds = (int) config('file-manager.pdf_thumbnails.lock_wait_seconds', 5);
            $cached = Cache::lock('order-document:pdf-thumbnail:'.$key, $lockSeconds)
                ->block($waitSeconds, function () use ($disk, $relativePath, $maxDimension, $file): string {
                    return $this->rasterizer->validCachedPath($disk, $relativePath, $maxDimension)
                        ?? $file->withTempFile(
                            fn (string $sourcePath): string => $this->rasterizer->renderToCache($disk, $relativePath, $maxDimension, $sourcePath)
                        );
                });
        }

        return [
            'absolute_path' => $cached,
            'etag' => 'pdf-p1-'.$key.'-'.$maxDimension,
            'cache_seconds' => (int) config('file-manager.pdf_thumbnails.browser_cache_seconds', 86400),
        ];
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk((string) config('document-rendering.render_cache.disk', 'pdf_render_cache'));

        return $disk;
    }
}
