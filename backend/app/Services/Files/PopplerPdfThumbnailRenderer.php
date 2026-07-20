<?php

namespace App\Services\Files;

use App\Contracts\Files\PdfThumbnailRenderer;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PopplerPdfThumbnailRenderer implements PdfThumbnailRenderer
{
    public function render(
        string $sourcePath,
        string $targetPath,
        int $maxDimension,
        int $timeoutSeconds
    ): void {
        $configuredBinary = trim((string) config('file-manager.pdf_thumbnails.renderer_binary', ''));
        $binary = $configuredBinary !== '' ? realpath($configuredBinary) : false;
        if (! is_string($binary) || ! is_file($binary) || ! is_executable($binary)) {
            throw new \RuntimeException('Renderizador de miniaturas PDF indisponivel.');
        }
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \RuntimeException('PDF indisponivel para renderizacao.');
        }

        $outputPrefix = preg_replace('/\.png$/i', '', $targetPath);
        if (! is_string($outputPrefix) || $outputPrefix === $targetPath) {
            throw new \InvalidArgumentException('Destino de miniatura invalido.');
        }

        $process = new Process([
            $binary,
            '-png',
            '-f', '1',
            '-l', '1',
            '-singlefile',
            '-scale-to', (string) $maxDimension,
            $sourcePath,
            $outputPrefix,
        ]);
        $process->setTimeout($timeoutSeconds);
        $process->disableOutput();

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException('Tempo limite da miniatura PDF excedido.');
        }

        if (! $process->isSuccessful() || ! is_file($targetPath)) {
            throw new \RuntimeException('Nao foi possivel renderizar a miniatura PDF.');
        }
    }
}
