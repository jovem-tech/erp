<?php

namespace App\Console\Commands\Orders;

use App\Services\Orders\OrderSearchIndexService;
use Illuminate\Console\Command;

/**
 * Reconstroi `os.busca_texto` para o acervo inteiro.
 *
 * Necessario uma vez apos a migration que criou a coluna, e util depois de
 * renomear catalogos (tipo/marca/modelo de equipamento) em massa — o unico caso
 * que os listeners de `saved` nao alcancam, porque a mudanca acontece numa
 * tabela que a OS apenas referencia.
 */
class ReindexOrderSearch extends Command
{
    protected $signature = 'os:reindexar-busca {--chunk=500 : Tamanho do lote}';

    protected $description = 'Reconstroi a coluna de busca (os.busca_texto) de todas as ordens de servico';

    public function handle(OrderSearchIndexService $searchIndex): int
    {
        if (! $searchIndex->indexAvailable()) {
            $this->error('A coluna os.busca_texto nao existe. Rode a migration 2026_08_27_000002 antes.');

            return self::FAILURE;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $startedAt = microtime(true);

        $this->info('Reindexando ordens de servico...');

        $total = $searchIndex->rebuildAll($chunk, function (int $done): void {
            $this->output->write("\r  {$done} OS reindexadas...");
        });

        $this->output->write("\r");
        $this->info(sprintf(
            '%d OS reindexadas em %.1fs.',
            $total,
            microtime(true) - $startedAt
        ));

        return self::SUCCESS;
    }
}
