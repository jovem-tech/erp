<?php

namespace App\Console\Commands\Documents;

use App\Models\Files\ManagedFile;
use App\Models\OrderDocument;
use App\Models\OrderDocumentFile;
use App\Services\Files\ManagedFilePurgeService;
use App\Services\Orders\Documents\DocumentPersistencePolicy;
use App\Services\Pdf\PdfTemplateRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Expurga os PDFs do acervo documental que passaram a ser renderizados sob
 * demanda. Fonte de verdade: os_documentos/os_documento_arquivos — nunca
 * varre diretório para apagar, porque dentro de os_documentos/{os}/ há
 * fotos e a pasta fiscal/. Dry-run por padrão; --execute apaga.
 *
 * Nunca toca: qualquer caminho fiscal (guarda legal), assinatura formal
 * (prova), managed_files com legal_hold, e documento sem caminho de
 * re-render (tipo fora do registry, orçamento sem orçamento vinculado).
 */
class PurgeLegacyDocumentBinaries extends Command
{
    protected $signature = 'documents:purge-legacy-binaries
        {--execute : Apaga de fato (sem esta flag é dry-run)}
        {--before= : Só documentos criados antes de YYYY-MM-DD}
        {--order= : Restringe a uma OS (id)}
        {--budgets : Inclui os PDFs de orçamento em private/orcamentos}
        {--zips : Inclui ZIPs residuais em os_documentos/*/zip e temporários antigos}
        {--limit=500 : Máximo de documentos avaliados nesta execução}';

    protected $description = 'Remove PDFs antigos do acervo da OS que já são renderizados sob demanda (snapshot ou dados atuais).';

    /** @var array<string, int> */
    private array $skipped = [];

    private int $deletedFiles = 0;

    private int $freedBytes = 0;

    public function handle(
        PdfTemplateRegistry $registry,
        DocumentPersistencePolicy $policy,
        ManagedFilePurgeService $purger
    ): int {
        // O container reaproveita a instância entre chamadas (Artisan::call
        // em sequência): zera os contadores a cada execução.
        $this->skipped = [];
        $this->deletedFiles = 0;
        $this->freedBytes = 0;

        $execute = (bool) $this->option('execute');
        $before = $this->parseBefore();
        $orderId = (int) ($this->option('order') ?: 0);
        $limit = max(1, min(5000, (int) ($this->option('limit') ?: 500)));

        if (! $execute) {
            $this->warn('Dry-run: nada será apagado. Use --execute para aplicar.');
        }

        $query = OrderDocument::query()->with(['files', 'snapshot'])->orderBy('id');
        if ($before instanceof Carbon) {
            $query->where('created_at', '<', $before);
        }
        if ($orderId > 0) {
            $query->where('os_id', $orderId);
        }

        $evaluated = 0;
        $purgedDocuments = 0;
        $liveFallback = (bool) config('document-rendering.live_fallback', true);

        $query->limit($limit)->get()->each(function (OrderDocument $document) use (
            $registry, $policy, $purger, $execute, $liveFallback, &$evaluated, &$purgedDocuments
        ): void {
            $evaluated++;

            $paths = $this->existingPaths($document);
            if ($paths === []) {
                $this->skip('sem_binario');

                return;
            }

            foreach ($paths as $path) {
                if (str_contains($path, '/fiscal/') || str_starts_with($path, 'private/fiscal')) {
                    $this->skip('fiscal_guarda_legal');

                    return;
                }
            }

            $method = strtolower(trim((string) ($document->metodo_assinatura ?? '')));
            if ($method === 'cliente_link' || in_array($method, $policy->persistedSignatureMethods(), true)) {
                $this->skip('assinatura_formal');

                return;
            }

            $managedFiles = $this->managedFilesFor($document, $paths);
            foreach ($managedFiles as $managed) {
                if ((bool) data_get($managed->metadata_json, 'legal_hold', false)) {
                    $this->skip('legal_hold');

                    return;
                }
            }

            $hasSnapshot = $document->relationLoaded('snapshot') && $document->getRelation('snapshot') !== null;
            if (! $hasSnapshot) {
                if (! $liveFallback) {
                    $this->skip('sem_snapshot_e_fallback_desligado');

                    return;
                }
                $type = (string) $document->tipo_documento;
                if ($registry->codeForLegacyType($type) === null) {
                    $this->skip('tipo_fora_do_registry');

                    return;
                }
                if ($type === 'orcamento' && ! $this->orderHasBudget($document)) {
                    $this->skip('orcamento_sem_vinculo');

                    return;
                }
            }

            $bytes = 0;
            foreach ($paths as $path) {
                $bytes += (int) Storage::disk('local')->size($path);
            }

            $this->line(sprintf(
                '%s OS %d doc #%d %s v%d — %d arquivo(s), %s (%s)',
                $execute ? 'APAGA ' : 'apagaria',
                (int) $document->os_id,
                (int) $document->id,
                (string) $document->tipo_documento,
                (int) $document->versao,
                count($paths),
                $this->humanBytes($bytes),
                $hasSnapshot ? 'snapshot' : 'dados atuais'
            ));

            if (! $execute) {
                $this->deletedFiles += count($paths);
                $this->freedBytes += $bytes;
                $purgedDocuments++;

                return;
            }

            try {
                foreach ($paths as $path) {
                    Storage::disk('local')->delete($path);
                }
                foreach ($managedFiles as $managed) {
                    try {
                        $purger->retireReplacedBinary($managed, null, 'PDF passou a ser renderizado sob demanda (documents:purge-legacy-binaries).');
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];
                $document->forceFill([
                    'metadados_json' => array_merge($metadata, [
                        'armazenamento' => 'snapshot',
                        'binario_expurgado_em' => now()->toIso8601String(),
                        'origem_reconstituicao' => $hasSnapshot ? 'snapshot' : 'dados_atuais',
                    ]),
                ])->save();

                $this->deletedFiles += count($paths);
                $this->freedBytes += $bytes;
                $purgedDocuments++;
            } catch (Throwable $exception) {
                report($exception);
                $this->skip('erro');
                $this->error(sprintf('Falha ao expurgar doc #%d: %s', (int) $document->id, $exception->getMessage()));
            }
        });

        if ((bool) $this->option('budgets')) {
            $this->purgeBudgetPdfs($execute, $before, $purger);
        }

        if ((bool) $this->option('zips')) {
            $this->purgeResidualZips($execute);
        }

        $this->newLine();
        $this->info(sprintf(
            '%sDocumentos avaliados: %d | expurgados: %d | arquivos: %d | liberado: %s',
            $execute ? '' : '[dry-run] ',
            $evaluated,
            $purgedDocuments,
            $this->deletedFiles,
            $this->humanBytes($this->freedBytes)
        ));
        foreach ($this->skipped as $reason => $count) {
            $this->line(sprintf('  mantidos por %s: %d', $reason, $count));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function existingPaths(OrderDocument $document): array
    {
        $candidates = [trim((string) ($document->arquivo ?? ''))];
        if ($document->relationLoaded('files')) {
            foreach ($document->files as $file) {
                $candidates[] = trim((string) ($file->arquivo ?? ''));
            }
        }

        $paths = [];
        foreach (array_unique(array_filter($candidates)) as $candidate) {
            $normalized = ltrim(str_replace('\\', '/', $candidate), '/');
            if ($normalized === '' || str_contains($normalized, '..')) {
                continue;
            }
            if (! str_starts_with($normalized, 'private/os_documentos/') && ! str_starts_with($normalized, 'private/orcamentos/')) {
                // Fora do acervo (legado em outra raiz): não é deste comando.
                continue;
            }
            if (Storage::disk('local')->exists($normalized)) {
                $paths[] = $normalized;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, string>  $paths
     * @return array<int, ManagedFile>
     */
    private function managedFilesFor(OrderDocument $document, array $paths): array
    {
        if (! Schema::hasTable('managed_files')) {
            return [];
        }

        $uuids = [];
        if ($document->relationLoaded('files') && Schema::hasColumn('os_documento_arquivos', 'managed_file_uuid')) {
            foreach ($document->files as $file) {
                $uuid = trim((string) ($file->managed_file_uuid ?? ''));
                if ($uuid !== '') {
                    $uuids[] = $uuid;
                }
            }
        }

        $query = ManagedFile::query()->where(function ($q) use ($uuids, $paths): void {
            if ($uuids !== []) {
                $q->whereIn('uuid', $uuids);
            }
            $q->orWhere(function ($inner) use ($paths): void {
                $inner->where('storage_disk', 'local')->whereIn('storage_key', $paths);
            });
        });

        return $query->get()->all();
    }

    private function orderHasBudget(OrderDocument $document): bool
    {
        $metadata = is_array($document->metadados_json) ? $document->metadados_json : [];
        if ((int) ($metadata['budget_id'] ?? 0) > 0) {
            return true;
        }

        return Schema::hasTable('orcamentos')
            && DB::table('orcamentos')->where('os_id', (int) $document->os_id)->exists();
    }

    private function purgeBudgetPdfs(bool $execute, ?Carbon $before, ManagedFilePurgeService $purger): void
    {
        $disk = Storage::disk('local');
        $count = 0;
        $bytes = 0;
        foreach ($disk->allFiles('private/orcamentos') as $path) {
            if (! str_ends_with(strtolower($path), '.pdf')) {
                continue;
            }
            if ($before instanceof Carbon && $disk->lastModified($path) >= $before->getTimestamp()) {
                continue;
            }

            $size = (int) $disk->size($path);
            $count++;
            $bytes += $size;
            $this->line(sprintf('%s orçamento %s (%s)', $execute ? 'APAGA ' : 'apagaria', $path, $this->humanBytes($size)));

            if (! $execute) {
                continue;
            }

            $disk->delete($path);
            if (Schema::hasTable('managed_files')) {
                ManagedFile::query()->where('storage_disk', 'local')->where('storage_key', $path)->get()
                    ->each(function (ManagedFile $managed) use ($purger): void {
                        try {
                            $purger->retireReplacedBinary($managed, null, 'PDF de orçamento passou a ser renderizado sob demanda.');
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });
            }
        }

        $this->deletedFiles += $count;
        $this->freedBytes += $bytes;
        $this->info(sprintf('%sPDFs de orçamento: %d arquivo(s), %s', $execute ? '' : '[dry-run] ', $count, $this->humanBytes($bytes)));
    }

    private function purgeResidualZips(bool $execute): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->getTimestamp();
        $count = 0;
        $bytes = 0;

        foreach ($disk->directories('private/os_documentos') as $orderDirectory) {
            $zipDirectory = $orderDirectory.'/zip';
            if (! $disk->directoryExists($zipDirectory)) {
                continue;
            }
            foreach ($disk->files($zipDirectory) as $path) {
                if (! str_ends_with(strtolower($path), '.zip') || $disk->lastModified($path) > $cutoff) {
                    continue;
                }
                $size = (int) $disk->size($path);
                $count++;
                $bytes += $size;
                $this->line(sprintf('%s zip %s (%s)', $execute ? 'APAGA ' : 'apagaria', $path, $this->humanBytes($size)));
                if ($execute) {
                    $disk->delete($path);
                }
            }
            if ($execute && $disk->files($zipDirectory) === []) {
                $disk->deleteDirectory($zipDirectory);
            }
        }

        foreach ([storage_path('app/tmp/os_closure'), (string) config('document-rendering.temp_directory', storage_path('framework/cache/pdf-tmp'))] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            foreach (glob($directory.'/*') ?: [] as $absolute) {
                if (! is_file($absolute) || filemtime($absolute) > $cutoff) {
                    continue;
                }
                $size = (int) filesize($absolute);
                $count++;
                $bytes += $size;
                $this->line(sprintf('%s temporário %s (%s)', $execute ? 'APAGA ' : 'apagaria', basename($absolute), $this->humanBytes($size)));
                if ($execute) {
                    @unlink($absolute);
                }
            }
        }

        $this->deletedFiles += $count;
        $this->freedBytes += $bytes;
        $this->info(sprintf('%sZIPs/temporários residuais: %d arquivo(s), %s', $execute ? '' : '[dry-run] ', $count, $this->humanBytes($bytes)));
    }

    private function parseBefore(): ?Carbon
    {
        $raw = trim((string) ($this->option('before') ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->startOfDay();
        } catch (Throwable) {
            $this->error('Valor inválido em --before (use YYYY-MM-DD).');
            exit(self::INVALID);
        }
    }

    private function skip(string $reason): void
    {
        $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
    }

    private function humanBytes(int $bytes): string
    {
        $value = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($value < 1024 || $unit === 'GB') {
                return sprintf('%.1f %s', $value, $unit);
            }
            $value /= 1024;
        }

        return (string) $bytes;
    }
}
