<?php

namespace Tests\Unit\Fiscal;

use App\Services\Fiscal\CertificadoA1;
use Tests\TestCase;

/**
 * Cofre do certificado A1.
 *
 * Gera um certificado autoassinado em tempo de teste: dá para exercitar tudo
 * que importa (abrir o .pfx, ler validade e titular, recusar senha errada) sem
 * depender de um certificado ICP-Brasil real, que não existe em CI.
 *
 * O que estes testes protegem é a diferença entre "não sei" e "está válido":
 * arquivo ilegível derruba a emissão do mesmo jeito que um certificado vencido,
 * e tratar os dois como iguais é o que faz a falha aparecer só no fim do mês.
 */
class CertificadoA1Test extends TestCase
{
    private string $diretorio = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->diretorio = sys_get_temp_dir().'/cert-a1-'.bin2hex(random_bytes(6));
        mkdir($this->diretorio, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio.'/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->diretorio);

        parent::tearDown();
    }

    public function test_le_titular_documento_e_validade_de_um_pfx(): void
    {
        $caminho = $this->gerarPfx('JOVEM TECH ASSISTENCIA:11222333000181', 'segredo', 365);

        $certificado = $this->certificado($caminho, 'segredo');

        $this->assertSame([], $certificado->problemas());
        $this->assertTrue($certificado->estaUsavel());
        $this->assertSame('11222333000181', $certificado->documentoTitular());
        $this->assertSame('JOVEM TECH ASSISTENCIA', $certificado->nomeTitular());
        $this->assertNotNull($certificado->expiraEm());
        $this->assertGreaterThan(300, $certificado->diasAteVencimento());
    }

    public function test_senha_errada_nao_abre_o_certificado(): void
    {
        $caminho = $this->gerarPfx('EMPRESA:11222333000181', 'segredo', 365);

        $problemas = $this->certificado($caminho, 'senha-errada')->problemas();

        $this->assertNotEmpty($problemas);
        $this->assertStringContainsString('senha incorreta', implode(' ', $problemas));
    }

    public function test_arquivo_ausente_e_problema_e_nao_silencio(): void
    {
        $problemas = $this->certificado($this->diretorio.'/nao-existe.pfx', 'x')->problemas();

        $this->assertNotEmpty($problemas);
        $this->assertStringContainsString('não encontrado', implode(' ', $problemas));
    }

    public function test_certificado_vencido_e_reportado_como_problema(): void
    {
        // Vencido nao pode passar por "usavel": a emissao para de autenticar.
        $caminho = $this->gerarPfx('EMPRESA:11222333000181', 'segredo', -1);

        $certificado = $this->certificado($caminho, 'segredo');

        $this->assertFalse($certificado->estaUsavel());
        $this->assertStringContainsString('venceu', implode(' ', $certificado->problemas()));
        $this->assertLessThan(0, (int) $certificado->diasAteVencimento());
    }

    public function test_sem_senha_configurada_e_problema(): void
    {
        $caminho = $this->gerarPfx('EMPRESA:11222333000181', '', 365);

        $problemas = $this->certificado($caminho, '')->problemas();

        $this->assertStringContainsString('FISCAL_CERT_SENHA', implode(' ', $problemas));
    }

    public function test_devolve_o_par_pem_para_assinar(): void
    {
        $caminho = $this->gerarPfx('EMPRESA:11222333000181', 'segredo', 365);

        $pem = $this->certificado($caminho, 'segredo')->pem();

        $this->assertIsArray($pem);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $pem['cert']);
        $this->assertNotFalse(openssl_pkey_get_private($pem['pkey']));
    }

    private function certificado(string $caminho, string $senha): CertificadoA1
    {
        config()->set('fiscal.certificado.pfx_path', $caminho);
        config()->set('fiscal.certificado.senha', $senha);

        // Instancia nova por chamada: o cache de leitura e' por instancia.
        return new CertificadoA1();
    }

    /**
     * Certificado autoassinado, no formato .pfx que a AC entrega no A1.
     */
    private function gerarPfx(string $commonName, string $senha, int $diasValidade): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($chave, 'nao foi possivel gerar a chave RSA');

        $csr = openssl_csr_new(['commonName' => $commonName, 'countryName' => 'BR'], $chave, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($csr);

        // `openssl_csr_sign` nao aceita validade negativa; para simular vencido
        // o certificado nasce com 1 dia e o relogio anda para frente.
        $dias = max(1, $diasValidade);
        $x509 = openssl_csr_sign($csr, null, $chave, $dias, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($x509);

        if ($diasValidade < 0) {
            $this->travelTo(now()->addDays($dias + 1));
        }

        $pfx = '';
        $this->assertTrue(openssl_pkcs12_export($x509, $pfx, $chave, $senha));

        $caminho = $this->diretorio.'/certificado.pfx';
        file_put_contents($caminho, $pfx);

        return $caminho;
    }
}
