<?php

namespace App\Services\Pdf\Contexts;

use App\Services\Company\CompanyProfileService;
use Illuminate\Support\Facades\Cache;

/**
 * Bloco `empresa.*` do DocumentContext — dados institucionais + logo em
 * base64 (dompdf roda com isRemoteEnabled=false, então a logo é embutida;
 * origem exclusivamente interna via CompanyProfileService::resolveLogoFile,
 * que já tem guard de path traversal).
 */
class CompanyContextProvider
{
    private const LOGO_CACHE_KEY = 'pdf_engine_logo_b64';

    private const LOGO_CACHE_TTL_SECONDS = 600;

    private const LOGO_MAX_BYTES = 1048576; // 1 MB — acima disso, pula com warning

    public function __construct(
        private readonly CompanyProfileService $companyProfileService
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function build(): array
    {
        $payload = $this->companyProfileService->payload();
        $settings = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
        $systemName = trim((string) ($settings['sistema_nome'] ?? ''));
        $legalName = trim((string) ($settings['empresa_razao_social'] ?? ''));
        $tradeName = trim((string) ($settings['empresa_nome_fantasia'] ?? ''));

        // Branding resiliente: nome do sistema e nome fantasia são usados em
        // cabeçalhos. Se apenas um deles foi cadastrado, o outro não deve
        // desaparecer do PDF. A razão social continua independente.
        if ($systemName === '') {
            $systemName = $tradeName !== '' ? $tradeName : $legalName;
        }
        if ($tradeName === '') {
            $tradeName = $systemName !== '' ? $systemName : $legalName;
        }

        return [
            'nome_sistema' => $systemName,
            'razao_social' => $legalName,
            'nome_fantasia' => $tradeName,
            'cnpj' => trim((string) ($settings['empresa_cnpj'] ?? '')),
            'inscricao_estadual' => trim((string) ($settings['empresa_inscricao_estadual'] ?? '')),
            'telefone' => trim((string) ($settings['empresa_telefone'] ?? '')),
            'email' => trim((string) ($settings['empresa_email'] ?? '')),
            'endereco' => trim((string) ($settings['empresa_endereco'] ?? '')),
            'logo_base64' => $this->logoBase64(),
        ];
    }

    /**
     * Data URI (base64) da logo, ou string vazia quando não houver logo,
     * arquivo grande demais ou formato não rasterizável.
     */
    private function logoBase64(): string
    {
        return (string) Cache::remember(self::LOGO_CACHE_KEY, self::LOGO_CACHE_TTL_SECONDS, function (): string {
            $logo = $this->companyProfileService->resolveLogoFile();
            if (! is_array($logo)) {
                return '';
            }

            $absolutePath = (string) ($logo['absolute_path'] ?? '');
            if ($absolutePath === '' || ! is_file($absolutePath)) {
                return '';
            }

            $size = filesize($absolutePath);
            if ($size === false || $size > self::LOGO_MAX_BYTES) {
                logger()->warning('[PDF ENGINE] Logo da empresa ignorada no PDF (tamanho acima do limite)', [
                    'bytes' => $size,
                    'limite' => self::LOGO_MAX_BYTES,
                ]);

                return '';
            }

            $bytes = file_get_contents($absolutePath);
            if ($bytes === false) {
                return '';
            }

            $mime = (string) ($logo['mime_type'] ?? 'image/png');

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        });
    }

    public static function forgetLogoCache(): void
    {
        Cache::forget(self::LOGO_CACHE_KEY);
    }
}
