<?php

namespace Tests\Feature\Integrations\Inter;

use App\Services\Integrations\Inter\InterCredentials;
use App\Services\Integrations\Inter\InterException;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class InterCredentialsTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesInterTestCertificate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    private function credentials(): InterCredentials
    {
        return new InterCredentials(app(PaymentIntegrationSettingsService::class));
    }

    public function test_lista_o_que_falta_em_vez_de_so_dizer_que_nao_esta_configurado(): void
    {
        config([
            'inter.base_url' => 'https://cdpj.example.test',
            'inter.certificado.cert_path' => '/caminho/que/nao/existe.crt',
            'inter.certificado.key_path' => '/caminho/que/nao/existe.key',
        ]);

        $problemas = $this->credentials()->problemas();

        $this->assertFalse($this->credentials()->estaConfigurado());

        $texto = implode(' ', $problemas);
        $this->assertStringContainsString('Client ID', $texto);
        $this->assertStringContainsString('Client Secret', $texto);
        $this->assertStringContainsString('certificado', $texto);
        $this->assertStringContainsString('chave privada', $texto);
    }

    public function test_assert_usavel_lanca_com_a_lista_de_problemas(): void
    {
        config(['inter.certificado.cert_path' => '/nao/existe.crt']);

        $this->expectException(InterException::class);
        $this->expectExceptionMessageMatches('/nao esta configurada/i');

        $this->credentials()->assertUsavel();
    }

    public function test_le_a_validade_do_certificado_e_calcula_dias_restantes(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: 45);

        $credentials = $this->credentials();

        $this->assertNotNull($credentials->certificadoExpiraEm());
        // Tolerancia de 1 dia: o openssl arredonda pelo horario de geracao.
        $this->assertEqualsWithDelta(45, $credentials->diasAteVencimento(), 1);
        $this->assertFalse($credentials->certificadoVencido());
    }

    public function test_detecta_certificado_vencido(): void
    {
        $this->gerarCertificadoDeTeste(diasDeValidade: -5);

        $credentials = $this->credentials();

        $this->assertTrue($credentials->certificadoVencido());
        $this->assertLessThan(0, $credentials->diasAteVencimento());
    }

    public function test_validade_e_null_quando_o_arquivo_nao_e_um_certificado(): void
    {
        $this->gerarCertificadoDeTeste();
        File::put(config('inter.certificado.cert_path'), 'isto nao e um x509');

        // Null significa "nao sei", nao "esta valido" — quem consome precisa
        // tratar os dois casos de forma diferente.
        $this->assertNull($this->credentials()->certificadoExpiraEm());
        $this->assertNull($this->credentials()->diasAteVencimento());
        $this->assertFalse($this->credentials()->certificadoVencido());
    }

    public function test_caminho_relativo_e_resolvido_contra_a_raiz_do_projeto(): void
    {
        config(['inter.certificado.cert_path' => 'storage/app/private/x.crt']);

        $this->assertSame(base_path('storage/app/private/x.crt'), $this->credentials()->certPath());
    }

    public function test_caminho_absoluto_e_preservado(): void
    {
        config(['inter.certificado.cert_path' => '/etc/ssl/inter.crt']);

        $this->assertSame('/etc/ssl/inter.crt', $this->credentials()->certPath());
    }

    public function test_opcoes_de_mtls_incluem_a_passphrase_quando_houver(): void
    {
        $this->gerarCertificadoDeTeste();

        config(['inter.certificado.key_passphrase' => '']);
        $sem = $this->credentials()->guzzleTlsOptions();
        $this->assertIsString($sem['ssl_key']);

        config(['inter.certificado.key_passphrase' => 'segredo-da-chave']);
        $com = $this->credentials()->guzzleTlsOptions();
        // keyPath() e nao o valor bruto do config: caminho relativo e' resolvido
        // contra base_path(), e e' o resolvido que vai para o Guzzle.
        $this->assertSame([$this->credentials()->keyPath(), 'segredo-da-chave'], $com['ssl_key']);
    }

    public function test_resumo_nunca_expoe_secret_nem_passphrase(): void
    {
        $this->gerarCertificadoDeTeste();
        $this->gravarCredenciaisDoInter(clientSecret: 'SEGREDO-QUE-NAO-PODE-VAZAR');
        config(['inter.certificado.key_passphrase' => 'PASSPHRASE-QUE-NAO-PODE-VAZAR']);

        $resumo = json_encode($this->credentials()->resumo(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('SEGREDO-QUE-NAO-PODE-VAZAR', (string) $resumo);
        $this->assertStringNotContainsString('PASSPHRASE-QUE-NAO-PODE-VAZAR', (string) $resumo);
        $this->assertStringContainsString('"client_secret_configurado":true', (string) $resumo);
    }

    public function test_conta_da_tela_tem_precedencia_sobre_a_do_env(): void
    {
        config(['inter.conta_corrente' => '111111']);
        $this->gravarCredenciaisDoInter(contaCorrente: '999999');

        $this->assertSame('999999', $this->credentials()->contaCorrente());
    }
}
