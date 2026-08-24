<?php

namespace Tests\Concerns;

use App\Services\Integrations\PaymentIntegrationSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Gera um par certificado/chave real para os testes do Banco Inter.
 *
 * Certificado de verdade, nao fixture estatica: a leitura de validade usa
 * openssl_x509_parse(), e uma fixture com data fixa passaria a falhar sozinha
 * no dia em que vencesse. Aqui a validade e' relativa ao "agora" do teste.
 */
trait GeneratesInterTestCertificate
{
    private ?string $interCertDir = null;

    /**
     * Gera um par certificado/chave real para os testes.
     *
     * Validade negativa NAO e' produzida pelo openssl (as flags -not_before/
     * -not_after nao existem em todas as versoes, e -days negativo tambem nao e'
     * portavel — isso deixava o teste de certificado vencido passando ou
     * falhando conforme a maquina). Em vez disso o certificado nasce valido e o
     * RELOGIO do teste avanca: Carbon::setTestNow e' deterministico e nao
     * depende do binario instalado.
     */
    protected function gerarCertificadoDeTeste(int $diasDeValidade = 365, string $passphrase = ''): void
    {
        $vencido = $diasDeValidade < 0;
        $diasReais = $vencido ? 1 : max(1, $diasDeValidade);

        $this->interCertDir = storage_path('framework/testing/inter-cert-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($this->interCertDir);

        $certPath = $this->interCertDir.'/cliente.crt';
        $keyPath = $this->interCertDir.'/cliente.key';

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key, 'Nao foi possivel gerar a chave RSA de teste.');

        $csr = openssl_csr_new(
            ['commonName' => 'inter-test', 'countryName' => 'BR'],
            $key,
            ['digest_alg' => 'sha256']
        );

        $this->assertNotFalse($csr, 'Nao foi possivel gerar a CSR de teste.');

        $cert = openssl_csr_sign($csr, null, $key, $diasReais, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($cert, 'Nao foi possivel assinar o certificado de teste.');

        openssl_x509_export_to_file($cert, $certPath);
        openssl_pkey_export_to_file($key, $keyPath, $passphrase === '' ? null : $passphrase);

        config([
            'inter.certificado.cert_path' => $certPath,
            'inter.certificado.key_path' => $keyPath,
            'inter.certificado.key_passphrase' => $passphrase,
        ]);

        if ($vencido) {
            // Relogio adiante do vencimento. O TestCase do Laravel limpa o
            // setTestNow sozinho no tearDown.
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays($diasReais + abs($diasDeValidade)));
            Carbon::setTestNow(Carbon::now()->addDays($diasReais + abs($diasDeValidade)));
        }
    }

    protected function gravarCredenciaisDoInter(
        string $clientId = 'client-id-de-teste',
        string $clientSecret = 'client-secret-de-teste',
        string $contaCorrente = '',
        string $ambiente = 'sandbox'
    ): void {
        app(PaymentIntegrationSettingsService::class)->save([
            'pagamentos_inter_enabled' => true,
            'pagamentos_inter_ambiente' => $ambiente,
            'pagamentos_inter_client_id' => $clientId,
            'pagamentos_inter_client_secret' => $clientSecret,
            'pagamentos_inter_conta_corrente' => $contaCorrente,
        ]);
    }

    protected function limparCertificadosDeTeste(): void
    {
        if ($this->interCertDir !== null && is_dir($this->interCertDir)) {
            File::deleteDirectory($this->interCertDir);
        }

        $this->interCertDir = null;
    }
}
