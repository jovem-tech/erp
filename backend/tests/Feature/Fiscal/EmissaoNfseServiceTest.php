<?php

namespace Tests\Feature\Fiscal;

use App\Models\DocumentoFiscal;
use App\Services\Fiscal\EmissaoNfseService;
use App\Services\Fiscal\NfseException;
use App\Services\Fiscal\SequenciaDps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesFiscalTestCertificate;
use Tests\TestCase;

/**
 * Emissao automatica ponta a ponta (com o ADN fingido).
 *
 * O foco destes testes nao e' o caminho feliz — e' a protecao contra emitir
 * DUAS notas para o mesmo servico, que e' o unico erro desta integracao que
 * nao se desfaz sozinho.
 */
class EmissaoNfseServiceTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesFiscalTestCertificate;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Storage::fake('local');

        $this->instalarCertificadoFiscalDeTeste('JOVEM TECH:34129526000198');

        // A fixture real teve a assinatura removida (ver ORIGEM.md), entao a
        // trava fica desligada aqui — como no NfseXmlImporterTest.
        config()->set('fiscal.nfse.exigir_assinatura_xml', false);
        config()->set('fiscal.nfse.serie', '70000');
        config()->set('fiscal.nfse.ambiente', 2);
        config()->set('fiscal.nfse.transmissao.urls', [2 => 'https://homologacao.example/SefinNacional']);

        foreach ([
            'empresa_cnpj' => '34129526000198',
            'empresa_codigo_ibge' => '3305208',
            'empresa_codigo_tributacao_nacional' => '140201',
        ] as $chave => $valor) {
            DB::table('configuracoes')->insert(['chave' => $chave, 'valor' => $valor, 'tipo' => 'texto']);
        }
    }

    protected function tearDown(): void
    {
        $this->limparCertificadoFiscalDeTeste();

        parent::tearDown();
    }

    public function test_emite_registra_a_nota_e_guarda_o_xml(): void
    {
        $this->fingirAdn($this->notaReal());

        $documento = $this->servico()->emitir($this->rascunho());

        $this->assertTrue($documento->foiEmitido());
        $this->assertNotEmpty($documento->numero);
        $this->assertNotEmpty($documento->chave);
        // O XML e' a guarda que a lei pede — tem de ficar gravado.
        $this->assertNotEmpty($documento->xml_arquivo);
    }

    public function test_grava_o_numero_da_dps_antes_de_transmitir(): void
    {
        // Se o processo morresse durante o POST, e' esta gravacao que permite a
        // proxima tentativa descobrir o que consultar.
        Http::fake(['*' => Http::response(['mensagem' => 'fora do ar'], 503)]);

        $documento = $this->rascunho();

        try {
            $this->servico()->emitir($documento);
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->ehFalhaTemporaria());
        }

        $documento->refresh();

        $this->assertSame(1, (int) $documento->numero_dps);
        // Falha de transporte NAO e' rejeicao: a nota pode existir do outro
        // lado, e "rejeitado" apagaria a pista de que ha' o que consultar.
        $this->assertSame(DocumentoFiscal::STATUS_RASCUNHO, $documento->status);
        $this->assertNull($documento->motivo_rejeicao);
    }

    public function test_retentativa_consulta_antes_de_reenviar_e_nao_emite_duas_vezes(): void
    {
        // Cenario perigoso: o ADN processou, a resposta se perdeu. A segunda
        // tentativa NAO pode postar de novo.
        $documento = $this->rascunho();
        $documento->forceFill(['numero_dps' => 7])->save();

        Http::fake([
            '*/dps/*' => Http::response(['nfseXmlGZipB64' => base64_encode((string) gzencode($this->notaReal()))]),
        ]);

        $emitido = $this->servico()->emitir($documento);

        $this->assertTrue($emitido->foiEmitido());

        // Nenhum POST: a nota ja existia e foi so' registrada.
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET');
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_retentativa_reusa_o_numero_da_dps_em_vez_de_queimar_outro(): void
    {
        $documento = $this->rascunho();
        $documento->forceFill(['numero_dps' => 7])->save();

        // Consulta diz "nao existe"; entao segue para o envio — com o MESMO 7.
        Http::fake([
            '*/dps/*' => Http::response([], 404),
            '*' => Http::response(['nfseXmlGZipB64' => base64_encode((string) gzencode($this->notaReal()))]),
        ]);

        $this->servico()->emitir($documento);

        Http::assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST') {
                return false;
            }

            $dps = gzdecode((string) base64_decode((string) ($r->data()['dpsXmlGZipB64'] ?? ''), true));
            $this->assertStringContainsString('<nDPS>7</nDPS>', (string) $dps);

            return true;
        });
    }

    public function test_rejeicao_do_adn_fica_registrada_no_documento(): void
    {
        Http::fake([
            '*' => Http::response([
                'erros' => [['codigo' => 'E0042', 'descricao' => 'Competencia encerrada']],
            ], 422),
        ]);

        $documento = $this->rascunho();

        try {
            $this->servico()->emitir($documento);
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->ehRejeicaoFiscal());
        }

        $documento->refresh();

        // O operador precisa ver o motivo na tela da nota, nao num flash que
        // some no proximo clique.
        $this->assertSame(DocumentoFiscal::STATUS_REJEITADO, $documento->status);
        $this->assertStringContainsString('Competencia encerrada', (string) $documento->motivo_rejeicao);
    }

    public function test_emite_para_cliente_nao_identificado(): void
    {
        // O caso que motivou a integracao: balcao, cliente sem CPF.
        $this->fingirAdn($this->notaReal());

        $documento = $this->rascunho(['tomador_documento' => null, 'tomador_nome' => 'Consumidor']);

        $emitido = $this->servico()->emitir($documento);

        $this->assertTrue($emitido->foiEmitido());

        Http::assertSent(function (Request $r): bool {
            if ($r->method() !== 'POST') {
                return false;
            }

            $dps = (string) gzdecode((string) base64_decode((string) ($r->data()['dpsXmlGZipB64'] ?? ''), true));
            $this->assertStringNotContainsString('<toma>', $dps);

            return true;
        });
    }

    public function test_recusa_emitir_documento_ja_emitido(): void
    {
        Http::fake();

        $documento = $this->rascunho();
        $documento->forceFill(['status' => DocumentoFiscal::STATUS_EMITIDO, 'numero' => '5'])->save();

        $this->expectException(ValidationException::class);

        try {
            $this->servico()->emitir($documento);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sem_certificado_nao_chega_a_transmitir(): void
    {
        Http::fake();
        $this->desinstalarCertificadoFiscalDeTeste();

        try {
            $this->servico()->emitir($this->rascunho());
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->origemLocal);
            $this->assertStringContainsString('Certificado A1', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_sequencia_avanca_a_cada_emissao(): void
    {
        $this->fingirAdn($this->notaReal());

        $sequencia = app(SequenciaDps::class);
        $this->assertSame(0, $sequencia->atual('70000'));

        $this->servico()->emitir($this->rascunho());

        $this->assertSame(1, $sequencia->atual('70000'));
    }

    private function fingirAdn(string $xmlDaNota): void
    {
        Http::fake([
            '*' => Http::response([
                'nfseXmlGZipB64' => base64_encode((string) gzencode($xmlDaNota)),
            ]),
        ]);
    }

    private function notaReal(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/nfse/nfse-real-mei.xml'));
    }

    /**
     * @param  array<string, mixed>  $sobrescreve
     */
    private function rascunho(array $sobrescreve = []): DocumentoFiscal
    {
        $documento = new DocumentoFiscal(array_replace([
            'tipo' => DocumentoFiscal::TIPO_NFSE,
            'status' => DocumentoFiscal::STATUS_RASCUNHO,
            'tomador_nome' => 'Cliente Fiscal',
            // Bate com o tomador da fixture: `conferirTomador()` recusa
            // registrar uma nota cujo tomador nao e' o cliente da OS.
            'tomador_documento' => '72063654001309',
            'discriminacao' => 'Ordem de servico OS26090003',
            'valor_servicos' => 360.00,
            'valor_pecas' => 0.00,
            'valor_total' => 360.00,
        ], $sobrescreve));

        $documento->save();

        return $documento;
    }

    private function servico(): EmissaoNfseService
    {
        return app(EmissaoNfseService::class);
    }
}
