<?php

namespace App\Console\Commands;

use App\Exceptions\OperationalPhotoException;
use App\Services\Photos\VipsCommandRunner;
use Illuminate\Console\Command;

final class OperationalPhotoPreflightCommand extends Command
{
    protected $signature = 'photos:preflight';

    protected $description = 'Valida executáveis e codecs HEIC/AVIF usados nas fotos operacionais';

    public function handle(VipsCommandRunner $runner): int
    {
        try {
            $runner->preflight();
        } catch (OperationalPhotoException $exception) {
            $this->error($exception->errorCode.': '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('PHOTO_PROCESSOR_OK: libvips com leitura HEIC/HEIF e escrita AVIF disponível.');

        return self::SUCCESS;
    }
}
