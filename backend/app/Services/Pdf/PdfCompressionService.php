<?php

namespace App\Services\Pdf;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Recompressão do PDF via Ghostscript para caber num orçamento de bytes
 * (document-rendering.max_bytes — todo PDF gerado, não só o assinado). Tenta
 * níveis crescentes de agressividade e fica com o menor resultado válido;
 * qualquer falha (gs ausente, timeout, saída inválida) devolve os bytes
 * originais — o teto é best-effort, nunca motivo para bloquear a emissão.
 */
final class PdfCompressionService
{
    /**
     * Nível 1: preset /screen do Ghostscript (72 dpi, o mais agressivo dos
     * presets padrão — usamos direto porque o teto de bytes é apertado o
     * bastante para justificar "comprimido ao extremo" em toda emissão).
     *
     * @var array<int, string>
     */
    private const LEVEL_1 = [
        '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.5',
        '-dPDFSETTINGS=/screen',
        '-dDetectDuplicateImages=true',
        '-dNOPAUSE',
        '-dBATCH',
        '-dQUIET',
        '-dSAFER',
    ];

    /**
     * Nível 2: além do /screen, força a recodificação JPEG das imagens numa
     * qualidade bem mais baixa e reduz a resolução efetiva — só entra quando
     * o nível 1 ainda estourou o teto.
     *
     * @var array<int, string>
     */
    private const LEVEL_2 = [
        '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.5',
        '-dPDFSETTINGS=/screen',
        '-dDetectDuplicateImages=true',
        '-dColorImageResolution=55',
        '-dGrayImageResolution=55',
        '-dColorImageDownsampleType=/Average',
        '-dGrayImageDownsampleType=/Average',
        '-dAutoFilterColorImages=false',
        '-dAutoFilterGrayImages=false',
        '-dColorImageFilter=/DCTEncode',
        '-dGrayImageFilter=/DCTEncode',
        '-dJPEGQ=18',
        '-dNOPAUSE',
        '-dBATCH',
        '-dQUIET',
        '-dSAFER',
    ];

    /**
     * Comprime até caber em $maxBytes (ou o máximo possível, se não couber).
     * Sem $maxBytes, aplica só o nível 1 (compressão "padrão máxima").
     */
    public function compress(string $bytes, ?int $maxBytes = null): string
    {
        if ($bytes === '' || ! (bool) config('document-rendering.archive.ghostscript', true)) {
            return $bytes;
        }

        $binary = (string) config('document-rendering.archive.gs_binary', '/usr/bin/gs');
        if ($binary === '' || ! is_executable($binary)) {
            logger()->warning('[PDF ENGINE] Ghostscript indisponível; PDF emitido sem compressão extra.', ['binary' => $binary]);

            return $bytes;
        }

        $best = $bytes;

        $level1 = $this->run($binary, self::LEVEL_1, $bytes);
        if ($level1 !== null && strlen($level1) < strlen($best)) {
            $best = $level1;
        }

        if ($maxBytes !== null && strlen($best) > $maxBytes) {
            $level2 = $this->run($binary, self::LEVEL_2, $bytes);
            if ($level2 !== null && strlen($level2) < strlen($best)) {
                $best = $level2;
            }
        }

        if ($maxBytes !== null && strlen($best) > $maxBytes) {
            logger()->warning('[PDF ENGINE] PDF acima do teto mesmo após compressão máxima.', [
                'bytes' => strlen($best),
                'max_bytes' => $maxBytes,
            ]);
        }

        return $best;
    }

    /**
     * Retrocompatibilidade com o único chamador anterior (compressão fixa,
     * sem teto de bytes — o teto universal agora é aplicado dentro do motor).
     */
    public function compressForArchive(string $bytes): string
    {
        return $this->compress($bytes);
    }

    /**
     * @param  array<int, string>  $settings
     */
    private function run(string $binary, array $settings, string $bytes): ?string
    {
        // O pacote `ghostscript` do Ubuntu roda `gs` sob um profile AppArmor
        // que só permite escrita em /tmp, /var/tmp ou dentro do $HOME do
        // usuário do processo (com extensão reconhecida) — storage/framework/
        // cache/pdf-tmp (fora dessas áreas para quem não tem HOME=/var/www)
        // é negado silenciosamente pelo kernel, não pelo PHP. /tmp é liberado
        // incondicionalmente pela abstraction `user-tmp`, então é o único
        // diretório que funciona em qualquer usuário/instalação — diferente
        // do `document-rendering.temp_directory` (pdftocairo/anexos do chat,
        // binários sem confinamento, esses sim configuráveis).
        $directory = sys_get_temp_dir();
        if (! is_dir($directory) || ! is_writable($directory)) {
            logger()->warning('[PDF ENGINE] Diretório temporário do sistema indisponível; PDF emitido sem compressão extra.', [
                'directory' => $directory,
            ]);

            return null;
        }

        $input = tempnam($directory, 'gs-in-');
        $output = tempnam($directory, 'gs-out-');
        if (! is_string($input) || ! is_string($output)) {
            return null;
        }

        try {
            @chmod($input, 0600);
            @chmod($output, 0600);
            if (file_put_contents($input, $bytes) === false) {
                return null;
            }

            $process = new Process([...[$binary], ...$settings, '-sOutputFile='.$output, $input]);
            $process->setTimeout((float) config('document-rendering.archive.gs_timeout_seconds', 20));
            $process->disableOutput();
            $process->run();

            if (! $process->isSuccessful()) {
                logger()->warning('[PDF ENGINE] Ghostscript falhou; PDF emitido sem compressão extra.', [
                    'exit_code' => $process->getExitCode(),
                ]);

                return null;
            }

            $compressed = file_get_contents($output);

            return is_string($compressed) && $compressed !== '' && str_starts_with($compressed, '%PDF-') ? $compressed : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
