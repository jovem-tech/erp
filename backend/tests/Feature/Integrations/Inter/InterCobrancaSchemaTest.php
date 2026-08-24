<?php

namespace Tests\Feature\Integrations\Inter;

use App\Models\Inter\InterCobranca;
use App\Models\Inter\InterEvento;
use App\Models\Inter\InterLiquidacao;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class InterCobrancaSchemaTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
    }

    private function cobranca(array $extra = []): InterCobranca
    {
        return InterCobranca::query()->create(array_merge([
            'txid' => 'TXID'.bin2hex(random_bytes(8)),
            'valor' => 300.00,
            'status' => InterCobranca::STATUS_ATIVA,
            'expira_em' => now()->addDay(),
        ], $extra));
    }

    public function test_txid_e_unico(): void
    {
        $this->cobranca(['txid' => 'TXIDREPETIDO0001']);

        $this->expectException(QueryException::class);

        $this->cobranca(['txid' => 'TXIDREPETIDO0001']);
    }

    public function test_e2eid_duplicado_e_recusado_pelo_banco_de_dados(): void
    {
        $cobranca = $this->cobranca();

        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2E1234567890',
            'valor' => 300.00,
            'horario' => now(),
        ]);

        // Esta e' a garantia central da integracao: a corrida e' resolvida pelo
        // UNIQUE, nao pela ordem de execucao do PHP. Se este teste cair, duas
        // entregas concorrentes do mesmo Pix viram duas baixas.
        $this->expectException(QueryException::class);

        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2E1234567890',
            'valor' => 300.00,
            'horario' => now(),
        ]);
    }

    public function test_mesmo_e2eid_em_cobrancas_diferentes_tambem_e_recusado(): void
    {
        $a = $this->cobranca();
        $b = $this->cobranca();

        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $a->id,
            'e2eid' => 'E2ECOMPARTILHADO',
            'valor' => 10.00,
        ]);

        // O e2eid identifica o Pix no SPI inteiro, nao dentro de uma cobranca.
        // O UNIQUE e' global de proposito.
        $this->expectException(QueryException::class);

        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $b->id,
            'e2eid' => 'E2ECOMPARTILHADO',
            'valor' => 10.00,
        ]);
    }

    public function test_pagamento_parcial_gera_varias_liquidacoes(): void
    {
        $cobranca = $this->cobranca(['valor' => 300.00]);

        foreach ([['E2EPARC1', 100.00], ['E2EPARC2', 120.00]] as [$e2e, $valor]) {
            InterLiquidacao::query()->create([
                'inter_cobranca_id' => $cobranca->id,
                'e2eid' => $e2e,
                'valor' => $valor,
            ]);
        }

        $this->assertEqualsWithDelta(220.00, $cobranca->valorLiquidado(), 0.001);
        $this->assertFalse($cobranca->estaQuitada());

        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2EPARC3',
            'valor' => 80.00,
        ]);

        $this->assertTrue($cobranca->fresh()->estaQuitada());
    }

    public function test_escopo_abertas_ignora_expirada_cancelada_e_concluida(): void
    {
        $aberta = $this->cobranca();
        $this->cobranca(['expira_em' => now()->subMinute()]);
        $this->cobranca(['cancelada_em' => now()]);
        $this->cobranca(['status' => InterCobranca::STATUS_CONCLUIDA]);

        $abertas = InterCobranca::query()->abertas()->pluck('id')->all();

        $this->assertSame([$aberta->id], $abertas);
    }

    public function test_cobranca_sem_expiracao_conta_como_aberta(): void
    {
        $semPrazo = $this->cobranca(['expira_em' => null]);

        $this->assertContains($semPrazo->id, InterCobranca::query()->abertas()->pluck('id')->all());
    }

    public function test_liquidacao_sem_movimento_e_pendente_de_baixa(): void
    {
        $cobranca = $this->cobranca();

        $liquidacao = InterLiquidacao::query()->create([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2EPENDENTE',
            'valor' => 50.00,
        ]);

        // Dinheiro que entrou na conta e ainda nao esta refletido no sistema:
        // estado que precisa ser visivel, nao escondido.
        $this->assertTrue($liquidacao->pendenteDeBaixa());

        $liquidacao->update(['financeiro_movimento_id' => 999]);

        $this->assertFalse($liquidacao->fresh()->pendenteDeBaixa());
    }

    public function test_apagar_cobranca_leva_as_liquidacoes_junto(): void
    {
        $cobranca = $this->cobranca();
        InterLiquidacao::query()->create([
            'inter_cobranca_id' => $cobranca->id,
            'e2eid' => 'E2ECASCATA',
            'valor' => 5.00,
        ]);

        $cobranca->delete();

        $this->assertSame(0, InterLiquidacao::query()->count());
    }

    public function test_evento_guarda_decisao_motivo_e_payloads(): void
    {
        InterEvento::registrar([
            'txid' => 'TXIDEVENTO',
            'e2eid' => 'E2EEVENTO',
            'evento' => 'liquidacao',
            'nivel' => 'warning',
            'origem' => InterEvento::ORIGEM_WEBHOOK,
            'http_status' => 200,
            'decisao' => InterEvento::DECISAO_VALOR_DIVERGENTE,
            'motivo' => 'Valor pago difere do saldo em aberto.',
            'payload_recebido' => ['pix' => [['valor' => '10.00']]],
            'payload_reconsulta' => ['status' => 'CONCLUIDA'],
        ]);

        $evento = InterEvento::query()->firstOrFail();

        $this->assertSame(InterEvento::DECISAO_VALOR_DIVERGENTE, $evento->decisao);
        $this->assertSame('CONCLUIDA', $evento->payload_reconsulta['status']);
        $this->assertNotNull($evento->created_at);
    }
}
