<?php

namespace Tests\Concerns;

/**
 * Gera um `.pfx` de verdade para os testes fiscais.
 *
 * Certificado real, com par de chaves real — nao um dublê. E' o que permite
 * assinar a DPS e conferir a assinatura de volta sem depender de certificado
 * ICP-Brasil nem de acesso a homologacao. Mesma ideia do
 * `GeneratesInterTestCertificate`, que faz isso para o mTLS do Banco Inter.
 *
 * O CN segue o formato da ICP-Brasil (`RAZAO SOCIAL:CNPJ`) porque
 * `CertificadoA1` extrai o documento do titular dali.
 */
trait GeneratesFiscalTestCertificate
{
    private string $diretorioCertificadoFiscal = '';

    /**
     * Instala um A1 valido e aponta a config para ele.
     */
    protected function instalarCertificadoFiscalDeTeste(
        string $commonName = 'JOVEM TECH:11222333000181',
        string $senha = 'segredo',
        int $diasDeValidade = 365
    ): string {
        if ($this->diretorioCertificadoFiscal === '') {
            $this->diretorioCertificadoFiscal = sys_get_temp_dir().'/fiscal-cert-'.bin2hex(random_bytes(6));
            @mkdir($this->diretorioCertificadoFiscal, 0700, true);
        }

        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $commonName, 'countryName' => 'BR'], $chave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $chave, $diasDeValidade, ['digest_alg' => 'sha256']);

        $pfx = '';
        openssl_pkcs12_export($x509, $pfx, $chave, $senha);

        $caminho = $this->diretorioCertificadoFiscal.'/certificado.pfx';
        file_put_contents($caminho, $pfx);

        config()->set('fiscal.certificado.pfx_path', $caminho);
        config()->set('fiscal.certificado.senha', $senha);

        return $caminho;
    }

    /**
     * Aponta a config para um caminho que nao existe — o cenario "ninguem
     * instalou o certificado ainda".
     */
    protected function desinstalarCertificadoFiscalDeTeste(): void
    {
        config()->set('fiscal.certificado.pfx_path', sys_get_temp_dir().'/nao-existe-'.bin2hex(random_bytes(4)).'.pfx');
        config()->set('fiscal.certificado.senha', '');
    }

    protected function limparCertificadoFiscalDeTeste(): void
    {
        if ($this->diretorioCertificadoFiscal === '') {
            return;
        }

        foreach (glob($this->diretorioCertificadoFiscal.'/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }

        @rmdir($this->diretorioCertificadoFiscal);
        $this->diretorioCertificadoFiscal = '';
    }
}
