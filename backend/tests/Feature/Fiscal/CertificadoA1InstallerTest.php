<?php

namespace Tests\Feature\Fiscal;

use App\Models\Configuration;
use App\Services\Fiscal\CertificadoA1;
use App\Services\Fiscal\CertificadoA1Installer;
use App\Support\SecretSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Instalação do certificado A1 pela tela.
 *
 * Existe porque o sistema é vendido: o novo dono instala o próprio certificado
 * sem terminal. O que estes testes protegem é a validação ANTES de gravar —
 * salvar primeiro e conferir depois trocaria um certificado que funciona por um
 * quebrado, e o erro só apareceria na emissão.
 */
class CertificadoA1InstallerTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $diretorio = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();

        $this->diretorio = sys_get_temp_dir().'/inst-'.bin2hex(random_bytes(6));
        mkdir($this->diretorio, 0700, true);

        config()->set('fiscal.certificado.pfx_path', $this->diretorio.'/certificado.pfx');
        config()->set('fiscal.certificado.senha', '');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio.'/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->diretorio);

        parent::tearDown();
    }

    public function test_instala_o_certificado_e_cifra_a_senha_em_repouso(): void
    {
        $estado = $this->instalador()->instalar($this->pfx('segredo'), 'segredo');

        $this->assertTrue($estado['instalado']);
        $this->assertTrue($estado['usavel']);
        $this->assertSame('11222333000181', $estado['documento_titular']);

        // O .pfx vai para o disco, nunca para o banco.
        $this->assertFileExists(config('fiscal.certificado.pfx_path'));

        // A senha fica cifrada: gravada em claro seria pior que o .env.
        $guardado = (string) Configuration::query()
            ->where('chave', CertificadoA1::CHAVE_SENHA)
            ->value('valor');

        $this->assertNotSame('segredo', $guardado);
        $this->assertSame(
            'segredo',
            SecretSettings::decrypt(CertificadoA1::CHAVE_SENHA, $guardado, [CertificadoA1::CHAVE_SENHA])
        );
    }

    public function test_senha_errada_nao_grava_nada(): void
    {
        try {
            $this->instalador()->instalar($this->pfx('segredo'), 'senha-errada');
            $this->fail('deveria ter recusado');
        } catch (ValidationException $excecao) {
            $this->assertArrayHasKey('senha', $excecao->errors());
        }

        $this->assertFileDoesNotExist(config('fiscal.certificado.pfx_path'));
        $this->assertDatabaseMissing('configuracoes', ['chave' => CertificadoA1::CHAVE_SENHA]);
    }

    public function test_nao_substitui_um_certificado_bom_por_um_invalido(): void
    {
        // O caso caro: já havia um funcionando e alguém sobe o arquivo errado.
        $this->instalador()->instalar($this->pfx('segredo'), 'segredo');
        $conteudoOriginal = file_get_contents(config('fiscal.certificado.pfx_path'));

        try {
            $this->instalador()->instalar(
                UploadedFile::fake()->createWithContent('lixo.pfx', 'isto nao e um pfx'),
                'qualquer'
            );
            $this->fail('deveria ter recusado');
        } catch (ValidationException) {
            // esperado
        }

        $this->assertSame($conteudoOriginal, file_get_contents(config('fiscal.certificado.pfx_path')));
    }

    public function test_recusa_certificado_vencido(): void
    {
        // Instalar um vencido mostraria "configurado" na tela e falharia só na
        // emissão — a pior falha possível deste fluxo.
        $arquivo = $this->pfx('segredo', vencido: true);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/venceu/');

        $this->instalador()->instalar($arquivo, 'segredo');
    }

    public function test_remover_apaga_arquivo_e_senha(): void
    {
        $this->instalador()->instalar($this->pfx('segredo'), 'segredo');

        $estado = $this->instalador()->remover();

        $this->assertFalse($estado['instalado']);
        $this->assertFileDoesNotExist(config('fiscal.certificado.pfx_path'));
        $this->assertDatabaseMissing('configuracoes', ['chave' => CertificadoA1::CHAVE_SENHA]);
    }

    public function test_senha_da_tela_tem_precedencia_sobre_o_env(): void
    {
        config()->set('fiscal.certificado.senha', 'senha-do-env-desatualizada');

        $this->instalador()->instalar($this->pfx('senha-nova'), 'senha-nova');

        $this->assertTrue((new CertificadoA1())->estaUsavel());
    }

    private function instalador(): CertificadoA1Installer
    {
        // Instância nova: o cache de leitura do .pfx é por instância.
        return new CertificadoA1Installer(new CertificadoA1());
    }

    private function pfx(string $senha, bool $vencido = false): UploadedFile
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'JOVEM TECH:11222333000181', 'countryName' => 'BR'], $chave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $chave, 1, ['digest_alg' => 'sha256']);

        $pfx = '';
        openssl_pkcs12_export($x509, $pfx, $chave, $senha);

        if ($vencido) {
            // `openssl_csr_sign` não aceita validade negativa; o relógio anda.
            $this->travelTo(now()->addDays(2));
        }

        return UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);
    }
}
