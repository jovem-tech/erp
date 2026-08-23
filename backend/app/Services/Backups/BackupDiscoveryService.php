<?php

namespace App\Services\Backups;

use App\Enums\Backups\BackupContent;
use App\Enums\Backups\BackupOrigin;
use App\Enums\Backups\BackupStatus;
use App\Enums\Backups\BackupType;
use App\Models\Backups\Backup;
use App\Services\Backups\Contracts\ProcessRunner;
use Illuminate\Support\Str;
use Throwable;

class BackupDiscoveryService
{
    public function __construct(private readonly ProcessRunner $runner) {}

    /**
     * Varre o disco e cataloga TODO backup encontrado, inclusive os que este
     * sistema nao gerou.
     *
     * Requisito explicito: o painel e o catalogo unico. Os dumps do cron de
     * root das 02:00 sao lidos e restauraveis pelo painel, mas marcados como
     * nao gerenciados - ele nao pode apaga-los, e a interface diz isso.
     *
     * Mesmo padrao do gerenciador de arquivos com os arquivos legados
     * (file-manager:sync / ScanManagedFiles).
     *
     * @return array{catalogados: int, atualizados: int, ausentes: int, ignorados: int}
     */
    public function scan(): array
    {
        if (! (bool) config('backup.discovery.enabled', true)) {
            return ['catalogados' => 0, 'atualizados' => 0, 'ausentes' => 0, 'ignorados' => 0];
        }

        $patterns = (array) config('backup.discovery.patterns', []);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $seen = [];

        foreach ((array) config('backup.discovery.roots', []) as $root) {
            $path = rtrim((string) ($root['path'] ?? ''), '/');

            if ($path === '' || ! is_dir($path) || ! is_readable($path)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                foreach ((array) glob($path.'/'.$pattern) as $file) {
                    $file = (string) $file;

                    if (! is_file($file) || ! is_readable($file)) {
                        $skipped++;

                        continue;
                    }

                    $seen[] = $file;
                    $outcome = $this->catalog($file, (array) $root);

                    if ($outcome === 'created') {
                        $created++;
                    } elseif ($outcome === 'updated') {
                        $updated++;
                    }
                }
            }
        }

        return [
            'catalogados' => $created,
            'atualizados' => $updated,
            'ausentes' => $this->markMissing($seen),
            'ignorados' => $skipped,
        ];
    }

    /** @param array<string, mixed> $root */
    private function catalog(string $file, array $root): string
    {
        $existing = Backup::query()->where('arquivo_caminho', $file)->first();

        $size = (int) filesize($file);
        $mtime = (int) filemtime($file);

        if ($existing !== null) {
            $unchanged = (int) $existing->tamanho_bytes === $size
                && $existing->arquivo_modificado_em?->getTimestamp() === $mtime
                && $existing->status !== BackupStatus::Ausente;

            if ($unchanged) {
                return 'unchanged';
            }

            $existing->forceFill([
                'tamanho_bytes' => $size,
                'arquivo_modificado_em' => now()->setTimestamp($mtime),
                'status' => BackupStatus::Concluido->value,
            ])->save();

            return 'updated';
        }

        $origin = $this->detectOrigin($file, $root);

        Backup::query()->create([
            'uuid' => (string) Str::uuid(),
            'tipo' => BackupType::Descoberto->value,
            'origem' => $origin->value,
            'conteudo' => $this->detectContent($file)->value,
            // Arquivo de root nao pode ser apagado pelo www-data: marcar como
            // nao gerenciado evita que a retencao tente e falhe toda noite.
            'gerenciado' => (bool) ($root['managed'] ?? false) && is_writable(dirname($file)),
            'status' => BackupStatus::Concluido->value,
            'etapa_atual' => 'Catalogado do disco',
            'progresso_percentual' => 100,
            'arquivo_nome' => basename($file),
            'arquivo_caminho' => $file,
            'tamanho_bytes' => $size,
            // sha256 fica nulo de proposito: calcular 440 MB a cada varredura
            // (de 15 em 15 minutos) seria desperdicio puro. E preenchido na
            // primeira verificacao sob demanda.
            'sha256' => null,
            'arquivo_modificado_em' => now()->setTimestamp($mtime),
            'iniciado_em' => now()->setTimestamp($mtime),
            'concluido_em' => now()->setTimestamp($mtime),
            'created_at' => now()->setTimestamp($mtime),
            'updated_at' => now(),
        ]);

        return 'created';
    }

    /** @param array<string, mixed> $root */
    private function detectOrigin(string $file, array $root): BackupOrigin
    {
        $name = basename($file);

        if (str_starts_with($name, 'pre-deploy-')) {
            return BackupOrigin::PreDeploy;
        }

        $configured = (string) ($root['origin'] ?? BackupOrigin::Manual->value);

        return BackupOrigin::tryFrom($configured) ?? BackupOrigin::Manual;
    }

    private function detectContent(string $file): BackupContent
    {
        if (str_ends_with($file, '.sql.gz')) {
            return BackupContent::SomenteBanco;
        }

        try {
            // Um pacote deste sistema traz manifest.json em texto claro no
            // envelope: e o que distingue "completo" de um tar qualquer.
            $result = $this->runner->run(['tar', '-tf', $file]);

            if ($result->successful() && str_contains($result->output, 'manifest.json')) {
                return BackupContent::Completo;
            }
        } catch (Throwable) {
            // Sem leitura possivel, trata como o caso conservador.
        }

        return BackupContent::SomenteBanco;
    }

    /** @param array<int, string> $seen */
    private function markMissing(array $seen): int
    {
        $missing = 0;

        $rows = Backup::query()
            ->whereNotNull('arquivo_caminho')
            ->whereNotIn('status', [
                BackupStatus::Pendente->value,
                BackupStatus::Executando->value,
                BackupStatus::Expirado->value,
                BackupStatus::Ausente->value,
            ])
            ->get();

        foreach ($rows as $row) {
            $path = (string) $row->arquivo_caminho;

            if (in_array($path, $seen, true) || is_file($path)) {
                continue;
            }

            // Nao apaga a linha: o historico e o que o painel mostra, e saber
            // que um backup existiu e sumiu vale mais que a linha sumir junto.
            $row->forceFill([
                'status' => BackupStatus::Ausente->value,
                'etapa_atual' => 'Arquivo não encontrado no disco',
            ])->save();

            $missing++;
        }

        return $missing;
    }
}
