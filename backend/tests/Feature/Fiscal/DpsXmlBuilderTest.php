<?php

namespace Tests\Feature\Fiscal;

use App\Models\DocumentoFiscal;
use App\Services\Company\CompanyProfileService;
use App\Services\Fiscal\CertificadoA1;
use App\Services\Fiscal\DpsXmlBuilder;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * DPS do padrão nacional: montagem e assinatura.
 *
 * A assinatura é verificada de verdade — canonicaliza o SignedInfo e confere
 * com a chave pública do próprio certificado. É o que dá para provar sem ter
 * certificado ICP-Brasil e sem acesso a homologação.
 *
 * O layout é validado contra o XSD oficial v1.01 (versionado em
 * tests/Fixtures/nfse-schemas). Foi assim que apareceram quatro erros que a
 * leitura da documentação não pegou: `regEspTrib` faltando, `locPrest` antes de
 * `cServ`, e `trib` obrigatório dentro de `valores`.
 *
 * ⚠️ O que ainda NÃO está provado: que o ADN aceita a transmissão. Schema
 * válido é condição necessária, não suficiente — regra de negócio do ambiente
 * nacional (série já usada, competência fechada, código de tributação
 * incompatível com o CNAE) só aparece na primeira chamada real.
 */
class DpsXmlBuilderTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    private string $diretorio = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();

        $this->diretorio = sys_get_temp_dir().'/dps-'.bin2hex(random_bytes(6));
        mkdir($this->diretorio, 0700, true);

        $this->cadastrarEmpresa();
        $this->instalarCertificado();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->diretorio.'/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->diretorio);

        parent::tearDown();
    }

    public function test_monta_a_dps_com_prestador_tomador_e_valor_do_servico(): void
    {
        $xml = $this->builder()->montar($this->documento(), 1);

        $this->assertStringContainsString('<infDPS', $xml);
        $this->assertStringContainsString('<CNPJ>11222333000181</CNPJ>', $xml);
        $this->assertStringContainsString('<CPF>52998224725</CPF>', $xml);
        $this->assertStringContainsString('<xNome>Cliente Fiscal</xNome>', $xml);
        $this->assertStringContainsString('<vServ>300.00</vServ>', $xml);
        // Ambiente nasce em homologacao: emitir em producao por engano gera
        // documento fiscal de verdade.
        $this->assertStringContainsString('<tpAmb>2</tpAmb>', $xml);
        // Peca nao entra na NFS-e — o valor de peca (120) nao pode aparecer.
        $this->assertStringNotContainsString('120.00', $xml);
    }

    public function test_a_assinatura_confere_com_a_chave_publica_do_certificado(): void
    {
        $xml = $this->builder()->gerarAssinado($this->documento(), 1);

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $this->assertTrue($dom->loadXML($xml));

        $signedInfo = $dom->getElementsByTagName('SignedInfo')->item(0);
        $this->assertInstanceOf(DOMElement::class, $signedInfo);

        $assinatura = base64_decode((string) $dom->getElementsByTagName('SignatureValue')->item(0)?->nodeValue, true);
        $this->assertNotFalse($assinatura);

        $certificadoBase64 = (string) $dom->getElementsByTagName('X509Certificate')->item(0)?->nodeValue;
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split($certificadoBase64, 64, "\n")."-----END CERTIFICATE-----\n";
        $chavePublica = openssl_pkey_get_public($pem);
        $this->assertNotFalse($chavePublica, 'certificado embutido na assinatura nao e legivel');

        // Canonicaliza segundo o algoritmo DECLARADO no proprio XML, e nao
        // repetindo a chamada do codigo de producao. E' o unico jeito de pegar
        // o defeito que existia aqui: declarar c14n inclusiva e executar
        // exclusiva. Um teste que repete a chamada e' consistente consigo
        // mesmo e passa com a assinatura errada.
        $this->assertSame(
            1,
            openssl_verify(
                $this->canonicalizarComoDeclarado($dom, $signedInfo),
                $assinatura,
                $chavePublica,
                $this->algoritmoDeAssinaturaDeclarado($dom)
            ),
            'assinatura da DPS nao confere com a chave publica do certificado'
        );
    }

    public function test_declara_sha256_e_c14n_exclusiva_como_o_ambiente_nacional(): void
    {
        // Valores conferidos num XML real devolvido pelo ADN.
        $xml = $this->builder()->gerarAssinado($this->documento(), 1);

        $this->assertStringContainsString('http://www.w3.org/2001/10/xml-exc-c14n#WithComments', $xml);
        $this->assertStringContainsString('http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', $xml);
        $this->assertStringContainsString('http://www.w3.org/2001/04/xmlenc#sha256', $xml);
        $this->assertStringNotContainsString('rsa-sha1', $xml);
    }

    public function test_o_digest_corresponde_ao_conteudo_assinado(): void
    {
        // Sem isto a assinatura poderia ser valida sobre um digest de outro
        // documento — assinatura correta de conteudo errado.
        $xml = $this->builder()->gerarAssinado($this->documento(), 1);

        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->loadXML($xml);

        $declarado = (string) $dom->getElementsByTagName('DigestValue')->item(0)?->nodeValue;

        $inf = $dom->getElementsByTagName('infDPS')->item(0);
        $this->assertInstanceOf(DOMElement::class, $inf);

        $recalculado = base64_encode(hash(
            $this->algoritmoDeDigestDeclarado($dom),
            $this->canonicalizarComoDeclarado($dom, $inf),
            true
        ));

        $this->assertSame($declarado, $recalculado);
    }

    /**
     * Canonicaliza lendo o `CanonicalizationMethod` do proprio documento.
     */
    private function canonicalizarComoDeclarado(DOMDocument $dom, DOMElement $elemento): string
    {
        $algoritmo = (string) $dom->getElementsByTagName('CanonicalizationMethod')->item(0)?->getAttribute('Algorithm');

        return $elemento->C14N(
            str_starts_with($algoritmo, 'http://www.w3.org/2001/10/xml-exc-c14n'),
            str_ends_with($algoritmo, '#WithComments')
        );
    }

    private function algoritmoDeAssinaturaDeclarado(DOMDocument $dom): int
    {
        $algoritmo = (string) $dom->getElementsByTagName('SignatureMethod')->item(0)?->getAttribute('Algorithm');

        return match (true) {
            str_ends_with($algoritmo, 'rsa-sha256') => OPENSSL_ALGO_SHA256,
            str_ends_with($algoritmo, 'rsa-sha512') => OPENSSL_ALGO_SHA512,
            str_ends_with($algoritmo, 'rsa-sha1') => OPENSSL_ALGO_SHA1,
            default => $this->fail('SignatureMethod desconhecido: '.$algoritmo),
        };
    }

    private function algoritmoDeDigestDeclarado(DOMDocument $dom): string
    {
        $algoritmo = (string) $dom->getElementsByTagName('DigestMethod')->item(0)?->getAttribute('Algorithm');

        return match (true) {
            str_ends_with($algoritmo, '#sha256') => 'sha256',
            str_ends_with($algoritmo, '#sha512') => 'sha512',
            str_ends_with($algoritmo, '#sha1') => 'sha1',
            default => $this->fail('DigestMethod desconhecido: '.$algoritmo),
        };
    }

    public function test_valida_contra_o_xsd_oficial_da_nfse_nacional(): void
    {
        // Esquemas v1.01 versionados em tests/Fixtures/nfse-schemas (ver
        // ORIGEM.md). Validar contra o schema real é o que separa "layout que
        // parece certo" de "layout que o ADN aceita".
        $xml = $this->builder()->gerarAssinado($this->documento(), 1);

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $valido = $dom->schemaValidate(base_path('tests/Fixtures/nfse-schemas/DPS_v1.01.xsd'));

        $erros = array_map(
            static fn (\LibXMLError $erro): string => trim($erro->message),
            libxml_get_errors()
        );
        libxml_clear_errors();

        if ($valido) {
            $this->assertSame([], $erros);

            return;
        }

        // Tolera EXATAMENTE um erro conhecido: o pattern de `serie` no pacote
        // oficial é insatisfazível (em XSD 1.0 `^` e `$` são literais, e com
        // maxLength=5 só a string "^1$" passa). Qualquer outro erro reprova.
        $this->assertCount(1, $erros, "Erros inesperados na validação:\n".implode("\n", $erros));
        $this->assertStringContainsString('serie', $erros[0]);
        $this->assertStringContainsString('pattern', $erros[0]);
    }

    public function test_emite_cnbs_quando_configurado_e_valida_no_xsd(): void
    {
        // <cNBS> e' opcional, mas tem posicao fixa na sequencia de <cServ>.
        // O XSD acusa se sair fora de ordem.
        config()->set('fiscal.nfse.cnbs', '120018100');

        $xml = $this->builder()->montar($this->documento(), 1);

        $this->assertStringContainsString('<cNBS>120018100</cNBS>', $xml);

        libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $dom->schemaValidate(base_path('tests/Fixtures/nfse-schemas/DPS_v1.01.xsd'));

        $erros = array_filter(
            array_map(static fn (\LibXMLError $e): string => trim($e->message), libxml_get_errors()),
            // O unico erro tolerado e' o pattern insatisfazivel de `serie`.
            static fn (string $erro): bool => ! str_contains($erro, 'serie'),
        );
        libxml_clear_errors();

        $this->assertSame([], array_values($erros));
    }

    public function test_declara_regime_do_mei_como_dois(): void
    {
        // XML real de NFS-e MEI traz opSimpNac = 2. O padrao anterior era 1.
        $xml = $this->builder()->montar($this->documento(), 1);

        $this->assertStringContainsString('<opSimpNac>2</opSimpNac>', $xml);
    }

    public function test_recusa_emitir_sem_documento_do_tomador(): void
    {
        // A NFS-e exige identificar o tomador. Falhar aqui, com mensagem
        // clara, e' melhor do que ser rejeitado pelo ADN depois.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Tomador sem CPF/');

        $this->builder()->montar($this->documento(['tomador_documento' => null]), 1);
    }

    public function test_recusa_emitir_sem_codigo_ibge_da_empresa(): void
    {
        DB::table('configuracoes')->where('chave', 'empresa_codigo_ibge')->update(['valor' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IBGE/');

        $this->builder()->montar($this->documento(), 1);
    }

    public function test_recusa_emitir_sem_codigo_de_tributacao_do_servico(): void
    {
        DB::table('configuracoes')->where('chave', 'empresa_codigo_tributacao_nacional')->update(['valor' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/contador/');

        $this->builder()->montar($this->documento(), 1);
    }

    private function builder(): DpsXmlBuilder
    {
        return new DpsXmlBuilder(new CertificadoA1(), app(CompanyProfileService::class));
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     */
    private function documento(array $sobrescreve = []): DocumentoFiscal
    {
        return new DocumentoFiscal(array_replace([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_RASCUNHO,
            'tomador_nome' => 'Cliente Fiscal',
            'tomador_documento' => '52998224725',
            'discriminacao' => "Ordem de servico OS2609001\nTroca de tela",
            'valor_servicos' => 300.00,
            'valor_pecas' => 120.00,
            'valor_total' => 420.00,
        ], $sobrescreve));
    }

    private function cadastrarEmpresa(): void
    {
        foreach ([
            'empresa_cnpj' => '11222333000181',
            'empresa_codigo_ibge' => '3550308',
            'empresa_inscricao_municipal' => '123456',
            'empresa_codigo_tributacao_nacional' => '010701',
        ] as $chave => $valor) {
            DB::table('configuracoes')->insert(['chave' => $chave, 'valor' => $valor, 'tipo' => 'texto']);
        }
    }

    private function instalarCertificado(): void
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'JOVEM TECH:11222333000181', 'countryName' => 'BR'], $chave, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $chave, 365, ['digest_alg' => 'sha256']);

        $pfx = '';
        openssl_pkcs12_export($x509, $pfx, $chave, 'segredo');

        $caminho = $this->diretorio.'/certificado.pfx';
        file_put_contents($caminho, $pfx);

        config()->set('fiscal.certificado.pfx_path', $caminho);
        config()->set('fiscal.certificado.senha', 'segredo');
    }
}
