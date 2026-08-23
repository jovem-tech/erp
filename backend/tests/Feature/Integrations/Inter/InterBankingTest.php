<?php

namespace Tests\Feature\Integrations\Inter;

use App\Models\FinanceiroConta;
use App\Services\Integrations\Inter\InterBankingService;
use App\Services\Integrations\Inter\InterException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\Concerns\GeneratesInterTestCertificate;
use Tests\TestCase;

class InterBankingTest extends TestCase
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
        Cache::flush();

        config(['inter.base_url' => self::BASE, 'inter.banking.saldo_cache_segundos' => 600]);
        $this->gerarCertificadoDeTeste();
        $this->gravarCredenciaisDoInter();

        $this->grantGroupPermissions(1, [
            'financeiro' => ['visualizar', 'criar', 'editar'],
            // Conta financeira vive no modulo contas_saldos, nao em financeiro.
            'contas_saldos' => ['visualizar', 'criar', 'editar'],
        ]);
        $this->grantGroupPermissions(3, ['clientes' => ['visualizar']]);
    }

    protected function tearDown(): void
    {
        $this->limparCertificadosDeTeste();

        parent::tearDown();
    }

    private function contaVinculada(): FinanceiroConta
    {
        return FinanceiroConta::query()->create([
            'nome' => 'Inter PJ',
            'tipo' => FinanceiroConta::TIPO_BANCO,
            'instituicao' => 'Banco Inter',
            'integracao_provider' => 'inter',
            'integracao_conta_ref' => '123456',
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'considera_disponivel' => true,
            'ativo' => true,
        ]);
    }

    private function admin()
    {
        return $this->createUserRecord([
            'nome' => 'Admin Financeiro',
            'email' => 'admin.inter@example.com',
            'perfil' => 'admin',
            'grupo_id' => 1,
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function fake(array $extra = []): void
    {
        Http::fake(array_merge([
            self::BASE.'/oauth/v2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
        ], $extra));
    }

    public function test_saldo_e_devolvido_e_cacheado(): void
    {
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response(['disponivel' => 2500.75])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario")
            ->assertOk()
            ->assertJsonPath('data.saldo.disponivel', 2500.75);

        // Segunda leitura sai do cache: ha limite de requisicoes no Inter e a
        // tela nao pode bater no banco a cada carregamento.
        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario")->assertOk();

        $chamadasAoSaldo = 0;
        Http::assertSent(function ($r) use (&$chamadasAoSaldo): bool {
            if (str_contains($r->url(), '/banking/v2/saldo')) {
                $chamadasAoSaldo++;
            }

            return true;
        });

        $this->assertSame(1, $chamadasAoSaldo);
    }

    public function test_parametro_atualizar_forca_nova_consulta(): void
    {
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response(['disponivel' => 10.0])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario")->assertOk();
        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario?atualizar=1")->assertOk();

        $chamadas = 0;
        Http::assertSent(function ($r) use (&$chamadas): bool {
            if (str_contains($r->url(), '/banking/v2/saldo')) {
                $chamadas++;
            }

            return true;
        });

        $this->assertSame(2, $chamadas);
    }

    public function test_conta_sem_vinculo_e_recusada(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $conta = FinanceiroConta::query()->create([
            'nome' => 'Caixa da loja',
            'tipo' => FinanceiroConta::TIPO_CAIXA,
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'considera_disponivel' => true,
            'ativo' => true,
        ]);

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INTER_CONTA_NAO_VINCULADA');

        Http::assertNothingSent();
    }

    public function test_usuario_sem_permissao_recebe_403(): void
    {
        $this->fake();
        $conta = $this->contaVinculada();

        $semPermissao = $this->createUserRecord([
            'nome' => 'Atendente',
            'email' => 'atendente.inter@example.com',
            'perfil' => 'atendente',
            'grupo_id' => 3,
        ]);

        Sanctum::actingAs($semPermissao, ['*']);

        foreach ([
            "/api/v1/financeiro/contas/{$conta->id}/saldo-bancario",
            "/api/v1/financeiro/contas/{$conta->id}/extrato-bancario",
            "/api/v1/financeiro/contas/{$conta->id}/conciliacao-bancaria",
            '/api/v1/financeiro/inter/status',
        ] as $rota) {
            $this->getJson($rota)->assertForbidden();
        }

        Http::assertNothingSent();
    }

    public function test_extrato_respeita_a_janela_maxima(): void
    {
        $this->fake([self::BASE.'/banking/v2/extrato*' => Http::response(['transacoes' => []])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        // 120 dias com limite de 90: o erro tem que ser nosso e explicito, nao
        // um 400 generico do banco.
        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/extrato-bancario?de=2026-01-01&ate=2026-05-01")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INTER_REQUISICAO_INVALIDA');
    }

    public function test_extrato_devolve_transacoes(): void
    {
        $this->fake([self::BASE.'/banking/v2/extrato*' => Http::response([
            'transacoes' => [
                ['data' => '2026-08-20', 'valor' => 150.00, 'tipo' => 'C', 'descricao' => 'PIX RECEBIDO'],
                ['data' => '2026-08-21', 'valor' => 80.00, 'tipo' => 'D', 'descricao' => 'TARIFA'],
            ],
        ])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/extrato-bancario?de=2026-08-01&ate=2026-08-22")
            ->assertOk()
            ->assertJsonPath('data.extrato.total', 2)
            ->assertJsonPath('data.extrato.transacoes.0.descricao', 'PIX RECEBIDO');
    }

    public function test_conciliacao_aponta_divergencia_sem_criar_ajuste(): void
    {
        // Saldo interno = 0 (conta recem-criada, sem movimentos). Banco diz 500.
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response(['disponivel' => 500.00])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        $movimentosAntes = DB::table('financeiro_conta_movimentos')->count();

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/conciliacao-bancaria")
            ->assertOk()
            ->assertJsonPath('data.conciliacao.saldo_banco', 500.0)
            ->assertJsonPath('data.conciliacao.divergente', true)
            ->assertJsonPath('data.conciliacao.ajuste_automatico', false);

        // A regra que mais importa: conciliacao e' LEITURA. Um sistema que
        // "conserta" o proprio saldo sozinho apaga a evidencia do erro.
        $this->assertSame($movimentosAntes, DB::table('financeiro_conta_movimentos')->count());
    }

    public function test_conciliacao_sem_divergencia_quando_bate(): void
    {
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response(['disponivel' => 0.0])]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/conciliacao-bancaria")
            ->assertOk()
            ->assertJsonPath('data.conciliacao.divergente', false);

        $this->assertEqualsWithDelta(
            0.0,
            (float) $this->getJson("/api/v1/financeiro/contas/{$conta->id}/conciliacao-bancaria")
                ->json('data.conciliacao.divergencia'),
            0.001
        );
    }

    public function test_banco_fora_do_ar_devolve_503_e_nao_422(): void
    {
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response('', 503)]);
        Sanctum::actingAs($this->admin(), ['*']);
        $conta = $this->contaVinculada();

        // 422 faria a tela sugerir "confira os dados" quando o problema e' do
        // banco e some sozinho.
        $this->getJson("/api/v1/financeiro/contas/{$conta->id}/saldo-bancario")
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'INTER_INDISPONIVEL');
    }

    public function test_status_expoe_diagnostico_sem_vazar_segredo(): void
    {
        $this->fake();
        $this->gravarCredenciaisDoInter(clientSecret: 'SEGREDO-NAO-PODE-VAZAR');
        Sanctum::actingAs($this->admin(), ['*']);

        $resposta = $this->getJson('/api/v1/financeiro/inter/status')->assertOk();

        $resposta->assertJsonPath('data.inter.pronto', true);
        $this->assertStringNotContainsString('SEGREDO-NAO-PODE-VAZAR', $resposta->getContent());
    }

    public function test_saldo_com_campo_desconhecido_falha_em_vez_de_assumir_zero(): void
    {
        $this->fake([self::BASE.'/banking/v2/saldo*' => Http::response(['algoInesperado' => 1])]);

        $this->expectException(InterException::class);

        app(InterBankingService::class)->saldo();
    }

    public function test_conta_pode_ser_vinculada_ao_inter_pela_api(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $resposta = $this->postJson('/api/v1/financeiro/contas', [
            'nome' => 'Conta Inter PJ',
            'tipo' => FinanceiroConta::TIPO_BANCO,
            'instituicao' => 'Banco Inter',
            'integracao_provider' => 'inter',
            'integracao_conta_ref' => '987654',
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'saldo_inicial' => 0,
        ])->assertCreated();

        $this->assertSame('inter', $resposta->json('data.conta.integracao_provider'));
    }

    public function test_nao_da_para_vincular_integracao_a_conta_que_nao_seja_banco(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        // Vincular caixa fisico daria uma conciliacao que nunca fecha, por
        // construcao: o dinheiro da gaveta nao aparece no extrato do banco.
        $this->postJson('/api/v1/financeiro/contas', [
            'nome' => 'Caixa com integracao',
            'tipo' => FinanceiroConta::TIPO_CAIXA,
            'integracao_provider' => 'inter',
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'saldo_inicial' => 0,
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_provider_vazio_significa_sem_vinculo(): void
    {
        $this->fake();
        Sanctum::actingAs($this->admin(), ['*']);

        $resposta = $this->postJson('/api/v1/financeiro/contas', [
            'nome' => 'Conta sem integracao',
            'tipo' => FinanceiroConta::TIPO_BANCO,
            'integracao_provider' => '',
            'data_inicio_controle' => now()->subMonth()->toDateString(),
            'saldo_inicial' => 0,
        ])->assertCreated();

        $this->assertNull($resposta->json('data.conta.integracao_provider'));
    }

    public function test_periodo_invertido_e_normalizado(): void
    {
        $this->fake([self::BASE.'/banking/v2/extrato*' => Http::response(['transacoes' => []])]);

        $resultado = app(InterBankingService::class)->extrato(
            CarbonImmutable::parse('2026-08-20'),
            CarbonImmutable::parse('2026-08-01')
        );

        $this->assertSame('2026-08-01', $resultado['periodo']['de']);
        $this->assertSame('2026-08-20', $resultado['periodo']['ate']);
    }
}
