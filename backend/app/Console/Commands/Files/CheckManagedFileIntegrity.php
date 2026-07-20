<?php

namespace App\Console\Commands\Files;

use App\Services\Files\FileIntegrityService;
use Illuminate\Console\Command;

class CheckManagedFileIntegrity extends Command
{
    protected $signature = 'file-manager:check-integrity {--limit=500 : Limite do lote}';

    protected $description = 'Confere existencia, tamanho e SHA-256 dos arquivos centrais em lote.';

    public function handle(FileIntegrityService $integrity): int
    {
        $result = $integrity->checkBatch((int) $this->option('limit'));
        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES));

        return ($result['missing'] + $result['corrupted']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
