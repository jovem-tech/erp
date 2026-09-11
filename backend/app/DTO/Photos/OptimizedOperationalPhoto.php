<?php

namespace App\DTO\Photos;

final readonly class OptimizedOperationalPhoto
{
    public function __construct(
        public string $path,
        public string $extension,
        public string $mimeType,
        public int $sizeBytes,
        public string $originalName,
        public bool $temporary,
        public bool $sourcePreserved,
    ) {}
}
