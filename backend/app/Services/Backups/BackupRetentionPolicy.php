<?php

namespace App\Services\Backups;

use App\Enums\Backups\BackupStatus;
use App\Models\Backups\Backup;
use Illuminate\Support\Collection;

class BackupRetentionPolicy
{
    public function __construct(private readonly BackupSettingsService $settings) {}

    /**
     * Retencao escalonada: N diarios + N semanais + N mensais.
     *
     * Cobre tanto o erro percebido ontem quanto o problema descoberto meses
     * depois, sem guardar uma copia por dia para sempre.
     *
     * @return array{removidos: int, mantidos: int, bytes_liberados: int}
     */
    public function apply(bool $dryRun = false): array
    {
        $config = $this->settings->retention();

        // Somente o que o painel gerencia. Os dumps do cron de root sao
        // root:root - o painel os LE e restaura, mas apagar e trabalho do
        // proprio cron, e tentar seria falhar em silencio a cada execucao.
        $candidates = Backup::query()
            ->successful()
            ->where('gerenciado', true)
            ->where('protegido', false)
            ->whereNotNull('arquivo_caminho')
            ->orderByDesc('created_at')
            ->get();

        $keep = $this->select($candidates, $config);

        // Piso duro, independente do que a tela diga: o sistema nunca fica sem
        // as ultimas copias boas.
        foreach ($candidates->take(max(1, (int) $config['minimo_copias'])) as $backup) {
            $keep->put($backup->getKey(), $backup);
        }

        $removed = 0;
        $freed = 0;

        foreach ($candidates as $backup) {
            if ($keep->has($backup->getKey())) {
                continue;
            }

            $path = (string) $backup->arquivo_caminho;
            $size = is_file($path) ? (int) filesize($path) : 0;

            if ($dryRun) {
                $removed++;
                $freed += $size;

                continue;
            }

            if (is_file($path)) {
                @unlink($path);
            }

            // A linha e o manifesto ficam para sempre: ocupam quase nada e sao
            // o historico que o painel mostra. So o arquivo some.
            $backup->forceFill([
                'status' => BackupStatus::Expirado->value,
                'etapa_atual' => 'Removido pela retenção',
            ])->save();

            $removed++;
            $freed += $size;
        }

        return [
            'removidos' => $removed,
            'mantidos' => $keep->count(),
            'bytes_liberados' => $freed,
        ];
    }

    /**
     * @param  Collection<int, Backup>  $candidates
     * @param  array<string, int>  $config
     * @return Collection<int, Backup>
     */
    private function select(Collection $candidates, array $config): Collection
    {
        $keep = collect();

        $this->keepNewestPerBucket($candidates, $keep, 'Y-m-d', (int) $config['diarios']);
        $this->keepNewestPerBucket($candidates, $keep, 'o-\WW', (int) $config['semanais']);
        $this->keepNewestPerBucket($candidates, $keep, 'Y-m', (int) $config['mensais']);

        return $keep;
    }

    /**
     * @param  Collection<int, Backup>  $candidates
     * @param  Collection<int, Backup>  $keep
     */
    private function keepNewestPerBucket(Collection $candidates, Collection $keep, string $format, int $limit): void
    {
        if ($limit <= 0) {
            return;
        }

        $seen = [];

        foreach ($candidates as $backup) {
            $bucket = $backup->created_at?->format($format);

            if ($bucket === null || isset($seen[$bucket])) {
                continue;
            }

            $seen[$bucket] = true;
            $keep->put($backup->getKey(), $backup);

            if (count($seen) >= $limit) {
                return;
            }
        }
    }
}
