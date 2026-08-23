<?php

namespace Tests\Concerns;

use App\Services\Integrations\PaymentIntegrationSettingsService;
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

    protected function gerarCertificadoDeTeste(int $diasDeValidade = 365, string $passphrase = ''): void
    {
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

        // openssl_csr_sign so' aceita validade em dias inteiros e positivos.
        // Para simular certificado JA vencido, assinamos com 1 dia e voltamos
        // o relogio via serialNumber? Nao da'. Entao usamos $days negativo
        // atraves de um certificado com notBefore no passado: a forma portavel
        // e' assinar com |dias| e, quando negativo, regravar com o binario do
        // openssl, que aceita -startdate/-enddate.
        if ($diasDeValidade >= 0) {
            $cert = openssl_csr_sign($csr, null, $key, $diasDeValidade, ['digest_alg' => 'sha256']);
            $this->assertNotFalse($cert, 'Nao foi possivel assinar o certificado de teste.');
            openssl_x509_export_to_file($cert, $certPath);
        } else {
            $this->gerarCertificadoVencido($certPath, $keyPath, abs($diasDeValidade));
        }

        if ($diasDeValidade >= 0) {
            openssl_pkey_export_to_file($key, $keyPath, $passphrase === '' ? null : $passphrase);
        }

        config([
            'inter.certificado.cert_path' => $certPath,
            'inter.certificado.key_path' => $keyPath,
            'inter.certificado.key_passphrase' => $passphrase,
        ]);
    }

    /**
     * Certificado com notAfter no passado. Usa o binario do openssl porque a
     * extensao do PHP nao expoe -enddate.
     */
    private function gerarCertificadoVencido(string $certPath, string $keyPath, int $diasAtras): void
    {
        $inicio = gmdate('ymdHis', time() - (($diasAtras + 30) * 86400)).'Z';
        $fim = gmdate('ymdHis', time() - ($diasAtras * 86400)).'Z';

        $comando = sprintf(
            'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -subj "/CN=inter-test-vencido" -not_before %s -not_after %s 2>/dev/null',
            escapeshellarg($keyPath),
            escapeshellarg($certPath),
            escapeshellarg($inicio),
            escapeshellarg($fim)
        );

        exec($comando, $saida, $status);

        if ($status !== 0 || ! is_file($certPath)) {
            // OpenSSL < 3.2 nao tem -not_before/-not_after. Cai para -days
            // negativo, que versoes antigas aceitam.
            $fallback = sprintf(
                'openssl req -x509 -newkey rsa:2048 -nodes -keyout %s -out %s -subj "/CN=inter-test-vencido" -days -%d 2>/dev/null',
                escapeshellarg($keyPath),
                escapeshellarg($certPath),
                $diasAtras
            );
            exec($fallback, $saida2, $status2);

            $this->assertSame(0, $status2, 'Nao foi possivel gerar certificado vencido para o teste.');
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
