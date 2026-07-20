<?php

namespace App\DTO\Files;

final readonly class FileDescriptor
{
    public function __construct(
        public string $sourcePath,
        public string $originalName,
        public ?string $declaredMimeType = null
    ) {
        if (
            $sourcePath === ''
            || str_contains($sourcePath, "\0")
            || ! is_file($sourcePath)
            || ! is_readable($sourcePath)
        ) {
            throw new \InvalidArgumentException('Arquivo de origem invalido ou ilegivel.');
        }

        if ($originalName === '' || str_contains($originalName, "\0")) {
            throw new \InvalidArgumentException('Nome original invalido.');
        }
    }
}
