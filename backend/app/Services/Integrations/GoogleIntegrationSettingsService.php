<?php

namespace App\Services\Integrations;

use App\Models\Configuration;
use App\Support\SecretSettings;

class GoogleIntegrationSettingsService
{
    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'portal_google_client_id' => '',
        'portal_google_client_secret' => '',
    ];

    /**
     * @var array<int, string>
     */
    private const SECRET_KEYS = [
        'portal_google_client_secret',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $settings = $this->loadSettings();
        $maskedSettings = SecretSettings::blank($settings, self::SECRET_KEYS);

        return [
            'settings' => $maskedSettings,
            'secret_status' => SecretSettings::status($settings, self::SECRET_KEYS),
            'summary' => $this->buildSummary($settings),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $current = $this->loadSettings();
        $normalized = $this->normalizePayload($payload, $current);

        foreach ($normalized as $key => $value) {
            $this->upsert((string) $key, (string) $value);
        }

        return $this->payload();
    }

    /**
     * @return array<string, string>
     */
    private function loadSettings(): array
    {
        $stored = Configuration::query()
            ->whereIn('chave', array_keys(self::DEFAULTS))
            ->pluck('valor', 'chave')
            ->all();

        return array_merge(self::DEFAULTS, array_map(
            static fn ($value): string => trim((string) $value),
            is_array($stored) ? $stored : []
        ));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $current
     * @return array<string, string>
     */
    private function normalizePayload(array $payload, array $current): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $defaultValue;
        }

        return SecretSettings::preserveExisting($normalized, $current, self::SECRET_KEYS);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildSummary(array $settings): array
    {
        $configured = trim((string) ($settings['portal_google_client_id'] ?? '')) !== ''
            && trim((string) ($settings['portal_google_client_secret'] ?? '')) !== '';

        return [
            'configured' => $configured,
            'status' => $configured ? 'success' : 'secondary',
            'status_label' => $configured ? 'Configurado' : 'Aguardando configuração',
        ];
    }

    private function upsert(string $key, string $value): void
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
}
