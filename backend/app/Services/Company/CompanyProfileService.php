<?php

namespace App\Services\Company;

use App\Models\Configuration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CompanyProfileService
{
    private const LOGO_CONFIG_KEY = 'empresa_logo';

    private const LOGIN_BACKGROUND_CONFIG_KEY = 'login_background_image';

    private const LOGO_DIRECTORY = 'private/empresa';

    private const LOGIN_BACKGROUND_DIRECTORY = 'private/empresa/login';

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOGO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'svg'];

    /**
     * @var array<int, string>
     */
    private const ALLOWED_LOGIN_BACKGROUND_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

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
            'login_background' => $this->loginBackgroundMeta(),
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
            'login_background' => $this->loginBackgroundMeta(),
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
        $this->storeImage(
            file: $file,
            configKey: self::LOGO_CONFIG_KEY,
            directory: self::LOGO_DIRECTORY,
            filenamePrefix: 'logo',
            allowedExtensions: self::ALLOWED_LOGO_EXTENSIONS
        );
    }

    public function removeLogo(): void
    {
        $this->deleteStoredImage(self::LOGO_CONFIG_KEY, self::LOGO_DIRECTORY);
        $this->upsert(self::LOGO_CONFIG_KEY, '');
    }

    public function storeLoginBackground(UploadedFile $file): void
    {
        $this->storeImage(
            file: $file,
            configKey: self::LOGIN_BACKGROUND_CONFIG_KEY,
            directory: self::LOGIN_BACKGROUND_DIRECTORY,
            filenamePrefix: 'login_background',
            allowedExtensions: self::ALLOWED_LOGIN_BACKGROUND_EXTENSIONS
        );
    }

    public function removeLoginBackground(): void
    {
        $this->deleteStoredImage(self::LOGIN_BACKGROUND_CONFIG_KEY, self::LOGIN_BACKGROUND_DIRECTORY);
        $this->upsert(self::LOGIN_BACKGROUND_CONFIG_KEY, '');
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    public function resolveLogoFile(): ?array
    {
        return $this->resolveStoredImageFile(self::LOGO_CONFIG_KEY, self::LOGO_DIRECTORY);
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    public function resolveLoginBackgroundFile(): ?array
    {
        return $this->resolveStoredImageFile(self::LOGIN_BACKGROUND_CONFIG_KEY, self::LOGIN_BACKGROUND_DIRECTORY);
    }

    /**
     * @return array<string, mixed>
     */
    private function logoMeta(): array
    {
        return $this->mediaMeta($this->resolveLogoFile());
    }

    /**
     * @return array<string, mixed>
     */
    private function loginBackgroundMeta(): array
    {
        return $this->mediaMeta($this->resolveLoginBackgroundFile());
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

    /**
     * @param array<int, string> $allowedExtensions
     */
    private function storeImage(
        UploadedFile $file,
        string $configKey,
        string $directory,
        string $filenamePrefix,
        array $allowedExtensions
    ): void {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: ''));
        if (! in_array($extension, $allowedExtensions, true)) {
            return;
        }

        $this->deleteStoredImage($configKey, $directory);

        $filename = $filenamePrefix . '_' . now()->format('YmdHisv') . '.' . $extension;
        Storage::disk('local')->putFileAs($directory, $file, $filename);

        $this->upsert($configKey, $directory . '/' . $filename);
    }

    private function deleteStoredImage(string $configKey, string $directory): void
    {
        $relativePath = $this->safeStoredImagePath($configKey, $directory);
        if ($relativePath !== null && Storage::disk('local')->exists($relativePath)) {
            Storage::disk('local')->delete($relativePath);
        }
    }

    /**
     * @return array{absolute_path: string, mime_type: string, filename: string}|null
     */
    private function resolveStoredImageFile(string $configKey, string $directory): ?array
    {
        $relativePath = $this->safeStoredImagePath($configKey, $directory);
        if ($relativePath === null || ! Storage::disk('local')->exists($relativePath)) {
            return null;
        }

        return [
            'absolute_path' => Storage::disk('local')->path($relativePath),
            'mime_type' => Storage::disk('local')->mimeType($relativePath) ?: 'application/octet-stream',
            'filename' => basename($relativePath),
        ];
    }

    private function safeStoredImagePath(string $configKey, string $directory): ?string
    {
        $relativePath = str_replace('\\', '/', trim((string) $this->configValue($configKey)));
        $expectedPrefix = rtrim($directory, '/') . '/';

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '..')
            || ! str_starts_with($relativePath, $expectedPrefix)
        ) {
            return null;
        }

        return $relativePath;
    }

    /**
     * @param array{absolute_path: string, mime_type: string, filename: string}|null $file
     * @return array<string, mixed>
     */
    private function mediaMeta(?array $file): array
    {
        return [
            'exists' => $file !== null,
        ];
    }
}
