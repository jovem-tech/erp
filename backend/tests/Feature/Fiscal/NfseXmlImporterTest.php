<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\DpsXmlBuilder;
use App\Services\Fiscal\NfseXmlImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Leitura do XML da NFS-e baixado do Emissor Nacional.
 *
 * A fixture é uma nota real (ver tests/Fixtures/nfse/ORIGEM.md). Testar contra
 * o arquivo de verdade é o que separa "meu parser funciona no meu exemplo" de
 * "funciona no que o portal entrega" — inclusive na acentuação duplamente
 * codificada, que só aparece no arquivo real.
 *
 * **A fixture não tem assinatura** (foi removida antes de versionar o
 * arquivo), e o importador agora exige uma por padrão. Em vez de desligar a
 * exigência e deixar o caminho principal sem cobertura, os testes ASSINAM a
 * fixture em tempo de execução com um certificado autoassinado, usando o mesmo
 * `DpsXmlBuilder` que assina a DPS de verdade. Isso cobre de graça uma coisa
 * que nenhum dos dois lados provava sozinho: que o que este projeto assina é
 * o que este projeto consegue conferir.
 */
class NfseXmlImporterTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    public function test_extrai_numero_serie_e_chave_da_nota_real(): void
    {
        $lido = $this->importar();

        $this->assertSame('2', $lido['numero']);
        $this->assertSame('70000', $lido['serie']);
        $this->assertSame('33052082234129526000198000000000000226086919348703', $lido['chave']);
        $this->assertSame('1', $lido['numero_dps']);
        $this->assertSame(270.00, $lido['valor']);
    }

    public function test_corrige_a_acentuacao_dupla_do_portal(): void
    {
        // O arquivo vem com "SÃ£o Pedro" e "ServiÃ§os". Guardar assim colocaria
        // lixo na tela e no PDF que vai ao cliente.
        $lido = $this->importar();

        $this->assertSame('São Pedro da Aldeia', $lido['municipio']);
        $this->assertSame('reparo em dispositivo móvel avariado', $lido['descricao_servico']);
        $this->assertStringContainsString('Serviços técnicos', (string) $lido['descricao_tributacao']);
        $this->assertStringNotContainsString('Ã', (string) $lido['descricao_tributacao']);
    }

    public function test_le_os_codigos_fiscais_do_servico(): void
    {
        $lido = $this->importar();

        $this->assertSame('310102', $lido['codigo_tributacao']);
        $this->assertSame('120018100', $lido['codigo_nbs']);
    }

    public function test_le_prestador_e_tomador(): void
    {
        $lido = $this->importar();

        $this->assertSame('34129526000198', $lido['prestador_documento']);
        $this->assertSame('72063654001309', $lido['tomador_documento']);
        $this->assertSame('ABRIGO DO MARINHEIRO', $lido['tomador_nome']);
    }

    public function test_recusa_nota_de_outro_cnpj(): void
    {
        // A checagem que impede anexar a nota de outro emitente a uma OS nossa.
        $this->cadastrarCnpj('11222333000181');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/outro CNPJ/');

        $this->importar();
    }

    public function test_aceita_quando_o_cnpj_da_empresa_confere(): void
    {
        $this->cadastrarCnpj('34129526000198');

        $this->assertSame('2', $this->importar()['numero']);
    }

    public function test_sem_cnpj_cadastrado_nao_bloqueia(): void
    {
        // Recusar aqui travaria quem ainda não terminou o cadastro da empresa.
        $this->assertSame('2', $this->importar()['numero']);
    }

    public function test_recusa_arquivo_que_nao_e_nfse(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/padrão nacional/');

        app(NfseXmlImporter::class)->ler('<?xml version="1.0"?><outraCoisa><a>1</a></outraCoisa>');
    }

    public function test_recusa_arquivo_que_nao_e_xml(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/XML válido/');

        app(NfseXmlImporter::class)->ler('isto nao e xml nenhum');
    }

    // ---- assinatura ----------------------------------------------------

    public function test_recusa_xml_sem_assinatura(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/assinatura digital/');

        app(NfseXmlImporter::class)->ler($this->fixture());
    }

    public function test_aceita_xml_sem_assinatura_quando_a_trava_esta_desligada(): void
    {
        // Existe para trabalhar com amostra sem assinatura — documentação,
        // XML de exemplo. Produção continua exigindo (é o padrão).
        config()->set('fiscal.nfse.exigir_assinatura_xml', false);

        $lido = app(NfseXmlImporter::class)->ler($this->fixture());

        $this->assertSame('2', $lido['numero']);
        $this->assertFalse($lido['assinatura_conferida']);
    }

    public function test_confere_a_assinatura_da_nota(): void
    {
        $lido = $this->importar();

        $this->assertTrue($lido['assinatura_conferida']);
        $this->assertNull($lido['assinatura_motivo']);
    }

    public function test_recusa_xml_assinado_e_depois_adulterado(): void
    {
        // Muda um digito do valor DEPOIS de assinado — o digest declarado na
        // assinatura passa a nao bater com o conteudo real.
        $assinado = $this->assinada();
        $adulterado = str_replace('<vServ>270.00</vServ>', '<vServ>999.00</vServ>', $assinado);

        $this->assertNotSame($assinado, $adulterado, 'a substituicao precisa ter encontrado o trecho');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/foi alterado depois de assinado/');

        app(NfseXmlImporter::class)->ler($adulterado);
    }

    public function test_recusa_chave_fora_do_tamanho(): void
    {
        config()->set('fiscal.nfse.exigir_assinatura_xml', false);

        $curta = str_replace(
            'Id="NFS33052082234129526000198000000000000226086919348703"',
            'Id="NFS123"',
            $this->fixture()
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/50 dígitos/');

        app(NfseXmlImporter::class)->ler($curta);
    }

    public function test_recusa_xml_com_doctype(): void
    {
        $comDoctype = "<?xml version=\"1.0\"?>\n<!DOCTYPE NFSe [<!ENTITY x \"y\">]>\n".$this->fixture();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/');

        app(NfseXmlImporter::class)->ler($comDoctype);
    }

    /**
     * @return array<string, mixed>
     */
    private function importar(): array
    {
        return app(NfseXmlImporter::class)->ler($this->assinada());
    }

    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse/nfse-real-mei.xml'));
    }

    /**
     * A fixture assinada sobre `infNFSe`, que é o elemento que o Ambiente
     * Nacional assina.
     *
     * Reusa o assinador de produção de propósito: se ele e o conferidor
     * divergirem — o defeito clássico de declarar um algoritmo e executar
     * outro —, este teste cai.
     */
    private function assinada(): string
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $this->instalarCertificadoDeTeste();

        return $cache = app(DpsXmlBuilder::class)->assinar($this->fixture(), 'infNFSe');
    }

    private function instalarCertificadoDeTeste(): void
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'JOVEM TECH:34129526000198', 'countryName' => 'BR'], $chave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $chave, 365, ['digest_alg' => 'sha256']);

        $pfx = '';
        openssl_pkcs12_export($x509, $pfx, $chave, 'segredo');

        $caminho = sys_get_temp_dir().'/nfse-importer-'.getmypid().'.pfx';
        file_put_contents($caminho, $pfx);

        config()->set('fiscal.certificado.pfx_path', $caminho);
        config()->set('fiscal.certificado.senha', 'segredo');
    }

    private function cadastrarCnpj(string $cnpj): void
    {
        DB::table('configuracoes')->insert([
            'chave' => 'empresa_cnpj',
            'valor' => $cnpj,
            'tipo' => 'texto',
        ]);
    }
}
