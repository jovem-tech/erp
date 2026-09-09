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
use Illuminate\Support\Facades\Log;
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
        // Faixa de aplicativo proprio (00001-49999). A 70000 do XML real
        // e' do Emissor Web e o ADN recusa por E0010 — ver o teste da faixa.
        config()->set('fiscal.nfse.serie', '00001');
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

        // Contrato real do ADN: `/dps/{id}` devolve a chave; o XML vem de
        // `/nfse/{chave}`. Fingir que o XML sai da primeira chamada mascarava
        // o defeito que deixou a duplicidade acontecer de verdade.
        Http::fake([
            '*/dps/*' => Http::response(['chaveAcesso' => 'CHAVE-JA-EMITIDA']),
            '*/nfse/CHAVE-JA-EMITIDA' => Http::response([
                'nfseXmlGZipB64' => base64_encode((string) gzencode($this->notaReal())),
            ]),
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
        $this->assertSame(0, $sequencia->atual('00001'));

        $this->servico()->emitir($this->rascunho());

        $this->assertSame(1, $sequencia->atual('00001'));
    }

    public function test_cadastro_fiscal_incompleto_falha_antes_de_queimar_o_numero_da_dps(): void
    {
        // Defeito real: sem o codigo IBGE, o builder estourava DEPOIS de o nDPS
        // ja' ter sido alocado e gravado — cada clique queimava um numero da
        // serie. E a excecao de la' e' `RuntimeException`, que escapava do
        // tratamento do controller e chegava na tela como erro 500 generico.
        Http::fake();
        DB::table('configuracoes')->where('chave', 'empresa_codigo_ibge')->delete();

        $documento = $this->rascunho();
        $sequencia = app(SequenciaDps::class);

        try {
            $this->servico()->emitir($documento);
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->origemLocal);
            // A mensagem precisa dizer O QUE falta e ONDE resolver.
            $this->assertStringContainsString('código IBGE', $e->getMessage());
            $this->assertStringContainsString('Configurações do Sistema', $e->getMessage());
        }

        $documento->refresh();

        // Nenhum numero queimado, nada transmitido.
        $this->assertNull($documento->numero_dps);
        $this->assertSame(0, $sequencia->atual('00001'));
        Http::assertNothingSent();
    }

    public function test_falha_do_builder_nao_escapa_como_erro_generico(): void
    {
        // Rede de seguranca para o que a guarda de cadastro nao previu: o
        // builder sinaliza problema com RuntimeException, e ela nao pode
        // atravessar o servico crua — viraria 500 na tela.
        Http::fake();
        DB::table('configuracoes')->where('chave', 'empresa_codigo_tributacao_nacional')
            ->update(['valor' => '']);

        $this->expectException(NfseException::class);

        $this->servico()->emitir($this->rascunho());
    }

    public function test_recusa_serie_da_faixa_do_portal_antes_de_transmitir(): void
    {
        // Defeito real: a serie 70000 foi copiada de uma NFS-e que a propria
        // empresa emitiu pelo portal. Mas a serie declara o TIPO DE EMISSOR —
        // 70000-79999 e' do Emissor Web, e emitir por API com ela devolve
        // rejeicao E0010 do ADN, depois de queimar um numero.
        Http::fake();
        config()->set('fiscal.nfse.serie', '70000');

        try {
            $this->servico()->emitir($this->rascunho());
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertTrue($e->origemLocal);
            $this->assertStringContainsString('portal do gov.br', $e->getMessage());
            $this->assertStringContainsString('00001 a 49999', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_aceita_serie_da_faixa_de_aplicativo_proprio(): void
    {
        $this->fingirAdn($this->notaReal());
        config()->set('fiscal.nfse.serie', '00001');

        $this->assertTrue($this->servico()->emitir($this->rascunho())->foiEmitido());
    }

    public function test_extrai_o_motivo_da_rejeicao_mesmo_com_chaves_maiusculas(): void
    {
        // O ADN devolve `Codigo`/`Descricao` capitalizados. Procurar so' a
        // forma minuscula descartava a mensagem util e deixava o operador com
        // um "HTTP 400" que nao diz o que corrigir.
        Http::fake([
            '*' => Http::response([
                'erros' => [[
                    'Codigo' => 'E0010',
                    'Descricao' => 'A serie informada na DPS nao pertence a faixa definida.',
                ]],
            ], 400),
        ]);

        $documento = $this->rascunho();

        try {
            $this->servico()->emitir($documento);
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            $this->assertStringContainsString('E0010', $e->getMessage());
            $this->assertStringContainsString('nao pertence a faixa', $e->getMessage());
        }

        $this->assertStringContainsString('E0010', (string) $documento->refresh()->motivo_rejeicao);
    }

    public function test_nota_autorizada_que_nao_registra_grita_no_log_e_salva_o_xml(): void
    {
        // O pior estado do sistema: o ADN autorizou, o registro local falhou.
        // Antes isso passava em silencio — `ValidationException` nao e' logada
        // pelo Laravel — e o XML da nota emitida se perdia.
        $xmlIntragavel = '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><naoEhNota/></NFSe>';
        $this->fingirAdn($xmlIntragavel);

        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();
        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $msg, array $ctx): bool => str_contains($msg, 'NAO REGISTRADA'));

        try {
            $this->servico()->emitir($this->rascunho());
            $this->fail('Deveria ter lancado NfseException.');
        } catch (NfseException $e) {
            // A mensagem precisa impedir o reflexo errado: clicar de novo.
            $this->assertStringContainsString('FOI EMITIDA', $e->getMessage());
            $this->assertStringContainsString('Não emita de novo', $e->getMessage());
        }
    }

    public function test_consulta_previa_busca_a_chave_e_depois_o_xml(): void
    {
        // Contrato real do ADN: `/dps/{id}` devolve so' a chave de acesso; o
        // XML vem de `/nfse/{chave}`. Tratar a primeira resposta como se
        // trouxesse o XML fazia a consulta falhar em silencio — e a protecao
        // contra duplicidade nao protegia nada.
        $documento = $this->rascunho();
        $documento->forceFill(['numero_dps' => 9])->save();

        Http::fake([
            '*/dps/*' => Http::response(['chaveAcesso' => 'CHAVE123']),
            '*/nfse/CHAVE123' => Http::response([
                'nfseXmlGZipB64' => base64_encode((string) gzencode($this->notaReal())),
            ]),
        ]);

        $emitido = $this->servico()->emitir($documento);

        $this->assertTrue($emitido->foiEmitido());
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/nfse/CHAVE123'));
        // Nada de POST: a nota ja' existia.
        Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
    }

    public function test_e0014_recupera_a_nota_em_vez_de_marcar_rejeitado(): void
    {
        // "DPS ja existe" nao e' rejeicao: e' o ADN dizendo que a nota existe
        // do lado dele. Marcar o documento como rejeitado registraria o oposto
        // da verdade e deixaria uma nota fiscal emitida sem contrapartida.
        $documento = $this->rascunho();
        $documento->forceFill(['numero_dps' => 9])->save();

        $xml = $this->notaReal();
        $consultasDps = 0;

        Http::fake(function (Request $request) use ($xml, &$consultasDps) {
            $url = $request->url();

            if (str_contains($url, '/dps/')) {
                $consultasDps++;

                // A primeira consulta nao acha — foi o que deixou o envio
                // seguir e colher o E0014 no caso real. Depois da recusa, a
                // recuperacao pergunta de novo e o ADN entrega a chave.
                return $consultasDps === 1
                    ? Http::response([], 404)
                    : Http::response(['chaveAcesso' => 'CHAVE999']);
            }

            if (str_contains($url, '/nfse/CHAVE999')) {
                return Http::response(['nfseXmlGZipB64' => base64_encode((string) gzencode($xml))]);
            }

            return Http::response([
                'erros' => [[
                    'Codigo' => 'E0014',
                    'Descricao' => 'Conjunto de Serie, Numero, Codigo do Municipio Emissor e '
                        .'CNPJ/CPF informado nesta DPS ja existe em uma NFS-e gerada anteriormente.',
                ]],
            ], 400);
        });

        $emitido = $this->servico()->emitir($documento);

        $this->assertTrue($emitido->foiEmitido());
        $this->assertNotSame(DocumentoFiscal::STATUS_REJEITADO, $emitido->status);
        $this->assertSame(2, $consultasDps, 'A recuperacao precisa consultar de novo apos o E0014.');
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
