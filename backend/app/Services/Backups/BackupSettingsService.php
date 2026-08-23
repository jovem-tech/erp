<?php

namespace App\Services\Backups;

use App\Models\Configuration;
use App\Support\SecretSettings;
use Illuminate\Support\Facades\Schema;

class BackupSettingsService
{
    /**
     * Chave/valor em `configuracoes`, o padrao de toda tela de configuracao
     * deste sistema (ver GoogleIntegrationSettingsService).
     *
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'backup_agendado_habilitado' => '1',
        'backup_horario' => '03:15',
        'backup_retencao_diarios' => '7',
        'backup_retencao_semanais' => '4',
        'backup_retencao_mensais' => '6',
        'backup_retencao_minimo_copias' => '2',
        'backup_incluir_banco_chat' => '1',
        'backup_incluir_legado' => '1',
        'backup_incluir_config' => '1',
        'backup_destino_montagem_habilitado' => '0',
        'backup_destino_montagem_caminho' => '',
        'backup_destino_remoto_habilitado' => '0',
        'backup_destino_remoto_alvo' => '',
        'backup_passphrase_modo' => 'armazenada',
        'backup_passphrase_hash' => '',
        'backup_passphrase_cifrada' => '',
        'backup_passphrase_fingerprint' => '',
    ];

    /** @var array<int, string> */
    private const SECRET_KEYS = [
        'backup_passphrase_hash',
        'backup_passphrase_cifrada',
    ];

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $settings = $this->all();

        return [
            'settings' => SecretSettings::blank($settings, self::SECRET_KEYS),
            'secret_status' => SecretSettings::status($settings, self::SECRET_KEYS),
            'summary' => $this->buildSummary($settings),
        ];
    }

    /** @return array<string, string> */
    public function all(): array
    {
        if (! Schema::hasTable('configuracoes')) {
            return self::DEFAULTS;
        }

        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        return array_merge(self::DEFAULTS, array_map(
            static fn ($value): string => trim((string) $value),
            is_array($stored) ? $stored : []
        ));
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'on', 'yes'], true);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key);

        return $value === '' ? $default : (int) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $current = $this->all();
        $normalized = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $default;
        }

        $normalized = SecretSettings::preserveExisting($normalized, $current, self::SECRET_KEYS);

        foreach ($normalized as $key => $value) {
            $this->put((string) $key, (string) $value);
        }

        return $this->payload();
    }

    public function put(string $key, string $value): void
    {
        Configuration::query()->updateOrInsert(
            ['chave' => $key],
            [
                'valor' => $value,
                'tipo' => 'texto',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /** @return array<string, int> */
    public function retention(): array
    {
        return [
            'diarios' => $this->int('backup_retencao_diarios', 7),
            'semanais' => $this->int('backup_retencao_semanais', 4),
            'mensais' => $this->int('backup_retencao_mensais', 6),
            // Piso duro: mesmo que alguem zere os campos na tela, o sistema
            // nunca fica sem um backup bom.
            'minimo_copias' => max(1, $this->int('backup_retencao_minimo_copias', 2)),
        ];
    }

    /**
     * @param  array<string, string>  $settings
     * @return array<string, mixed>
     */
    private function buildSummary(array $settings): array
    {
        $hasPassphrase = trim((string) ($settings['backup_passphrase_hash'] ?? '')) !== '';

        return [
            'configured' => $hasPassphrase,
            'status' => $hasPassphrase ? 'success' : 'warning',
            'status_label' => $hasPassphrase
                ? 'Frase secreta configurada'
                : 'Defina a frase secreta para habilitar os backups',
            'passphrase_fingerprint' => (string) ($settings['backup_passphrase_fingerprint'] ?? ''),
            'passphrase_modo' => (string) ($settings['backup_passphrase_modo'] ?? 'armazenada'),
        ];
    }
}
