<?php

namespace Tests\Feature\Integrations\Inter;

use App\Mail\OperationalAlertMail;
use App\Models\Financeiro;
use App\Models\Inter\InterCobranca;
use App\Models\Inter\InterEvento;
use App\Models\Inter\InterLiquidacao;
use App\Services\Integrations\EmailIntegrationSettingsService;
use App\Services\Integrations\Inter\InterLiquidacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class InterLiquidacaoTest extends TestCase
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
        Mail::fake();

        config([
            'inter.base_url' => self::BASE,
            'alertas.enabled' => true,
            'alertas.email' => 'dono@example.com',
            'alertas.whatsapp' => '',
        ]);

        app(EmailIntegrationSettingsService::class)->save([
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_crypto' => 'tls',
            'smtp_user' => 'a@example.test',
            'smtp_pass' => 'x',
            'smtp_from_email' => 'a@example.test',
            'smtp_from_name' => 'ERP',
        ]);

        $this->gerarCertificadoDeTeste();
        $this->gravarCredenciaisDoInter();
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    private function servico(): InterLiquidacaoService
    {
        return app(InterLiquidacaoService::class);
    }

    private function cenario(float $valorTitulo = 300.00): array
    {
        $titulo = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'servico',
            'descricao' => 'Reparo',
            'valor' => $valorTitulo,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(3)->toDateString(),
        ]);

        $cobranca = InterCobranca::query()->create([
            'provider' => 'inter',
            'txid' => 'TXID'.strtoupper(bin2hex(random_bytes(8))),
            'financeiro_id' => $titulo->id,
            'valor' => $valorTitulo,
            'status' => InterCobranca::STATUS_ATIVA,
            'expira_em' => now()->addDay(),
        ]);

        return [$titulo, $cobranca];
    }

    /** @param array<int, array<string, mixed>> $pix */
    private function fakeCob(array $pix, string $status = 'CONCLUIDA'): void
    {
        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => Http::response(['status' => $status, 'pix' => $pix]),
        ]);
    }

    public function test_pix_confirmado_vira_baixa_no_titulo(): void
    {
        [$titulo, $cobranca] = $this->cenario(300.00);

        $this->fakeCob([[
            'endToEndId' => 'E2E-PAGO-1',
            'valor' => '300.00',
            'horario' => now()->toIso8601String(),
        ]]);

        $resumo = $this->servico()->conciliar($cobranca);

        $this->assertSame(1, $resumo['liquidadas']);

        $movimento = DB::table('financeiro_movimentos')->where('financeiro_id', $titulo->id)->first();
        $this->assertNotNull($movimento);
        $this->assertEqualsWithDelta(300.00, (float) $movimento->valor_movimento, 0.001);
        $this->assertSame('pix', $movimento->forma_pagamento);
        $this->assertSame('E2E-PAGO-1', $movimento->documento_ref);

        $this->assertSame(Financeiro::STATUS_PAGO, $titulo->fresh()->status);

        // A liquidacao aponta para o movimento: deixa de estar pendente.
        $this->assertFalse(InterLiquidacao::query()->firstOrFail()->pendenteDeBaixa());
    }

    public function test_rodar_duas_vezes_nao_duplica_a_baixa(): void
    {
        [$titulo, $cobranca] = $this->cenario(300.00);

        $this->fakeCob([[
            'endToEndId' => 'E2E-REPETIDO',
            'valor' => '300.00',
        ]]);

        $primeira = $this->servico()->conciliar($cobranca);
        $segunda = $this->servico()->conciliar($cobranca->fresh());

        $this->assertSame(1, $primeira['liquidadas']);
        $this->assertSame(0, $segunda['liquidadas']);
        $this->assertSame(1, $segunda['ja_processadas']);

        // O numero que importa: um Pix, um movimento.
        $this->assertSame(1, DB::table('financeiro_movimentos')->where('financeiro_id', $titulo->id)->count());
    }

    public function test_pagamento_parcial_e_registrado_e_titulo_fica_parcial(): void
    {
        [$titulo, $cobranca] = $this->cenario(300.00);

        $this->fakeCob([['endToEndId' => 'E2E-PARCIAL', 'valor' => '120.00']], 'ATIVA');

        $this->servico()->conciliar($cobranca);

        $this->assertEqualsWithDelta(
            120.00,
            (float) DB::table('financeiro_movimentos')->where('financeiro_id', $titulo->id)->sum('valor_movimento'),
            0.001
        );
        $this->assertSame(Financeiro::STATUS_PARCIAL, $titulo->fresh()->status);
    }

    public function test_valor_maior_que_o_saldo_nao_da_baixa_e_alerta(): void
    {
        [$titulo, $cobranca] = $this->cenario(100.00);

        // Pagou R$ 250 num titulo de R$ 100. O excedente exige decisao humana
        // (devolver? abater outro titulo?) e registerMovement recusaria.
        $this->fakeCob([['endToEndId' => 'E2E-EXCEDENTE', 'valor' => '250.00']]);

        $resumo = $this->servico()->conciliar($cobranca);

        $this->assertSame(1, $resumo['divergentes']);
        $this->assertSame(0, $resumo['liquidadas']);
        $this->assertSame(0, DB::table('financeiro_movimentos')->where('financeiro_id', $titulo->id)->count());

        // Mas a liquidacao FICA gravada: o dinheiro entrou na conta e nao pode
        // sumir do radar so' porque nao coube no titulo.
        $liquidacao = InterLiquidacao::query()->firstOrFail();
        $this->assertTrue($liquidacao->pendenteDeBaixa());

        Mail::assertSent(OperationalAlertMail::class);
    }

    public function test_varios_pix_na_mesma_cobranca_somam(): void
    {
        [$titulo, $cobranca] = $this->cenario(300.00);

        $this->fakeCob([
            ['endToEndId' => 'E2E-A', 'valor' => '100.00'],
            ['endToEndId' => 'E2E-B', 'valor' => '200.00'],
        ]);

        $resumo = $this->servico()->conciliar($cobranca);

        $this->assertSame(2, $resumo['liquidadas']);
        $this->assertSame(Financeiro::STATUS_PAGO, $titulo->fresh()->status);
    }

    public function test_cobranca_sem_pix_nao_faz_nada(): void
    {
        [$titulo, $cobranca] = $this->cenario();

        $this->fakeCob([], 'ATIVA');

        $resumo = $this->servico()->conciliar($cobranca);

        $this->assertSame(0, $resumo['liquidadas']);
        $this->assertSame(0, DB::table('financeiro_movimentos')->count());
    }

    public function test_pix_sem_e2eid_e_ignorado_com_registro(): void
    {
        [, $cobranca] = $this->cenario();

        $this->fakeCob([['valor' => '50.00']]);

        $this->servico()->conciliar($cobranca);

        $this->assertSame(0, InterLiquidacao::query()->count());
        $this->assertTrue(InterEvento::query()->where('evento', 'pix_ignorado')->exists());
    }

    public function test_falha_na_reconsulta_nao_liquida_e_deixa_rastro(): void
    {
        [$titulo, $cobranca] = $this->cenario();

        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => Http::response('', 503),
        ]);

        $resumo = $this->servico()->conciliar($cobranca);

        $this->assertSame(0, $resumo['liquidadas']);
        $this->assertSame(0, DB::table('financeiro_movimentos')->count());
        $this->assertTrue(InterEvento::query()->where('evento', 'reconsulta_falhou')->exists());
    }

    public function test_404_encerra_a_cobranca_que_nunca_existiu_no_banco(): void
    {
        [, $cobranca] = $this->cenario();

        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => Http::response(['title' => 'nao encontrada'], 404),
        ]);

        $this->servico()->conciliar($cobranca);

        $cobranca = $cobranca->fresh();
        $this->assertSame(InterCobranca::STATUS_REMOVIDA_PELO_PSP, $cobranca->status);
        $this->assertNotNull($cobranca->cancelada_em);
    }

    public function test_baixa_automatica_aparece_como_maquina_no_historico_da_os(): void
    {
        $clienteId = $this->createClientRecord();
        $equipamentoId = $this->createEquipmentRecord($clienteId);
        $this->seedOrderCatalog();
        $this->seedOrderNumberConfiguration();

        $osId = $this->createOrderRecord([
            'cliente_id' => $clienteId,
            'equipamento_id' => $equipamentoId,
            'status' => 'aguardando_reparo',
            'valor_total' => 200,
            'valor_final' => 200,
        ]);

        $titulo = Financeiro::query()->create([
            'tipo' => Financeiro::TIPO_RECEBER,
            'categoria' => 'servico',
            'descricao' => 'Reparo',
            'valor' => 200.00,
            'status' => Financeiro::STATUS_PENDENTE,
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'os_id' => $osId,
        ]);

        $cobranca = InterCobranca::query()->create([
            'provider' => 'inter',
            'txid' => 'TXIDOS'.strtoupper(bin2hex(random_bytes(6))),
            'financeiro_id' => $titulo->id,
            'os_id' => $osId,
            'valor' => 200.00,
            'status' => InterCobranca::STATUS_ATIVA,
        ]);

        $this->fakeCob([['endToEndId' => 'E2E-OS', 'valor' => '200.00']]);

        $this->servico()->conciliar($cobranca);

        $evento = DB::table('os_eventos')
            ->where('os_id', $osId)
            ->where('tipo', 'cobranca_pix_liquidada')
            ->first();

        $this->assertNotNull($evento);
        // Quem deu a baixa foi a maquina: isso precisa ficar explicito no
        // historico, nao parecer acao de uma pessoa.
        $this->assertSame('automacao', $evento->origem);
        $this->assertNull($evento->usuario_id);
    }

    public function test_comando_enfileira_apenas_cobrancas_abertas(): void
    {
        [, $aberta] = $this->cenario();
        [, $concluida] = $this->cenario();
        $concluida->update(['status' => InterCobranca::STATUS_CONCLUIDA]);
        [, $vencidaHaMuito] = $this->cenario();
        $vencidaHaMuito->update(['expira_em' => now()->subDays(60)]);

        Http::fake([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            self::BASE.'/pix/v2/cob/*' => Http::response(['status' => 'ATIVA', 'pix' => []]),
        ]);

        $this->artisan('inter:conciliar --sincrono')->assertSuccessful();

        // Uma unica reconsulta: concluida e vencida ha 60 dias ficam de fora.
        $reconsultas = 0;
        Http::assertSent(function ($r) use (&$reconsultas): bool {
            if (str_contains($r->url(), '/pix/v2/cob/')) {
                $reconsultas++;
            }

            return true;
        });

        $this->assertSame(1, $reconsultas);
    }

    public function test_comando_sem_integracao_configurada_nao_faz_barulho(): void
    {
        config(['inter.certificado.cert_path' => '/nao/existe.crt']);
        Http::fake();

        $this->artisan('inter:conciliar')->assertSuccessful();

        Http::assertNothingSent();
    }
}
