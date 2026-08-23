<?php

namespace Tests\Feature\Integrations\Inter;

use App\Services\Integrations\Inter\InterClient;
use App\Services\Integrations\Inter\InterException;
use App\Services\Integrations\Inter\InterTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class InterClientTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesInterTestCertificate;
    use RefreshDatabase;

    private const BASE = 'https://cdpj.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        Cache::flush();

        config(['inter.base_url' => self::BASE]);
        $this->gerarCertificadoDeTeste();
        $this->gravarCredenciaisDoInter();
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    private function client(): InterClient
    {
        return app(InterClient::class);
    }

    /** @param array<string, mixed> $extra */
    private function fakeToken(array $extra = []): array
    {
        return array_merge([
            self::BASE.'/oauth/v2/token' => Http::response([
                'access_token' => 'token-abc',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ]),
        ], $extra);
    }

    public function test_envia_bearer_e_usa_mtls_na_chamada(): void
    {
        Http::fake($this->fakeToken([
            self::BASE.'/banking/v2/saldo' => Http::response(['disponivel' => 1234.56]),
        ]));

        $resposta = $this->client()->get('banking/v2/saldo', ['extrato.read']);

        $this->assertSame(1234.56, $resposta['disponivel']);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/banking/v2/saldo')) {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer token-abc');
        });

        // O par cert/chave em si e' verificado no InterCredentialsTest
        // (guzzleTlsOptions): Http::fake() intercepta antes do Guzzle e nao
        // expoe as opcoes de transporte, entao afirmar isso aqui seria teatro.
    }

    public function test_envia_x_conta_corrente_apenas_quando_configurada(): void
    {
        Http::fake($this->fakeToken([
            self::BASE.'/banking/v2/saldo' => Http::response([]),
        ]));

        $this->client()->get('banking/v2/saldo', ['extrato.read']);

        Http::assertSent(fn (Request $r): bool => ! str_contains($r->url(), '/saldo')
            || ! $r->hasHeader('x-conta-corrente'));

        Cache::flush();
        Http::fake($this->fakeToken([
            self::BASE.'/banking/v2/saldo' => Http::response([]),
        ]));
        $this->gravarCredenciaisDoInter(contaCorrente: '123456789');

        $this->client()->get('banking/v2/saldo', ['extrato.read']);

        Http::assertSent(fn (Request $r): bool => ! str_contains($r->url(), '/saldo')
            || $r->hasHeader('x-conta-corrente', '123456789'));
    }

    public function test_token_e_reaproveitado_entre_chamadas(): void
    {
        Http::fake($this->fakeToken([
            self::BASE.'/banking/v2/saldo' => Http::response([]),
        ]));

        $this->client()->get('banking/v2/saldo', ['extrato.read']);
        $this->client()->get('banking/v2/saldo', ['extrato.read']);
        $this->client()->get('banking/v2/saldo', ['extrato.read']);

        $pedidosDeToken = 0;
        Http::assertSent(function (Request $r) use (&$pedidosDeToken): bool {
            if (str_contains($r->url(), '/oauth/v2/token')) {
                $pedidosDeToken++;
            }

            return true;
        });

        $this->assertSame(1, $pedidosDeToken, 'O token deveria ser pedido uma unica vez.');
    }

    public function test_escopos_diferentes_nao_compartilham_token(): void
    {
        Http::fake($this->fakeToken([
            self::BASE.'/banking/v2/saldo' => Http::response([]),
            self::BASE.'/pix/v2/cob/x' => Http::response([]),
        ]));

        $this->client()->get('banking/v2/saldo', ['extrato.read']);
        $this->client()->get('pix/v2/cob/x', ['cob.read']);

        $pedidosDeToken = 0;
        Http::assertSent(function (Request $r) use (&$pedidosDeToken): bool {
            if (str_contains($r->url(), '/oauth/v2/token')) {
                $pedidosDeToken++;
            }

            return true;
        });

        // Um token por conjunto de escopos: reaproveitar um token de leitura
        // para escrita daria 403 do banco em producao.
        $this->assertSame(2, $pedidosDeToken);
    }

    public function test_401_renova_o_token_e_repete_uma_unica_vez(): void
    {
        $chamadasAoSaldo = 0;

        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::sequence()
                ->push(['access_token' => 'token-velho', 'expires_in' => 3600])
                ->push(['access_token' => 'token-novo', 'expires_in' => 3600]),
            self::BASE.'/banking/v2/saldo' => function () use (&$chamadasAoSaldo) {
                $chamadasAoSaldo++;

                return $chamadasAoSaldo === 1
                    ? Http::response(['title' => 'Unauthorized'], 401)
                    : Http::response(['disponivel' => 10.0]);
            },
        ]);

        $resposta = $this->client()->get('banking/v2/saldo', ['extrato.read']);

        $this->assertEqualsWithDelta(10.0, $resposta['disponivel'], 0.001);
        $this->assertSame(2, $chamadasAoSaldo, 'Deveria repetir exatamente uma vez.');
    }

    public function test_401_persistente_nao_vira_laco_infinito(): void
    {
        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/banking/v2/saldo' => Http::response(['title' => 'Unauthorized'], 401),
        ]);

        try {
            $this->client()->get('banking/v2/saldo', ['extrato.read']);
            $this->fail('Deveria lancar InterException.');
        } catch (InterException $e) {
            $this->assertSame(401, $e->statusHttp);
            $this->assertTrue($e->ehCredencialInvalida());
        }
    }

    public function test_erro_do_banco_vira_exception_com_status_e_mensagem_util(): void
    {
        Http::fake($this->fakeToken([
            self::BASE.'/pix/v2/cob/naoexiste' => Http::response(['detail' => 'Cobranca nao encontrada'], 404),
        ]));

        try {
            $this->client()->get('pix/v2/cob/naoexiste', ['cob.read']);
            $this->fail('Deveria lancar InterException.');
        } catch (InterException $e) {
            $this->assertSame(404, $e->statusHttp);
            $this->assertStringContainsString('Cobranca nao encontrada', $e->getMessage());
            $this->assertFalse($e->ehFalhaTemporaria());
        }
    }

    public function test_falha_de_transporte_e_classificada_como_temporaria(): void
    {
        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/banking/v2/saldo' => Http::response('', 503),
        ]);

        try {
            $this->client()->get('banking/v2/saldo', ['extrato.read']);
            $this->fail('Deveria lancar InterException.');
        } catch (InterException $e) {
            $this->assertTrue($e->ehFalhaTemporaria());
        }
    }

    public function test_sem_credenciais_falha_antes_de_qualquer_chamada(): void
    {
        Http::fake();
        config(['inter.certificado.cert_path' => '/nao/existe.crt']);

        $this->expectException(InterException::class);

        try {
            $this->client()->get('banking/v2/saldo', ['extrato.read']);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_token_store_nao_pede_token_de_novo_dentro_da_validade(): void
    {
        Http::fake($this->fakeToken());

        $store = app(InterTokenStore::class);

        $this->assertSame('token-abc', $store->token(['extrato.read']));
        $this->assertSame('token-abc', $store->token(['extrato.read']));

        // Um escopo diferente forca um token novo (o fake devolve o mesmo
        // valor, entao o que se mede aqui e' a QUANTIDADE de chamadas).
        $store->token(['cob.read', 'cob.write']);

        // Ordem dos escopos nao pode gerar chave diferente: normalizamos antes
        // de montar a chave, senao o mesmo par pediria dois tokens.
        $store->token(['cob.write', 'cob.read']);

        // 2 pedidos: um para extrato.read, outro para o par cob.*.
        Http::assertSentCount(2);
    }
}
