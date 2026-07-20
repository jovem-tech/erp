<?php

namespace App\Services\Files;

use App\Enums\Files\FileCategory;
use Illuminate\Support\Facades\Cache;

class FileManagerMetrics
{
    private const ALLOWED_METRICS = [
        'central_read',
        'legacy_fallback',
        'shadow_catalog_success',
        'shadow_catalog_failure',
    ];

    public function increment(FileCategory $category, string $metric): void
    {
        if (! in_array($metric, self::ALLOWED_METRICS, true)) {
            throw new \InvalidArgumentException('Metrica do gerenciador de arquivos nao autorizada.');
        }

        try {
            $key = $this->key($category, $metric);
            Cache::add($key, 0, now()->addDays(2));
            Cache::increment($key);
        } catch (\Throwable) {
            // Métrica operacional nunca pode interromper upload ou leitura legada.
        }
    }

    /**
     * @return array<string, int>
     */
    public function snapshot(FileCategory $category): array
    {
        $snapshot = [];
        foreach (self::ALLOWED_METRICS as $metric) {
            $snapshot[$metric] = (int) Cache::get($this->key($category, $metric), 0);
        }

        return $snapshot;
    }

    private function key(FileCategory $category, string $metric): string
    {
        return 'file-manager:metrics:'.now()->format('Y-m-d').':'.$category->value.':'.$metric;
    }
}
