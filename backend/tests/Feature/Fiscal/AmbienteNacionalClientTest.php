<?php

namespace Tests\Feature\Fiscal;

use App\Services\Fiscal\AmbienteNacionalClient;
use App\Services\Fiscal\NfseException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GeneratesFiscalTestCertificate;
use Tests\TestCase;

/**
 * Transmissao ao Ambiente Nacional.
 *
 * ⚠️ O que este teste NAO prova: que o mTLS funciona. `Http::fake()` intercepta
 * antes do Guzzle, entao as opcoes de transporte nunca chegam ao cURL — afirmar
 * aqui que o certificado foi apresentado seria teatro. Mesma limitacao, e mesma
 * honestidade, do `InterClientTest`. O que da' para provar de verdade e' a
 * forma do corpo, o tratamento de erro e — o mais importante — que o POST de
 * emissao NAO repete sozinho.
 */
class AmbienteNacionalClientTest extends TestCase
{
    use GeneratesFiscalTestCertificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instalarCertificadoFiscalDeTeste();
        config()->set('fiscal.nfse.ambiente', 2);
        config()->set('fiscal.nfse.transmissao.urls', [
            1 => 'https://sefin.example/SefinNacional',
            2 => 'https://homologacao.example/SefinNacional',
        ]);
    }

    protected function tearDown(): void
    {
        $this->limparCertificadoFiscalDeTeste();

        parent::tearDown();
    }

    public function test_envia_a_dps_comprimida_e_devolve_o_xml_da_nota(): void
    {
        Http::fake([
            '*' => Http::response([
                'nfseXmlGZipB64' => base64_encode((string) gzencode('<NFSe>nota</NFSe>')),
                'chaveAcesso' => 'nota',
            ]),
        ]);

        $xml = $this->cliente()->emitir('<DPS>assinada</DPS>');

        $this->assertSame('<NFSe>nota</NFSe>', $xml);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('https://homologacao.example/SefinNacional/nfse', $request->url());
            $this->assertSame('POST', $request->method());

            // O ADN recebe a DPS comprimida em gzip e codificada em base64.
            $enviado = $request->data()['dpsXmlGZipB64'] ?? '';
            $this->assertSame('<DPS>assinada</DPS>', gzdecode((string) base64_decode((string) $enviado, true)));

            return true;
        });
    }

    public function test_o_post_de_emissao_nao_repete_sozinho(): void
    {
        // Este teste guarda a decisao mais importante da integracao: repetir um
        // POST que talvez tenha sido processado emite a nota duas vezes.
        Http::fake(['*' => Http::response(['mensagem' => 'indisponivel'], 503)]);

        try {
            $this->cliente()->emitir('<DPS/>');
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->ehFalhaTemporaria());
            $this->assertFalse($e->ehRejeicaoFiscal());
        }

        Http::assertSentCount(1);
    }

    public function test_rejeicao_do_adn_vira_excecao_com_o_motivo_legivel(): void
    {
        Http::fake([
            '*' => Http::response([
                'erros' => [
                    ['codigo' => 'E0042', 'descricao' => 'Serie ja utilizada para o numero informado'],
                ],
            ], 422),
        ]);

        try {
            $this->cliente()->emitir('<DPS/>');
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertStringContainsString('E0042', $e->getMessage());
            $this->assertStringContainsString('Serie ja utilizada', $e->getMessage());
            // 422 e' "seu dado esta errado": vira rejeicao no documento.
            $this->assertTrue($e->ehRejeicaoFiscal());
            $this->assertFalse($e->ehFalhaTemporaria());
        }
    }

    public function test_429_e_tratado_como_temporario_e_nao_como_rejeicao(): void
    {
        // 4xx normalmente e' culpa nossa, mas 429 significa "volte depois" —
        // marcar o documento como rejeitado ai seria mentira.
        Http::fake(['*' => Http::response(['mensagem' => 'calma'], 429)]);

        try {
            $this->cliente()->emitir('<DPS/>');
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->ehFalhaTemporaria());
            $this->assertFalse($e->ehRejeicaoFiscal());
        }
    }

    public function test_consulta_devolve_null_quando_a_dps_nao_virou_nota(): void
    {
        Http::fake(['*' => Http::response(['mensagem' => 'nao encontrado'], 404)]);

        $this->assertNull($this->cliente()->consultarPorIdDps('DPS123'));
    }

    public function test_consulta_devolve_o_xml_quando_a_nota_ja_existe(): void
    {
        Http::fake([
            '*' => Http::response([
                'nfseXmlGZipB64' => base64_encode((string) gzencode('<NFSe>ja existia</NFSe>')),
            ]),
        ]);

        $xml = $this->cliente()->consultarPorIdDps('DPS123');

        $this->assertSame('<NFSe>ja existia</NFSe>', $xml);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/dps/DPS123'));
    }

    public function test_sem_certificado_falha_antes_de_tocar_a_rede(): void
    {
        Http::fake();
        $this->desinstalarCertificadoFiscalDeTeste();

        try {
            $this->cliente()->emitir('<DPS/>');
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->origemLocal);
        }

        // Nada de mandar DPS as cegas sem credencial.
        Http::assertNothingSent();
    }

    public function test_resposta_sem_o_campo_esperado_nao_passa_por_nota_valida(): void
    {
        Http::fake(['*' => Http::response(['algoOutro' => 'x'])]);

        $this->expectException(NfseException::class);
        $this->expectExceptionMessageMatches('/formato inesperado/');

        $this->cliente()->emitir('<DPS/>');
    }

    public function test_aceita_xml_sem_compressao_se_o_adn_devolver_assim(): void
    {
        // Tolerancia deliberada: o contrato foi conferido sem acesso ao Swagger
        // autenticado, e falhar por causa de um gzip ausente seria perder uma
        // nota ja' emitida por preciosismo de formato.
        Http::fake([
            '*' => Http::response(['nfseXmlGZipB64' => base64_encode('<NFSe>cru</NFSe>')]),
        ]);

        $this->assertSame('<NFSe>cru</NFSe>', $this->cliente()->emitir('<DPS/>'));
    }

    public function test_nao_deixa_a_chave_privada_para_tras_no_disco(): void
    {
        Http::fake([
            '*' => Http::response(['nfseXmlGZipB64' => base64_encode((string) gzencode('<NFSe/>'))]),
        ]);

        $this->cliente()->emitir('<DPS/>');

        // O material mTLS e' gravado em disco porque o cURL exige arquivo — mas
        // some assim que a chamada termina.
        $this->assertSame([], glob(storage_path('framework/cache/nfse-mtls-*.pem')) ?: []);
    }

    private function cliente(): AmbienteNacionalClient
    {
        return app(AmbienteNacionalClient::class);
    }
}
