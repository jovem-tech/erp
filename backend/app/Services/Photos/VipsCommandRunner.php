<?php

namespace App\Services\Photos;

use App\Exceptions\OperationalPhotoException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class VipsCommandRunner
{
    private const PREFLIGHT_HEIC_BASE64 = 'AAAAHGZ0eXBoZWljAAAAAG1pZjFoZWljbWlhZgAAAaltZXRhAAAAAAAAACFoZGxyAAAAAAAAAABwaWN0AAAAAAAAAAAAAAAAAAAAAA5waXRtAAAAAAABAAAANGlsb2MAAAAAREAAAgABAAAAAAHNAAEAAAAAAAAALgACAAAAAAH7AAEAAAAAAAAAvgAAADhpaW5mAAAAAAACAAAAFWluZmUCAAAAAAEAAGh2YzEAAAAAFWluZmUCAAABAAIAAEV4aWYAAAAA6GlwcnAAAADJaXBjbwAAAHVodmNDAQNwAAAAAAAAAAAAHvAA/P34+AAADwNgAAEAGEABDAH//wNwAAADAJAAAAMAAAMAHroCQGEAAQApQgEBA3AAAAMAkAAAAwAAAwAeoCCBBZbqrprm4CGgwIAAAAyAAAADAIRiAAEABkQBwXPBiQAAABRpc3BlAAAAAAAAAEAAAABAAAAAKGNsYXAAAAAQAAAAAQAAABAAAAAB////0AAAAAL////QAAAAAgAAABBwaXhpAAAAAAMICAgAAAAXaXBtYQAAAAAAAAABAAEEgQIEgwAAABppcmVmAAAAAAAAAA5jZHNjAAIAAQABAAAA9G1kYXQAAAAqKAGvDshNZoBYTErPY/4M7gUvyth7oO5UJjt0hkSwi9N7CZiAh4oumOFwAAAABkV4aWYAAElJKgAIAAAABgASAQMAAQAAAAEAAAAaAQUAAQAAAFYAAAAbAQUAAQAAAF4AAAAoAQMAAQAAAAIAAAATAgMAAQAAAAEAAABphwQAAQAAAGYAAAAAAAAAOGMAAOgDAAA4YwAA6AMAAAYAAJAHAAQAAAAwMjEwAZEHAAQAAAABAgMAAKAHAAQAAAAwMTAwAaADAAEAAAD//wAAAqAEAAEAAAAQAAAAA6AEAAEAAAAQAAAAAAAAAA==';

    private bool $preflightCompleted = false;

    public function header(string $sourcePath): string
    {
        return $this->run([
            $this->executable('header_binary'),
            '-a',
            $sourcePath,
        ]);
    }

    public function thumbnail(
        string $sourcePath,
        string $targetPath,
        int $maxDimension,
        int $quality,
        string $format,
        ?float $timeoutSeconds = null,
    ): void {
        $options = $format === 'avif'
            ? sprintf('[Q=%d,effort=%d,keep=none]', $quality, (int) config('operational-photos.avif_effort', 4))
            : sprintf('[Q=%d,optimize_coding,interlace,keep=none]', $quality);

        $this->run([
            $this->executable('thumbnail_binary'),
            $sourcePath,
            '--size',
            sprintf('%dx%d>', $maxDimension, $maxDimension),
            '--output-profile',
            'srgb',
            '--path',
            $targetPath.$options,
        ], $timeoutSeconds);

        if (! is_file($targetPath) || (int) filesize($targetPath) < 1) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSING_FAILED',
                'O processador não gerou uma imagem válida.',
            );
        }
    }

    public function assertAvailable(): void
    {
        $this->preflight();
    }

    public function preflight(): void
    {
        if ($this->preflightCompleted) {
            return;
        }

        $this->executable('header_binary');
        $this->executable('thumbnail_binary');
        $foreignLoaders = $this->run([$this->executable('vips_binary'), '-l', 'foreign']);

        if (! str_contains($foreignLoaders, 'VipsForeignLoadHeif') || ! str_contains($foreignLoaders, 'VipsForeignSaveHeif')) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSOR_UNAVAILABLE',
                'O processador não possui os codecs HEIC/AVIF necessários.',
                503,
            );
        }

        $this->assertCodecRoundTrip();

        $this->preflightCompleted = true;
    }

    private function assertCodecRoundTrip(): void
    {
        $directory = (string) config(
            'operational-photos.temporary_directory',
            storage_path('app/private/operational-photo-tmp'),
        );
        if ($directory === '' || (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory))) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSOR_UNAVAILABLE',
                'Não foi possível criar a área privada de preflight dos codecs.',
                503,
            );
        }
        @chmod($directory, 0700);

        $suffix = bin2hex(random_bytes(8));
        $heicPath = $directory.DIRECTORY_SEPARATOR.'preflight-'.$suffix.'.heic';
        $avifPath = $directory.DIRECTORY_SEPARATOR.'preflight-'.$suffix.'.avif';

        try {
            $heicBytes = base64_decode(self::PREFLIGHT_HEIC_BASE64, true);
            if (! is_string($heicBytes) || file_put_contents($heicPath, $heicBytes, LOCK_EX) !== strlen($heicBytes)) {
                throw new OperationalPhotoException(
                    'PHOTO_PROCESSOR_UNAVAILABLE',
                    'Não foi possível preparar a prova efetiva dos codecs.',
                    503,
                );
            }
            @chmod($heicPath, 0600);

            $this->run([$this->executable('header_binary'), '-a', $heicPath]);
            $this->thumbnail($heicPath, $avifPath, 16, 60, 'avif');
            $this->run([$this->executable('header_binary'), '-a', $avifPath]);
        } catch (OperationalPhotoException $exception) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSOR_UNAVAILABLE',
                'Os codecs instalados não concluíram a leitura HEIC e a escrita AVIF.',
                503,
                $exception,
            );
        } finally {
            @unlink($heicPath);
            @unlink($avifPath);
        }
    }

    private function executable(string $configKey): string
    {
        $configured = (string) config('operational-photos.'.$configKey);
        $resolved = $configured !== '' ? realpath($configured) : false;

        if ($resolved === false || ! is_file($resolved) || ! is_executable($resolved)) {
            throw new OperationalPhotoException(
                'PHOTO_PROCESSOR_UNAVAILABLE',
                'O processador seguro de fotos está indisponível.',
                503,
            );
        }

        return $resolved;
    }

    /** @param list<string> $arguments */
    private function run(array $arguments, ?float $timeoutSeconds = null): string
    {
        $process = new Process(
            $arguments,
            null,
            $this->isolatedEnvironment(),
        );
        $configuredTimeout = (float) config('operational-photos.timeout_seconds', 12);
        $process->setTimeout($timeoutSeconds === null
            ? $configuredTimeout
            : max(0.1, min($configuredTimeout, $timeoutSeconds)));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            Log::warning('Operational photo processing timed out.', [
                'command' => basename($arguments[0]),
                'timeout_seconds' => config('operational-photos.timeout_seconds', 12),
            ]);

            throw new OperationalPhotoException(
                'PHOTO_PROCESSING_TIMEOUT',
                'A foto excedeu o tempo seguro de processamento.',
                422,
                $exception,
            );
        }

        if (! $process->isSuccessful()) {
            Log::warning('Operational photo processor rejected an image.', [
                'command' => basename($arguments[0]),
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr(trim($process->getErrorOutput()), 0, 500),
            ]);

            throw new OperationalPhotoException(
                'PHOTO_FORMAT_UNSUPPORTED',
                'A foto está corrompida, usa um formato não suportado ou contém múltiplos quadros.',
            );
        }

        return $process->getOutput();
    }

    /**
     * The image decoder handles attacker-controlled bytes. Do not expose
     * application secrets to its process environment.
     *
     * @return array<string, string|false>
     */
    private function isolatedEnvironment(): array
    {
        $inherited = getenv();
        $environment = [];

        if (is_array($inherited)) {
            foreach (array_keys($inherited) as $key) {
                $environment[(string) $key] = false;
            }
        }

        foreach (['PATH', 'LD_LIBRARY_PATH', 'LIBHEIF_PLUGIN_PATH', 'TMPDIR', 'TEMP', 'TMP', 'LANG', 'LC_ALL', 'SYSTEMROOT', 'WINDIR'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $environment[$key] = $value;
            }
        }

        $environment['VIPS_CONCURRENCY'] = (string) config('operational-photos.vips_concurrency', 1);
        $environment['VIPS_DISC_THRESHOLD'] = (string) config('operational-photos.vips_disc_threshold', '64m');

        return $environment;
    }
}
