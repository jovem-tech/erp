<?php

namespace App\DTO\Photos;

final readonly class OperationalPhotoMetadata
{
    public function __construct(
        public string $mimeType,
        public int $sizeBytes,
        public int $width,
        public int $height,
        public int $pageCount,
        public int $orientation,
        public bool $hasSensitiveMetadata,
    ) {}

    public function longestSide(): int
    {
        return max($this->width, $this->height);
    }
}
