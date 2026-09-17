<?php

namespace App\Console\Commands\Documents;

use App\Services\Orders\Documents\PdfRenderCache;
use Illuminate\Console\Command;

/**
 * Retenção do cache de PDFs renderizados sob demanda: apaga entradas
 * paradas há mais de `ttl_days` (mtime é tocado a cada hit) e, se ainda
 * passar de `max_bytes`, descarta as menos recentes até caber. É cache:
 * o pior caso é um re-render.
 */
class PurgeRenderCache extends Command
{
    protected $signature = 'documents:purge-render-cache
        {--dry-run : Só relata o que seria apagado}
        {--ttl-days= : Sobrescreve document-rendering.render_cache.ttl_days}
        {--max-bytes= : Sobrescreve document-rendering.render_cache.max_bytes}';

    protected $description = 'Aplica TTL e limite de tamanho ao cache de PDFs renderizados sob demanda.';

    public function handle(PdfRenderCache $cache): int
    {
        $disk = $cache->disk();
        $root = rtrim((string) $disk->path(''), '/');
        if (! is_dir($root)) {
            $this->info('Cache de render vazio.');

            return self::SUCCESS;
        }

        $ttlDays = max(0, (int) ($this->option('ttl-days') ?: config('document-rendering.render_cache.ttl_days', 7)));
        $maxBytes = max(0, (int) ($this->option('max-bytes') ?: config('document-rendering.render_cache.max_bytes', 512 * 1024 * 1024)));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = time() - ($ttlDays * 86400);

        $entries = [];
        foreach ($disk->allFiles() as $relative) {
            if (! preg_match('/\.(pdf|png)$/i', $relative)) {
                continue;
            }
            $absolute = $disk->path($relative);
            $stat = @stat($absolute);
            if (! is_array($stat)) {
                continue;
            }
            $entries[] = ['path' => $relative, 'mtime' => (int) $stat['mtime'], 'size' => (int) $stat['size']];
        }

        $removed = 0;
        $freed = 0;
        $remaining = [];
        foreach ($entries as $entry) {
            if ($ttlDays > 0 && $entry['mtime'] < $cutoff) {
                $removed++;
                $freed += $entry['size'];
                if (! $dryRun) {
                    $disk->delete($entry['path']);
                }

                continue;
            }
            $remaining[] = $entry;
        }

        $total = array_sum(array_column($remaining, 'size'));
        if ($maxBytes > 0 && $total > $maxBytes) {
            usort($remaining, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);
            foreach ($remaining as $entry) {
                if ($total <= $maxBytes) {
                    break;
                }
                $removed++;
                $freed += $entry['size'];
                $total -= $entry['size'];
                if (! $dryRun) {
                    $disk->delete($entry['path']);
                }
            }
        }

        $this->info(sprintf(
            '%s%d entrada(s) removida(s), %s liberados; %s permanecem no cache.',
            $dryRun ? '[dry-run] ' : '',
            $removed,
            $this->humanBytes($freed),
            $this->humanBytes($total)
        ));

        return self::SUCCESS;
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $bytes, $unit);
            }
            $bytes /= 1024;
        }

        return (string) $bytes;
    }
}
