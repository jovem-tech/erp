<?php

namespace App\DTO\Files;

final readonly class StoredFileResult
{
    public function __construct(
        public string $disk,
        public string $storageKey,
        public string $safeDownloadName,
        public string $extension,
        public string $detectedMimeType,
        public int $sizeBytes,
        public string $sha256
    ) {}
}
