<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaCompromisso;
use App\Models\CrmFollowup;
use App\Models\Financeiro;
use App\Models\FinanceiroMovimento;
use App\Services\Agenda\AgendaSourceReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * O reconciliador e o que faz a agenda continuar verdadeira sozinha. Estes
 * testes cobrem as quatro transicoes (criar, atualizar, concluir, cancelar) e,
 * principalmente, a idempotencia - sem ela o comando de 15 em 15 minutos
 * encheria a agenda de duplicatas.
 */
class AgendaSourceReconcilerTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
    }

    private function reconciler(): AgendaSourceReconciler
    {
        return app(AgendaSourceReconciler::class);
    }

    private function criarContaPagar(array $overrides = []): Financeiro
    {
        return Financeiro::query()->create(array_merge([
            'tipo' => Financeiro::TIPO_PAGAR,
            'categoria' => 'Despesas Operacionais',
            'descricao' => 'Conta de energia',
            'valor' => 250.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => Financeiro::STATUS_PENDENTE,
        ], $overrides));
    }

    public function test_creates_an_appointment_for_a_payable(): void
    {
        $titulo = $this->criarContaPagar();

        $this->reconciler()->reconcile();

        $compromisso = AgendaCompromisso::query()->where('origem_tipo', 'conta_pagar')->first();

        $this->assertNotNull($compromisso);
        $this->assertSame((int) $titulo->id, (int) $compromisso->origem_id);
        $this->assertStringContainsString('Conta de energia', $compromisso->titulo);
        $this->assertSame(AgendaCompromisso::STATUS_PENDENTE, $compromisso->status);
        $this->assertTrue($compromisso->isManaged());
    }

    public function test_titulo_sem_valor_nao_vira_compromisso(): void
    {
        // A baixa da OS grava um lançamento de cobrança mesmo quando não sobra
        // nada a receber (garantia, sem custo, devolvido sem reparo). Eram 13
        // de 30 na base real, inundando o calendário com "Receber: Cobrança da
        // OS" que ninguém jamais precisaria fazer.
        $this->criarContaPagar([
            'tipo' => Financeiro::TIPO_RECEBER,
            'descricao' => 'Cobrança da OS OS26070001',
            'valor' => 0.00,
        ]);

        $this->reconciler()->reconcile();

        $this->assertSame(0, AgendaCompromisso::query()->count());
    }

    public function test_compromisso_de_titulo_zerado_ja_criado_e_cancelado(): void
    {
        $titulo = $this->criarContaPagar([
            'tipo' => Financeiro::TIPO_RECEBER,
            'descricao' => 'Cobrança da OS',
            'valor' => 150.00,
        ]);

        $this->reconciler()->reconcile();
        $this->assertSame(1, AgendaCompromisso::query()->where('status', 'pendente')->count());

        // O título perde o valor (estorno, correção de lançamento).
        $titulo->update(['valor' => 0.00]);
        $this->reconciler()->reconcile();

        $this->assertSame(
            AgendaCompromisso::STATUS_CANCELADO,
            AgendaCompromisso::query()->where('origem_id', $titulo->id)->first()->status
        );
    }

    public function test_titulo_integralmente_liquidado_e_concluido_mesmo_com_status_desatualizado(): void
    {
        // Olhar só o `status` deixava passar o título pago por movimentos cujo
        // status não acompanhou — ele parava de exigir ação e continuava na agenda.
        $titulo = $this->criarContaPagar(['valor' => 300.00]);
        $this->reconciler()->reconcile();

        FinanceiroMovimento::query()->create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => 'saida',
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 300.00,
        ]);
        // Status continua "pendente" de propósito.
        $this->reconciler()->reconcile();

        $this->assertSame(
            AgendaCompromisso::STATUS_CONCLUIDO,
            AgendaCompromisso::query()->where('origem_id', $titulo->id)->first()->status
        );
    }

    public function test_descricao_mostra_o_saldo_em_aberto_e_nao_o_valor_de_face(): void
    {
        // "R$ 500,00" num título com R$ 400,00 já recebidos faz cobrar errado.
        $titulo = $this->criarContaPagar([
            'tipo' => Financeiro::TIPO_RECEBER,
            'descricao' => 'Cobrança parcial',
            'valor' => 500.00,
        ]);

        FinanceiroMovimento::query()->create([
            'financeiro_id' => $titulo->id,
            'tipo_movimento' => 'entrada',
            'data_movimento' => now()->toDateString(),
            'valor_movimento' => 400.00,
        ]);

        $this->reconciler()->reconcile();

        $descricao = (string) AgendaCompromisso::query()->where('origem_id', $titulo->id)->first()->descricao;

        $this->assertStringContainsString('Em aberto: R$ 100,00', $descricao);
        $this->assertStringContainsString('já recebido R$ 400,00', $descricao);
    }

    public function test_is_idempotent(): void
    {
        $this->criarContaPagar();

        $this->reconciler()->reconcile();
        $this->reconciler()->reconcile();
        $this->reconciler()->reconcile();

        $this->assertSame(1, AgendaCompromisso::query()->where('origem_tipo', 'conta_pagar')->count());
    }

    public function test_paying_the_bill_completes_the_appointment(): void
    {
        $titulo = $this->criarContaPagar();
        $this->reconciler()->reconcile();

        $titulo->update(['status' => Financeiro::STATUS_PAGO]);
        $this->reconciler()->reconcile();

        $compromisso = AgendaCompromisso::query()->where('origem_id', $titulo->id)->first();

        $this->assertSame(AgendaCompromisso::STATUS_CONCLUIDO, $compromisso->status);
        $this->assertNotNull($compromisso->concluido_em);
    }

    public function test_cancelling_the_bill_cancels_the_appointment(): void
    {
        $titulo = $this->criarContaPagar();
        $this->reconciler()->reconcile();

        // Cancelado sai da coleta da fonte; o reconciliador percebe a ausencia.
        $titulo->update(['status' => Financeiro::STATUS_CANCELADO]);
        $this->reconciler()->reconcile();

        $this->assertSame(
            AgendaCompromisso::STATUS_CANCELADO,
            AgendaCompromisso::query()->where('origem_id', $titulo->id)->first()->status
        );
    }

    public function test_a_bill_that_comes_back_reopens_instead_of_duplicating(): void
    {
        $titulo = $this->criarContaPagar();
        $this->reconciler()->reconcile();

        $titulo->update(['status' => Financeiro::STATUS_CANCELADO]);
        $this->reconciler()->reconcile();

        // Estorno: o titulo volta a valer.
        $titulo->update(['status' => Financeiro::STATUS_PENDENTE]);
        $this->reconciler()->reconcile();

        $compromissos = AgendaCompromisso::query()->where('origem_id', $titulo->id)->get();

        // A unique (origem_tipo, origem_id) impede recriar: precisa reabrir.
        $this->assertCount(1, $compromissos);
        $this->assertSame(AgendaCompromisso::STATUS_PENDENTE, $compromissos->first()->status);
    }

    public function test_follows_the_due_date_when_it_changes(): void
    {
        $titulo = $this->criarContaPagar();
        $this->reconciler()->reconcile();

        $novaData = now()->addDays(20)->toDateString();
        $titulo->update(['data_vencimento' => $novaData]);
        $this->reconciler()->reconcile();

        $this->assertSame(
            $novaData,
            AgendaCompromisso::query()->where('origem_id', $titulo->id)->first()->inicio_em->toDateString()
        );
    }

    public function test_never_touches_manual_appointments(): void
    {
        $manual = AgendaCompromisso::query()->create([
            'titulo' => 'Meu lembrete', 'inicio_em' => now()->addDay(),
            'status' => AgendaCompromisso::STATUS_PENDENTE, 'tipo' => AgendaCompromisso::TIPO_MANUAL,
        ]);

        $this->criarContaPagar();
        $this->reconciler()->reconcile();
        $this->reconciler()->reconcile();

        $manual->refresh();

        $this->assertSame('Meu lembrete', $manual->titulo);
        $this->assertSame(AgendaCompromisso::STATUS_PENDENTE, $manual->status);
    }

    public function test_brings_the_post_service_followup_that_had_no_screen(): void
    {
        // A razao mais direta de o modulo existir: a baixa da OS ja gravava
        // isto e nenhuma tela lia.
        $followup = CrmFollowup::query()->create([
            'titulo' => 'Retorno pós-serviço da OS 123',
            'descricao' => 'Revisar satisfação do cliente.',
            'data_prevista' => now()->addDays(7)->setTime(10, 0),
            'status' => CrmFollowup::STATUS_PENDENTE,
        ]);

        $this->reconciler()->reconcile();

        $compromisso = AgendaCompromisso::query()->where('origem_tipo', 'retorno_pos_servico')->first();

        $this->assertNotNull($compromisso);
        $this->assertSame((int) $followup->id, (int) $compromisso->origem_id);
        // Ligacao tem hora marcada; nao pode virar evento de dia inteiro.
        $this->assertFalse((bool) $compromisso->dia_inteiro);
        $this->assertSame('10:00', $compromisso->inicio_em->format('H:i'));
    }

    public function test_reconciles_the_default_six_month_followup(): void
    {
        // Regressão real: OrderClosureService::RETURN_FOLLOWUP_DEFAULT_DAYS é
        // 180, exatamente o horizonte antigo da varredura. O padrão da própria
        // tela de baixa ficava na borda, e um dia além dela sumia em silêncio.
        foreach ([180, 181, 200, 365] as $dias) {
            CrmFollowup::query()->create([
                'titulo' => 'Retorno +'.$dias,
                'data_prevista' => now()->addDays($dias)->setTime(10, 0),
                'status' => CrmFollowup::STATUS_PENDENTE,
                'origem_evento' => 'teste_'.$dias,
            ]);
        }

        $this->reconciler()->reconcile();

        $this->assertSame(
            4,
            AgendaCompromisso::query()->where('origem_tipo', 'retorno_pos_servico')->count()
        );
    }

    public function test_reconcile_for_date_reaches_beyond_the_sweep_horizon(): void
    {
        // Quem agenda um retorno para daqui a dois anos precisa vê-lo na agenda
        // na hora — não quando o horizonte da varredura periódica alcançar a data.
        $followup = CrmFollowup::query()->create([
            'titulo' => 'Retorno muito distante',
            'data_prevista' => now()->addDays(900)->setTime(10, 0),
            'status' => CrmFollowup::STATUS_PENDENTE,
            'origem_evento' => 'teste_distante',
        ]);

        // A varredura normal não alcança.
        $this->reconciler()->reconcile();
        $this->assertDatabaseMissing('agenda_compromissos', [
            'origem_tipo' => 'retorno_pos_servico',
            'origem_id' => $followup->id,
        ]);

        $this->reconciler()->reconcileForDate(
            'retorno_pos_servico',
            CarbonImmutable::parse($followup->data_prevista)
        );

        $this->assertDatabaseHas('agenda_compromissos', [
            'origem_tipo' => 'retorno_pos_servico',
            'origem_id' => $followup->id,
        ]);
    }

    public function test_reconcile_for_date_only_touches_the_requested_source(): void
    {
        $this->criarContaPagar();
        CrmFollowup::query()->create([
            'titulo' => 'Retorno',
            'data_prevista' => now()->addDays(7)->setTime(10, 0),
            'status' => CrmFollowup::STATUS_PENDENTE,
            'origem_evento' => 'teste_isolado',
        ]);

        $this->reconciler()->reconcileForDate('retorno_pos_servico', CarbonImmutable::now()->addDays(7));

        // A conta a pagar não pertence à fonte pedida e não pode ser tocada.
        $this->assertSame(1, AgendaCompromisso::query()->count());
        $this->assertSame(
            'retorno_pos_servico',
            AgendaCompromisso::query()->first()->origem_tipo
        );
    }

    public function test_reconcile_for_date_never_throws(): void
    {
        // A agenda é um espelho: uma falha aqui não pode derrubar a baixa da OS
        // que criou a obrigação.
        $resultado = $this->reconciler()->reconcileForDate('fonte_inexistente', CarbonImmutable::now());

        $this->assertNull($resultado);
    }

    public function test_ignores_sources_outside_the_window(): void
    {
        // Além do horizonte da varredura (AgendaSourceReconciler::DAYS_AHEAD,
        // 400 dias): não deve nem criar, nem cancelar nada.
        $this->criarContaPagar(['data_vencimento' => now()->addYears(3)->toDateString()]);

        $this->reconciler()->reconcile();

        $this->assertSame(0, AgendaCompromisso::query()->count());
    }
}
