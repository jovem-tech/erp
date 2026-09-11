<?php

namespace App\Services\Photos;

use App\DTO\Photos\OperationalPhotoMetadata;
use App\Exceptions\OperationalPhotoException;

class OperationalPhotoInspector
{
    /** @var array<string, list<string>> */
    private const EXTENSIONS_BY_MIME = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/avif' => ['avif'],
        'image/heic' => ['heic', 'heif'],
        'image/heif' => ['heic', 'heif'],
    ];

    public function __construct(private readonly VipsCommandRunner $runner) {}

    public function inspect(string $path): OperationalPhotoMetadata
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'O arquivo de foto não pôde ser lido.',
            );
        }

        $sizeBytes = (int) filesize($path);
        if ($sizeBytes < 1 || $sizeBytes > (int) config('operational-photos.max_input_bytes')) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'A foto deve ter no máximo 20 MB.',
                $sizeBytes > (int) config('operational-photos.max_input_bytes') ? 413 : 422,
            );
        }

        $mimeType = self::detectMimeType($path);
        if (! array_key_exists($mimeType, self::EXTENSIONS_BY_MIME)) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'Envie uma foto JPEG, PNG, WebP, AVIF, HEIC ou HEIF.',
            );
        }

        $header = $this->runner->header($path);
        $width = $this->integerHeader($header, 'width');
        $height = $this->integerHeader($header, 'height');

        if ($width < 1 || $height < 1) {
            if (preg_match('/:\s*(\d+)x(\d+)\s+/m', $header, $matches) === 1) {
                $width = (int) $matches[1];
                $height = (int) $matches[2];
            }
        }

        if ($width < 1 || $height < 1 || $width > intdiv(PHP_INT_MAX, $height)) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'Não foi possível validar as dimensões da foto.',
            );
        }

        $pixelCount = $width * $height;
        if ($pixelCount > (int) config('operational-photos.max_pixels')) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'A foto excede o limite de 60 megapixels.',
            );
        }

        $pageCount = max(1, $this->integerHeader($header, 'n-pages'));
        if ($pageCount !== 1) {
            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'Fotos animadas, sequências HEIF e arquivos com múltiplos quadros não são aceitos.',
            );
        }

        $orientation = max(1, $this->integerHeader($header, 'orientation'));
        $hasSensitiveMetadata = preg_match(
            '/^(?:exif-[^:\r\n]+|xmp-data|iptc-data|[^:\r\n]*comment[^:\r\n]*|image-description):/mi',
            $header,
        ) === 1;

        return new OperationalPhotoMetadata(
            $mimeType,
            $sizeBytes,
            $width,
            $height,
            $pageCount,
            $orientation,
            $hasSensitiveMetadata,
        );
    }

    public static function detectMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detected = strtolower((string) $finfo->file($path));
        $isobmffMime = self::detectIsoBmffMime($path);

        if ($isobmffMime !== null) {
            return $isobmffMime;
        }

        return match ($detected) {
            'image/jpg', 'image/pjpeg' => 'image/jpeg',
            'image/x-png' => 'image/png',
            'image/x-webp' => 'image/webp',
            default => $detected,
        };
    }

    public static function extensionMatchesMime(string $extension, string $mimeType): bool
    {
        return in_array(strtolower($extension), self::EXTENSIONS_BY_MIME[$mimeType] ?? [], true);
    }

    /** @return list<string> */
    public static function acceptedExtensions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::EXTENSIONS_BY_MIME))));
    }

    private static function detectIsoBmffMime(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $header = (string) fread($handle, 128);
        } finally {
            fclose($handle);
        }

        if (strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
            return null;
        }

        $brands = substr($header, 8);
        if (str_contains($brands, 'avif') || str_contains($brands, 'avis')) {
            return 'image/avif';
        }

        foreach (['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis'] as $brand) {
            if (str_contains($brands, $brand)) {
                return 'image/heic';
            }
        }

        if (str_contains($brands, 'mif1') || str_contains($brands, 'msf1')) {
            return 'image/heif';
        }

        return null;
    }

    private function integerHeader(string $header, string $field): int
    {
        return preg_match('/^'.preg_quote($field, '/').':\s*(\d+)/mi', $header, $matches) === 1
            ? (int) $matches[1]
            : 0;
    }
}
