<?php

namespace App\Services\Integrations\Inter;

use App\Services\Integrations\PaymentIntegrationSettingsService;
use Carbon\CarbonImmutable;

/**
 * Resolve e valida as credenciais do Banco Inter.
 *
 * Tudo aqui e' resolvido POR METODO, nunca lido de config() no ponto de uso.
 * Quando o sistema ganhar tenant_id, esta classe e' o unico lugar que muda —
 * o InterClient continua pedindo "o certificado", sem saber de quem.
 */
class InterCredentials
{
    public function __construct(
        private readonly PaymentIntegrationSettingsService $settings,
    ) {
    }

    public function ambiente(): string
    {
        $ambiente = mb_strtolower(trim((string) config('inter.ambiente', 'sandbox')));

        return in_array($ambiente, ['sandbox', 'producao'], true) ? $ambiente : 'sandbox';
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('inter.base_url', ''), '/');
    }

    public function clientId(): string
    {
        return trim((string) ($this->settings->interSettings()['pagamentos_inter_client_id'] ?? ''));
    }

    public function clientSecret(): string
    {
        return trim((string) ($this->settings->interSettings()['pagamentos_inter_client_secret'] ?? ''));
    }

    public function contaCorrente(): string
    {
        // A conta da tela tem precedencia sobre a do .env: trocar de conta nao
        // deveria exigir deploy.
        $doBanco = trim((string) ($this->settings->interSettings()['pagamentos_inter_conta_corrente'] ?? ''));

        return $doBanco !== '' ? $doBanco : trim((string) config('inter.conta_corrente', ''));
    }

    public function certPath(): string
    {
        return $this->resolvePath((string) config('inter.certificado.cert_path', ''));
    }

    public function keyPath(): string
    {
        return $this->resolvePath((string) config('inter.certificado.key_path', ''));
    }

    public function keyPassphrase(): ?string
    {
        $passphrase = (string) config('inter.certificado.key_passphrase', '');

        return $passphrase === '' ? null : $passphrase;
    }

    /**
     * Opcoes de mTLS no formato que o Guzzle espera.
     *
     * @return array<string, mixed>
     */
    public function guzzleTlsOptions(): array
    {
        $passphrase = $this->keyPassphrase();

        return [
            'cert' => $this->certPath(),
            'ssl_key' => $passphrase === null
                ? $this->keyPath()
                : [$this->keyPath(), $passphrase],
        ];
    }

    public function estaConfigurado(): bool
    {
        return $this->problemas() === [];
    }

    /**
     * Lista o que falta, em vez de devolver so' um booleano.
     *
     * @return array<int, string>
     */
    public function problemas(): array
    {
        $problemas = [];

        if ($this->baseUrl() === '') {
            $problemas[] = 'INTER_BASE_URL nao configurada.';
        }

        if ($this->clientId() === '') {
            $problemas[] = 'Client ID do Inter nao informado em Configuracoes > Integracoes.';
        }

        if ($this->clientSecret() === '') {
            $problemas[] = 'Client Secret do Inter nao informado em Configuracoes > Integracoes.';
        }

        foreach ([
            'certificado (INTER_CERT_PATH)' => $this->certPath(),
            'chave privada (INTER_KEY_PATH)' => $this->keyPath(),
        ] as $rotulo => $caminho) {
            if ($caminho === '') {
                $problemas[] = "Caminho do {$rotulo} nao configurado.";

                continue;
            }

            if (! is_file($caminho)) {
                $problemas[] = "Arquivo do {$rotulo} nao encontrado em {$caminho}.";

                continue;
            }

            if (! is_readable($caminho)) {
                $problemas[] = "Arquivo do {$rotulo} existe mas nao e' legivel pelo processo do PHP ({$caminho}).";
            }
        }

        return $problemas;
    }

    /**
     * @throws InterException quando falta qualquer credencial.
     */
    public function assertUsavel(): void
    {
        $problemas = $this->problemas();

        if ($problemas !== []) {
            throw InterException::local(
                'Integracao com o Banco Inter nao esta configurada: '.implode(' ', $problemas),
                ['problemas' => $problemas]
            );
        }
    }

    /**
     * Quando o certificado da aplicacao expira.
     *
     * Null quando o arquivo nao existe ou nao e' um X.509 legivel — o chamador
     * trata isso como "nao sei", nao como "esta valido".
     */
    public function certificadoExpiraEm(): ?CarbonImmutable
    {
        $path = $this->certPath();

        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $conteudo = @file_get_contents($path);

        if ($conteudo === false || $conteudo === '') {
            return null;
        }

        $dados = @openssl_x509_parse($conteudo);

        if (! is_array($dados) || ! isset($dados['validTo_time_t'])) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC((int) $dados['validTo_time_t']);
    }

    /**
     * Dias ate' o vencimento. Negativo quando ja' venceu; null quando nao da'
     * para saber.
     */
    public function diasAteVencimento(): ?int
    {
        $expiraEm = $this->certificadoExpiraEm();

        if ($expiraEm === null) {
            return null;
        }

        return (int) CarbonImmutable::now()->startOfDay()->diffInDays($expiraEm->startOfDay(), false);
    }

    public function certificadoVencido(): bool
    {
        $dias = $this->diasAteVencimento();

        return $dias !== null && $dias < 0;
    }

    /**
     * Resumo seguro para tela/log. NUNCA inclui secret nem passphrase.
     *
     * @return array<string, mixed>
     */
    public function resumo(): array
    {
        $expiraEm = $this->certificadoExpiraEm();

        return [
            'ambiente' => $this->ambiente(),
            'base_url' => $this->baseUrl(),
            'client_id_configurado' => $this->clientId() !== '',
            'client_secret_configurado' => $this->clientSecret() !== '',
            'conta_corrente' => $this->contaCorrente(),
            'certificado_encontrado' => is_file($this->certPath()),
            'certificado_expira_em' => $expiraEm?->toDateString(),
            'certificado_dias_restantes' => $this->diasAteVencimento(),
            'certificado_vencido' => $this->certificadoVencido(),
            'pronto' => $this->estaConfigurado(),
            'problemas' => $this->problemas(),
        ];
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        // Absoluto (unix ou windows) fica como esta'.
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
