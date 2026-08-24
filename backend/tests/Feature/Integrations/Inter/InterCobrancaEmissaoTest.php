<?php

namespace Tests\Feature\Integrations\Inter;

use App\Models\Financeiro;
use App\Models\Inter\InterCobranca;
use App\Models\Inter\InterEvento;
use App\Services\Integrations\Inter\InterCobrancaService;
use App\Services\Integrations\PaymentIntegrationSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class InterCobrancaEmissaoTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use GeneratesInterTestCertificate;
    use RefreshDatabase;

    private const BASE = 'https://cdpj.example.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();

        config(['inter.base_url' => self::BASE]);
        $this->gerarCertificadoDeTeste();
        $this->gravarCredenciaisDoInter();

        app(PaymentIntegrationSettingsService::class)->save([
            'pagamentos_inter_chave_pix' => 'chave-pix-da-empresa',
        ]);

        $this->grantGroupPermissions(1, ['financeiro' => ['visualizar', 'criar', 'editar']]);
        $this->grantGroupPermissions(3, ['financeiro' => ['visualizar']]);
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    private function admin()
    {
        return $this->createUserRecord([
            'nome' => 'Admin Cobranca',
            'email' => 'admin.cobranca@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    private function titulo(array $extra = []): Financeiro
    {
        return Financeiro::query()->create(array_merge([
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'servico',
            'descricao' => 'Reparo de notebook',
            'valor' => 300.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(5)->toDateString(),
        ], $extra));
    }

    /** @param array<string, mixed> $extra */
    private function fake(array $extra = []): void
    {
        Http::fake(array_merge([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => Http::response([
                'status' => 'ATIVA',
                'txid' => 'DEVOLVIDO',
                'pixCopiaECola' => '00020126...5802BR6304ABCD',
                'location' => 'qr.example.test/abc',
                'calendario' => ['criacao' => now()->toIso8601String(), 'expiracao' => 259200],
            ]),
        ], $extra));
    }

    public function test_emite_cobranca_com_valor_em_aberto_e_grava_copia_e_cola(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $resposta = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertSame('ATIVA', $resposta->json('data.cobranca.status'));
        $this->assertEqualsWithDelta(300.00, (float) $resposta->json('data.cobranca.valor'), 0.001);
        $this->assertStringStartsWith('00020126', (string) $resposta->json('data.cobranca.pix_copia_e_cola'));

        $cobranca = InterCobranca::query()->firstOrFail();
        $this->assertSame($titulo->id, $cobranca->financeiro_id);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{26,35}$/', (string) $cobranca->txid);
    }

    public function test_dois_cliques_devolvem_a_mesma_cobranca(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $a = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();
        $b = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertSame($a->json('data.cobranca.txid'), $b->json('data.cobranca.txid'));
        $this->assertSame(1, InterCobranca::query()->count());
    }

    public function test_valor_cobrado_e_o_saldo_e_nao_o_total_do_titulo(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo(['valor' => 300.00]);

        // Adiantamento de R$ 100: cobrar os R$ 300 cheios cobraria duas vezes
        // o mesmo dinheiro.
        DB::table('financeiro_movimentos')->insert([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => 'entrada',
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resposta = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertEqualsWithDelta(200.00, (float) $resposta->json('data.cobranca.valor'), 0.001);
    }

    public function test_falha_na_emissao_mantem_a_linha_para_reconciliacao(): void
    {
        // O cenario que mais importa: timeout depois do banco ter criado a
        // cobranca. Apagar a linha aqui perderia o unico rastro do txid.
        $this->fake([self::BASE.'/pix/v2/cob/*' => Http::response('', 504)]);
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(503);

        $cobranca = InterCobranca::query()->firstOrFail();
        $this->assertSame(InterCobranca::STATUS_FALHA_EMISSAO, $cobranca->status);
        $this->assertNotEmpty($cobranca->txid);

        // E ela entra na fila de reconciliacao, justamente por nao sabermos o
        // que existe do outro lado.
        $this->assertTrue(
            InterCobranca::query()->paraReconciliar()->whereKey($cobranca->id)->exists()
        );
    }

    public function test_apos_falha_confirma_no_banco_antes_de_emitir_outra(): void
    {
        // Um unico Http::fake com closure: chamar Http::fake() de novo ACUMULA
        // stubs, e o primeiro registrado continua ganhando o match.
        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => function ($request) {
                // PUT = tentativa de emissao, que falha. GET = a pergunta
                // "essa cobranca existe ai?", que o banco responde que sim.
                return $request->method() === 'PUT'
                    ? Http::response('', 504)
                    : Http::response(['status' => 'ATIVA', 'pixCopiaECola' => '00020126RECUPERADA']);
            },
        ]);

        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(503);
        $txidOriginal = InterCobranca::query()->firstOrFail()->txid;

        // Segunda tentativa adota a cobranca existente em vez de criar outra:
        // duas cobrancas vivas para o mesmo titulo e' o pior desfecho.
        $resposta = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertSame($txidOriginal, $resposta->json('data.cobranca.txid'));
        $this->assertSame(1, InterCobranca::query()->count());
    }

    public function test_apos_falha_com_404_do_banco_emite_uma_nova(): void
    {
        $primeiroTxid = null;

        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => function ($request) use (&$primeiroTxid) {
                if ($request->method() === 'GET') {
                    // 404 = o banco garante que aquela nao existe. So' entao
                    // emitir outra e' seguro.
                    return Http::response(['title' => 'Nao encontrado'], 404);
                }

                $txid = basename(parse_url($request->url(), PHP_URL_PATH) ?: '');
                $primeiroTxid ??= $txid;

                // A chave e' o txid, nao um contador: o cliente faz retry em
                // 5xx, entao a MESMA emissao chega mais de uma vez ao banco.
                // Isso e' seguro por construcao — PUT /cob/{txid} com o mesmo
                // txid e' idempotente do lado do Inter.
                return $txid === $primeiroTxid
                    ? Http::response('', 504)
                    : Http::response(['status' => 'ATIVA', 'pixCopiaECola' => '00020126NOVA']);
            },
        ]);

        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(503);
        $txidOriginal = InterCobranca::query()->firstOrFail()->txid;

        $resposta = $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertNotSame($txidOriginal, $resposta->json('data.cobranca.txid'));
        $this->assertSame(2, InterCobranca::query()->count());
    }

    public function test_nao_emite_outra_quando_o_banco_nao_responde_sobre_a_pendente(): void
    {
        $this->fake([self::BASE.'/pix/v2/cob/*' => Http::response('', 504)]);
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(503);

        // Banco segue indisponivel: duas cobrancas vivas para o mesmo titulo e'
        // pior que nenhuma, entao recusamos em vez de arriscar.
        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(422);

        $this->assertSame(1, InterCobranca::query()->count());
    }

    public function test_recusa_titulo_a_pagar_cancelado_ou_liquidado(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $pagar = $this->titulo(['tipo' => Financeiro::TIPO_PAGAR]);
        $this->postJson("/api/v1/financeiro/{$pagar->id}/cobranca-pix")->assertStatus(422);

        $cancelado = $this->titulo(['status' => Financeiro::STATUS_CANCELADO]);
        $this->postJson("/api/v1/financeiro/{$cancelado->id}/cobranca-pix")->assertStatus(422);

        $zerado = $this->titulo(['valor' => 0]);
        $this->postJson("/api/v1/financeiro/{$zerado->id}/cobranca-pix")->assertStatus(422);

        $this->assertSame(0, InterCobranca::query()->count());
    }

    public function test_sem_chave_pix_configurada_recusa_antes_de_chamar_o_banco(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);
        app(PaymentIntegrationSettingsService::class)->save(['pagamentos_inter_chave_pix' => '']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INTER_REQUISICAO_INVALIDA');

        $this->assertSame(0, InterCobranca::query()->count());
        Http::assertNothingSent();
    }

    public function test_usuario_sem_permissao_de_criar_recebe_403(): void
    {
        $this->fake();
        $titulo = $this->titulo();

        $somenteLeitura = $this->createUserRecord([
            'nome' => 'Leitor',
            'email' => 'leitor.cobranca@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        Sanctum::actingAs($somenteLeitura, ['*']);

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertForbidden();
        $this->deleteJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertForbidden();
        // Leitura ele pode.
        $this->getJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertOk();

        Http::assertNothingSent();
    }

    public function test_documento_invalido_emite_sem_devedor_em_vez_de_falhar(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $clienteId = $this->createClientRecord(['nome_razao' => 'Cliente Doc Ruim', 'cpf_cnpj' => '123']);
        $titulo = $this->titulo(['cliente_id' => $clienteId]);

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        Http::assertSent(function ($r): bool {
            if (! str_contains($r->url(), '/pix/v2/cob/')) {
                return true;
            }

            // Documento invalido faria o Inter recusar a cobranca inteira.
            // Emitir sem identificacao e' melhor que nao emitir.
            return ! isset($r->data()['devedor']);
        });
    }

    public function test_cancelamento_e_recusado_quando_ja_houve_pagamento(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);
        $titulo = $this->titulo();

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();
        $cobranca = InterCobranca::query()->firstOrFail();

        DB::table('inter_liquidacoes')->insert([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2EJAPAGO',
            'valor' => 300.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->deleteJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertStatus(422);
    }

    public function test_lembrete_de_cobranca_sai_mesmo_com_o_inter_fora_do_ar(): void
    {
        // O objetivo do agendamento e' cobrar o cliente. Anexar o Pix e'
        // conveniencia — trocar "cobranca sem Pix" por "cobranca que nao sai"
        // seria um retrocesso.
        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response('', 503),
            self::BASE.'/pix/v2/cob/*' => Http::response('', 503),
        ]);

        $titulo = $this->titulo();

        $servico = app(\App\Services\Orders\OrderClosureService::class);
        $metodo = new \ReflectionMethod($servico, 'resolvePixCopiaECola');
        $metodo->setAccessible(true);

        // Nao lanca: devolve null e o chamador segue montando a mensagem.
        $this->assertNull($metodo->invoke($servico, $titulo));
    }

    public function test_trilha_registra_a_emissao_sem_o_documento_do_cliente(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $clienteId = $this->createClientRecord(['nome_razao' => 'Fulano', 'cpf_cnpj' => '11144477735']);
        $titulo = $this->titulo(['cliente_id' => $clienteId]);

        $this->postJson("/api/v1/financeiro/{$titulo->id}/cobranca-pix")->assertCreated();

        $this->assertTrue(InterEvento::query()->where('evento', 'emitida')->exists());

        $cobranca = InterCobranca::query()->firstOrFail();
        $trilha = json_encode($cobranca->solicitacao_payload);

        // O CPF vai para o banco (ele precisa), mas nao fica na nossa trilha.
        $this->assertStringNotContainsString('11144477735', (string) $trilha);
    }
}
