<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CompanyProfileService
{
    private const BRANDING_CACHE_KEY = 'desktop:company_branding';

    private const BRANDING_CACHE_SECONDS = 60;

    /** @var array{name: string, has_logo: bool, has_login_background: bool}|null */
    private ?array $brandingMemo = null;

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
    public function update(array $payload, ?UploadedFile $logo = null, ?UploadedFile $loginBackground = null): array
    {
        $files = [];
        if ($logo instanceof UploadedFile) {
            $files['empresa_logo'] = [$logo];
        }
        if ($loginBackground instanceof UploadedFile) {
            $files['login_background_image'] = [$loginBackground];
        }

        $response = $this->apiClient->postMultipart('/configuracoes/empresa', array_merge($payload, [
            '_method' => 'PATCH',
        ]), $files);

        Cache::forget(self::BRANDING_CACHE_KEY);
        $this->brandingMemo = null;

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
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPublicFavicon(): array
    {
        return $this->apiClient->guestDownload('/configuracoes/empresa/favicon-publico');
    }

    /**
     * @return array{body: string, headers: array<string, string>, status: int}
     */
    public function downloadPublicLoginBackground(): array
    {
        return $this->apiClient->guestDownload('/configuracoes/empresa/login-background-publico');
    }

    /**
     * @return array{name: string, has_logo: bool, has_login_background: bool}
     */
    /**
     * Memoizado por requisicao ALEM do cache de 60s.
     *
     * O View::composer('*') do DesktopAppServiceProvider dispara para cada view
     * renderizada, e uma tela de OS renderiza ~14 delas — o que significava ~14
     * leituras do cache (em disco, com CACHE_STORE=file) por pagina para
     * devolver sempre o mesmo valor. A marca da empresa nao muda no meio de uma
     * requisicao, entao resolver uma vez basta.
     *
     * A propriedade e' de instancia e o servico e' resolvido por requisicao, o
     * que mantem o valor preso ao ciclo de vida certo — nada de estatico, que
     * vazaria entre requisicoes na suite de testes.
     */
    public function branding(): array
    {
        if ($this->brandingMemo !== null) {
            return $this->brandingMemo;
        }

        return $this->brandingMemo = $this->resolveBranding();
    }

    /**
     * @return array{name: string, has_logo: bool, has_login_background: bool}
     */
    private function resolveBranding(): array
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
                'has_login_background' => (bool) ($data['login_background']['exists'] ?? false),
            ];
        });
    }

    /**
     * @return array{name: string, has_logo: bool, has_login_background: bool}
     */
    private function fallbackBranding(): array
    {
        return [
            'name' => 'Sistema ERP',
            'has_logo' => false,
            'has_login_background' => false,
        ];
    }
}
