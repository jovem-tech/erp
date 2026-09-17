<?php

namespace App\Services\Pdf\Contexts;

use App\Services\Company\CompanyProfileService;
use App\Services\Photos\OperationalPhotoPdfRenderer;
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

    // O template exibe a logo a no máximo 150pt de largura (~150 CSS px) —
    // ver PdfDefaultTemplates 'largura_max' e PdfTemplateRenderer::renderImage.
    // A fonte cadastrada podia ter centenas de KB (697x800 px medido em
    // produção = 38 KB sozinha, quase metade do teto de 80 KB do documento
    // inteiro) sem nenhum ganho visual acima disso.
    private const LOGO_DISPLAY_MAX_DIMENSION = 300;

    private const LOGO_DISPLAY_QUALITY = 70;

    public function __construct(
        private readonly CompanyProfileService $companyProfileService,
        private readonly OperationalPhotoPdfRenderer $photoPdfRenderer
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
            // Endereco estruturado (041). Aditivo: os templates ja' publicados
            // continuam lendo `endereco`, que nao mudou.
            'logradouro' => trim((string) ($settings['empresa_logradouro'] ?? '')),
            'numero' => trim((string) ($settings['empresa_numero'] ?? '')),
            'complemento' => trim((string) ($settings['empresa_complemento'] ?? '')),
            'bairro' => trim((string) ($settings['empresa_bairro'] ?? '')),
            'cidade' => trim((string) ($settings['empresa_cidade'] ?? '')),
            'uf' => trim((string) ($settings['empresa_uf'] ?? '')),
            'cep' => trim((string) ($settings['empresa_cep'] ?? '')),
            'codigo_ibge' => trim((string) ($settings['empresa_codigo_ibge'] ?? '')),
            'inscricao_municipal' => trim((string) ($settings['empresa_inscricao_municipal'] ?? '')),
            'cnae' => trim((string) ($settings['empresa_cnae'] ?? '')),
            // Aditivo (Anexo X): proporcionaliza o limite do MEI no ano de
            // abertura. Templates ja' publicados nao veem diferenca.
            'data_abertura' => trim((string) ($settings['empresa_data_abertura'] ?? '')),
            'logo_base64' => $this->logoBase64(),
        ];
    }

    public function logoDataUri(): string
    {
        return $this->logoBase64();
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

            $mime = (string) ($logo['mime_type'] ?? 'image/png');
            $rendered = $this->photoPdfRenderer->forPdf(
                $absolutePath,
                $mime,
                self::LOGO_DISPLAY_MAX_DIMENSION,
                self::LOGO_DISPLAY_QUALITY
            );
            if (is_array($rendered) && $rendered['bytes'] !== '') {
                return 'data:' . $rendered['mime'] . ';base64,' . base64_encode($rendered['bytes']);
            }

            // vips indisponível: embute a fonte como está (pior caso já
            // coberto pelo teto de tamanho aplicado no PDF inteiro).
            $bytes = file_get_contents($absolutePath);
            if ($bytes === false) {
                return '';
            }

            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        });
    }

    /**
     * Referência da logo para o snapshot documental (no lugar do base64).
     * O re-render reembute a logo atual; se o hash mudou desde a emissão,
     * quem hidrata registra a divergência.
     *
     * @return array{tipo: string, arquivo: string, sha256: string}|null
     */
    public function logoReference(): ?array
    {
        $logo = $this->companyProfileService->resolveLogoFile();
        if (! is_array($logo)) {
            return null;
        }

        $absolutePath = (string) ($logo['absolute_path'] ?? '');
        if ($absolutePath === '' || ! is_file($absolutePath)) {
            return null;
        }

        return [
            'tipo' => 'company_logo',
            'arquivo' => basename($absolutePath),
            'sha256' => (string) hash_file('sha256', $absolutePath),
        ];
    }

    public static function forgetLogoCache(): void
    {
        Cache::forget(self::LOGO_CACHE_KEY);
    }
}
