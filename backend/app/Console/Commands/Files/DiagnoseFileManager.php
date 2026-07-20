<?php

namespace App\Console\Commands\Files;

use App\Services\Files\FileManagerConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class DiagnoseFileManager extends Command
{
    protected $signature = 'file-manager:diagnose {--json : Retorna o diagnostico como JSON}';

    protected $description = 'Valida configuracao, tabelas e acesso aos discos do gerenciador de arquivos.';

    public function handle(FileManagerConfiguration $configuration): int
    {
        $errors = $configuration->validate();
        $warnings = [];
        $checks = [];
        $requiredTables = [
            'managed_files',
            'managed_file_links',
            'managed_file_legacy_aliases',
            'managed_file_events',
            'file_scan_runs',
            'file_scan_findings',
        ];

        foreach ($requiredTables as $table) {
            $checks['table:'.$table] = Schema::hasTable($table);
            if (! $checks['table:'.$table]) {
                $errors[] = 'missing_table:'.$table;
            }
        }

        try {
            $disk = (string) config('file-manager.storage.disk', '');
            $diskPath = $disk !== '' ? Storage::disk($disk)->path('') : '';
            $checks['storage_disk_configured'] = $diskPath !== '';
            $checks['storage_disk_readable'] = $diskPath !== '' && is_readable($diskPath);
            $checks['storage_disk_writable'] = $diskPath !== '' && is_writable($diskPath);
            if (! $checks['storage_disk_readable'] || ! $checks['storage_disk_writable']) {
                if ($configuration->mode()->allowsCentralWrite()) {
                    $errors[] = 'storage_disk_permissions_invalid';
                } else {
                    $warnings[] = 'storage_disk_permissions_limited_for_cli_user';
                }
            }
        } catch (\Throwable) {
            $checks['storage_disk_configured'] = false;
            $errors[] = 'storage_disk_unavailable';
        }

        $checks['database_connection'] = $this->checkDatabaseConnection();
        if (! $checks['database_connection']) {
            $errors[] = 'database_unavailable';
        }

        $checks['queue_connection'] = $this->checkQueueConnection();
        if (! $checks['queue_connection']) {
            $errors[] = 'queue_unavailable';
        }

        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('managed_files')) {
            $plans = DB::select(
                'EXPLAIN SELECT id, uuid, category, lifecycle_status, security_status, created_at
                FROM managed_files
                WHERE category = ?
                ORDER BY created_at DESC
                LIMIT 50',
                ['company_logo']
            );
            $key = (string) ($plans[0]->key ?? '');
            $checks['catalog_query_uses_index'] = $key === 'ix_mf_category_created';
            if (! $checks['catalog_query_uses_index']) {
                $errors[] = 'catalog_query_without_expected_index';
            }
        }

        $payload = [
            'ok' => $errors === [],
            'mode' => (string) config('file-manager.mode', 'off'),
            'checks' => $checks,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->info($payload['ok'] ? 'Gerenciador de arquivos pronto.' : 'Diagnostico encontrou bloqueios.');
            foreach ($payload['errors'] as $error) {
                $this->components->error($error);
            }
            foreach ($payload['warnings'] as $warning) {
                $this->components->warn($warning);
            }
        }

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function checkDatabaseConnection(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function checkQueueConnection(): bool
    {
        $connection = (string) config('queue.default', '');
        $driver = (string) config("queue.connections.{$connection}.driver", '');

        try {
            return match ($driver) {
                'sync', 'null' => true,
                'database' => Schema::hasTable((string) config("queue.connections.{$connection}.table", 'jobs')),
                'redis' => Redis::connection(
                    (string) config("queue.connections.{$connection}.connection", 'default')
                )->ping() !== false,
                default => $driver !== '',
            };
        } catch (\Throwable) {
            return false;
        }
    }
}
