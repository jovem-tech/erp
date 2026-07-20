<?php

namespace App\Console\Commands\Files;

use App\Enums\Files\FileCategory;
use App\Services\Files\LegacyFileCatalogService;
use Illuminate\Console\Command;

class CatalogLegacyFiles extends Command
{
    protected $signature = 'file-manager:catalog-legacy
        {--apply : Registra metadados e aliases; sem esta flag e dry-run}
        {--root= : Limita a uma root configurada do scanner}
        {--category= : Sobrescreve a categoria inferida para todo o lote}
        {--limit=500 : Limite do lote}
        {--resume= : UUID de uma execucao anterior do mesmo modo}';

    protected $description = 'Cataloga findings legados em lotes sem mover, renomear ou excluir arquivos.';

    public function handle(LegacyFileCatalogService $catalog): int
    {
        $category = null;
        if (is_string($this->option('category')) && $this->option('category') !== '') {
            $category = FileCategory::tryFrom((string) $this->option('category'));
            if (! $category instanceof FileCategory) {
                $this->components->error('Categoria invalida.');

                return self::INVALID;
            }
        }

        try {
            $result = $catalog->catalog(
                (bool) $this->option('apply'),
                (int) $this->option('limit'),
                is_string($this->option('root')) && $this->option('root') !== '' ? (string) $this->option('root') : null,
                $category,
                is_string($this->option('resume')) && $this->option('resume') !== '' ? (string) $this->option('resume') : null
            );
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
