<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaCompromisso;
use App\Models\CrmFollowup;
use App\Models\Financeiro;
use App\Services\Agenda\AgendaSourceReconciler;
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

    public function test_ignores_sources_outside_the_window(): void
    {
        // Fora da janela consultada: nao deve nem criar, nem cancelar nada.
        $this->criarContaPagar(['data_vencimento' => now()->addYears(3)->toDateString()]);

        $this->reconciler()->reconcile();

        $this->assertSame(0, AgendaCompromisso::query()->count());
    }
}
