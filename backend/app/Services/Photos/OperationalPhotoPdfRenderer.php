<?php

namespace App\Services\Photos;

use App\Exceptions\OperationalPhotoException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class OperationalPhotoPdfRenderer
{
    public function __construct(private readonly VipsCommandRunner $runner) {}

    public function jpegBytes(string $sourcePath): ?string
    {
        $directory = (string) config(
            'operational-photos.temporary_directory',
            storage_path('app/private/operational-photo-tmp'),
        );
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return null;
        }
        @chmod($directory, 0700);

        $targetPath = $directory.DIRECTORY_SEPARATOR.Str::uuid()->toString().'.jpg';
        try {
            $this->runner->thumbnail(
                $sourcePath,
                $targetPath,
                (int) config('operational-photos.pdf_max_dimension', 1920),
                (int) config('operational-photos.pdf_jpeg_quality', 85),
                'jpeg',
            );

            $bytes = file_get_contents($targetPath);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        } catch (OperationalPhotoException $exception) {
            Log::warning('AVIF photo could not be rendered for PDF.', [
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
