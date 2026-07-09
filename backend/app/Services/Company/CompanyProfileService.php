<?php

namespace App\Services\Company;

use App\Models\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CompanyProfileService
{
    private const LOGO_CONFIG_KEY = 'empresa_logo';

    private const LOGO_DIRECTORY = 'private/empresa';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg'];

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'sistema_nome' => '',
        'empresa_razao_social' => '',
        'empresa_nome_fantasia' => '',
        'empresa_cnpj' => '',
        'empresa_inscricao_estadual' => '',
        'empresa_telefone' => '',
        'empresa_email' => '',
        'empresa_endereco' => '',
    ];

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'settings' => $this->loadSettings(),
            'logo' => $this->logoMeta(),
        ];
    }

    /**
     * Dados mínimos e não sensíveis para telas públicas, como o login.
     *
     * @return array<string, mixed>
     */
    public function publicBranding(): array
    {
        $settings = $this->loadSettings();

        $systemName = trim((string) ($settings['sistema_nome'] ?? ''));
        if ($systemName === '') {
            $systemName = trim((string) ($settings['empresa_nome_fantasia'] ?? ''));
        }
        if ($systemName === '') {
            $systemName = trim((string) ($settings['empresa_razao_social'] ?? ''));
        }

        return [
            'sistema_nome' => $systemName !== '' ? $systemName : 'Sistema ERP',
            'logo' => $this->logoMeta(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $normalized = $this->normalizePayload($payload);

        foreach ($normalized as $key => $value) {
            $this->upsert((string) $key, (string) $value);
        }

        return $this->payload();
    }

    public function storeLogo(UploadedFile $file): void
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        if (! in_array($extension, self::ALLOWED_LOGO_EXTENSIONS, true)) {
            return;
        }

        $this->deleteStoredLogo();

        $filename = 'logo_' . now()->format('YmdHisv') . '.' . $extension;
        Storage::disk('local')->putFileAs(self::LOGO_DIRECTORY, $file, $filename);

        $this->upsert(self::LOGO_CONFIG_KEY, self::LOGO_DIRECTORY . '/' . $filename);
    }

    public function removeLogo(): void
    {
        $this->deleteStoredLogo();
        $this->upsert(self::LOGO_CONFIG_KEY, '');
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    public function resolveLogoFile(): ?array
    {
        $relativePath = trim((string) $this->configValue(self::LOGO_CONFIG_KEY));
        if ($relativePath === '' || ! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        return [
            'absolute_path' => Storage::disk('local')->path($relativePath),
            'mime_type' => Storage::disk('local')->mimeType($relativePath) ?: 'application/octet-stream',
            'filename' => basename($relativePath),
        ];
    }

    private function deleteStoredLogo(): void
    {
        $relativePath = trim((string) $this->configValue(self::LOGO_CONFIG_KEY));
        if ($relativePath !== '' && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function logoMeta(): array
    {
        return [
            'exists' => $this->resolveLogoFile() !== null,
        ];
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

    private function configValue(string $key): string
    {
        $value = Configuration::query()->where('chave', $key)->value('valor');

        return trim((string) $value);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function normalizePayload(array $payload): array
    {
        $normalized = [];

        foreach (self::DEFAULTS as $key => $defaultValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];

            if ($key === 'empresa_email') {
                $normalized[$key] = strtolower(trim((string) $value));
                continue;
            }

            $normalized[$key] = is_scalar($value) ? trim((string) $value) : (string) $defaultValue;
        }

        return $normalized;
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
