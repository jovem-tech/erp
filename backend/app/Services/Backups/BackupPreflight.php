<?php

namespace App\Services\Backups;

use App\Models\Backups\Backup;
use App\Services\Backups\Contracts\ProcessRunner;

class BackupPreflight
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly BackupPassphraseResolver $passphrase,
        private readonly BackupRootRegistry $roots,
    ) {}

    /**
     * Falhas duras impedem o backup; avisos apenas o marcam.
     *
     * A distincao importa: um HD externo desconectado NAO pode impedir a copia
     * local de acontecer, mas a falta de espaco em disco tem que impedir - um
     * pacote pela metade e pior que nenhum, porque parece um backup.
     *
     * @return array{ok: bool, erros: array<int, string>, avisos: array<int, string>}
     */
    public function check(): array
    {
        $errors = [];
        $warnings = [];

        foreach ((array) config('backup.preflight.required_binaries', []) as $binary) {
            $result = $this->runner->run(['bash', '-c', 'command -v '.escapeshellarg((string) $binary)]);

            if (! $result->successful()) {
                $errors[] = sprintf('Programa obrigatório ausente no servidor: %s.', (string) $binary);
            }
        }

        $store = (string) config('backup.store.path');

        if (! is_dir($store)) {
            $errors[] = sprintf(
                'O diretório de backups não existe: %s. Crie-o uma única vez com: '
                .'sudo install -d -o www-data -g www-data -m 0700 %s',
                $store,
                $store,
            );
        } elseif (! is_writable($store)) {
            $errors[] = sprintf(
                'Sem permissão de escrita em %s. O backup roda como "%s"; ajuste o dono com: '
                .'sudo chown -R www-data:www-data %s',
                $store,
                $this->currentUser(),
                $store,
            );
        } else {
            $free = @disk_free_space($store);
            $needed = $this->estimatedNeed();

            if (is_float($free) && $free < $needed) {
                $errors[] = sprintf(
                    'Espaço insuficiente em %s: %s livres, são necessários ~%s.',
                    $store,
                    $this->humanBytes((int) $free),
                    $this->humanBytes($needed),
                );
            }
        }

        if (! $this->passphrase->isConfigured()) {
            $errors[] = 'Nenhuma frase secreta configurada. Defina uma em Configurações → Backup.';
        }

        foreach ($this->roots->all() as $root) {
            if ($root['available']) {
                continue;
            }

            $message = sprintf('Raiz "%s" indisponível (%s).', $root['label'], (string) $root['reason']);

            if ($root['optional']) {
                $warnings[] = $message;
            } else {
                $errors[] = $message;
            }
        }

        return ['ok' => $errors === [], 'erros' => $errors, 'avisos' => $warnings];
    }

    /**
     * Espaco necessario estimado: 3x o ultimo pacote bem-sucedido (o pacote em
     * si + a area de trabalho + folga), com piso configuravel para a primeira
     * execucao, quando ainda nao ha historico.
     */
    private function estimatedNeed(): int
    {
        $floor = (int) config('backup.preflight.free_space_floor_bytes', 500 * 1024 * 1024);
        $multiplier = (float) config('backup.preflight.free_space_multiplier', 3.0);

        $last = Backup::query()
            ->successful()
            ->where('tamanho_bytes', '>', 0)
            ->orderByDesc('id')
            ->value('tamanho_bytes');

        if (! is_numeric($last) || (int) $last <= 0) {
            return $floor;
        }

        return max($floor, (int) ((int) $last * $multiplier));
    }

    private function currentUser(): string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $info = posix_getpwuid(posix_geteuid());

            if (is_array($info) && isset($info['name'])) {
                return (string) $info['name'];
            }
        }

        return get_current_user();
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return sprintf('%.1f %s', $value, $units[$index]);
    }
}
