<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CompanyProfileService
{
    private const BRANDING_CACHE_KEY = 'desktop:company_branding';

    private const BRANDING_CACHE_SECONDS = 60;

    public function __construct(
        private readonly ApiClient $apiClient
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function find(): array
    {
        $response = $this->apiClient->get('/configuracoes/empresa');

        return $response['data'] ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(array $payload, ?UploadedFile $logo = null): array
    {
        $files = [];
        if ($logo instanceof UploadedFile) {
            $files['empresa_logo'] = [$logo];
        }

        $response = $this->apiClient->postMultipart('/configuracoes/empresa', array_merge($payload, [
            '_method' => 'PATCH',
        ]), $files);

        Cache::forget(self::BRANDING_CACHE_KEY);

        return $response['data'] ?? [];
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadLogo(): array
    {
        return $this->apiClient->download('/configuracoes/empresa/logo');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPublicLogo(): array
    {
        return $this->apiClient->guestDownload('/configuracoes/empresa/logo-publica');
    }

    /**
     * @return array{name: string, has_logo: bool}
     */
    public function branding(): array
    {
        return Cache::remember(self::BRANDING_CACHE_KEY, now()->addSeconds(self::BRANDING_CACHE_SECONDS), function (): array {
            try {
                $response = $this->apiClient->guestGet('/configuracoes/empresa/publico');
                $data = $response['data'] ?? [];
            } catch (Throwable) {
                return $this->fallbackBranding();
            }

            $name = trim((string) ($data['sistema_nome'] ?? ''));

            return [
                'name' => $name !== '' ? $name : 'Sistema ERP',
                'has_logo' => (bool) ($data['logo']['exists'] ?? false),
            ];
        });
    }

    /**
     * @return array{name: string, has_logo: bool}
     */
    private function fallbackBranding(): array
    {
        return [
            'name' => 'Sistema ERP',
            'has_logo' => false,
        ];
    }
}
