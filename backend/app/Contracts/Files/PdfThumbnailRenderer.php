<?php

namespace App\Contracts\Files;

interface PdfThumbnailRenderer
{
    public function render(
        string $sourcePath,
        string $targetPath,
        int $maxDimension,
        int $timeoutSeconds
    ): void;
}
