<?php

namespace App\Services\Photos;

use App\Exceptions\OperationalPhotoException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OperationalPhotoPdfRenderer
{
    public function __construct(private readonly VipsCommandRunner $runner) {}

    public function jpegBytes(string $sourcePath, ?int $maxDimension = null, ?int $quality = null): ?string
    {
        return $this->forPdf($sourcePath, 'image/jpeg', $maxDimension, $quality)['bytes'] ?? null;
    }

    /**
     * Reduz qualquer foto ao tamanho que o PDF realmente precisa (a célula
     * da galeria A4 tem ~90 mm; 1400 px já passa de 350 dpi). JPEG/WebP/
     * AVIF/HEIC saem como JPEG no perfil pedido; PNG continua PNG para não
     * perder a transparência. Falha do vips devolve null e o chamador decide
     * (usar o original ou omitir a foto) — nunca derruba o documento.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function forPdf(string $sourcePath, string $sourceMime, ?int $maxDimension = null, ?int $quality = null): ?array
    {
        $directory = (string) config(
            'operational-photos.temporary_directory',
            storage_path('app/private/operational-photo-tmp'),
        );
        if ((! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) || ! is_writable($directory)) {
            // Sem diretório utilizável a foto entra no PDF sem compressão —
            // avisar, senão o laudo volta a pesar MBs sem ninguém perceber.
            Log::warning('Photo temporary directory unavailable; embedding original photo in PDF.', [
                'directory' => $directory,
                'owner_mismatch' => is_dir($directory) && function_exists('posix_geteuid') && fileowner($directory) !== posix_geteuid(),
            ]);

            return null;
        }
        @chmod($directory, 0700);

        $format = strtolower($sourceMime) === 'image/png' ? 'png' : 'jpeg';
        $targetPath = $directory.DIRECTORY_SEPARATOR.Str::uuid()->toString().($format === 'png' ? '.png' : '.jpg');
        try {
            $this->runner->thumbnail(
                $sourcePath,
                $targetPath,
                $maxDimension ?? (int) config('operational-photos.pdf_max_dimension', 1920),
                $quality ?? (int) config('operational-photos.pdf_jpeg_quality', 85),
                $format,
            );

            $bytes = file_get_contents($targetPath);

            return is_string($bytes) && $bytes !== ''
                ? ['bytes' => $bytes, 'mime' => $format === 'png' ? 'image/png' : 'image/jpeg']
                : null;
        } catch (OperationalPhotoException $exception) {
            Log::warning('Photo could not be rendered for PDF.', [
                'error_code' => $exception->errorCode,
                'path_hash' => hash('sha256', $sourcePath),
            ]);

            return null;
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
        }
    }
}
