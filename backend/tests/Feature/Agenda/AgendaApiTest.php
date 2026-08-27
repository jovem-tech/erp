<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaCompromisso;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

class AgendaApiTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->grantGroupPermissions(1, [
            'agenda' => ['visualizar', 'criar', 'editar', 'excluir', 'ver_todos'],
        ]);
        // Grupo 2 opera a propria agenda, sem enxergar a dos outros.
        $this->grantGroupPermissions(2, [
            'agenda' => ['visualizar', 'criar', 'editar'],
        ]);
    }

    public function test_creates_a_manual_appointment(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/agenda', [
            'titulo' => 'Ligar para o fornecedor',
            'inicio_em' => '2026-09-10 14:30:00',
            'dia_inteiro' => false,
            'prioridade' => 'alta',
            'lembrete_minutos' => 30,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.compromisso.titulo', 'Ligar para o fornecedor')
            ->assertJsonPath('data.compromisso.hora', '14:30')
            ->assertJsonPath('data.compromisso.tipo', 'manual')
            ->assertJsonPath('data.compromisso.gerido', false);

        // Sem responsavel informado, quem cria assume: um lembrete pessoal sem
        // dono some da visao de quem o escreveu no momento em que a equipe cresce.
        $this->assertSame(
            (int) $admin->id,
            (int) AgendaCompromisso::query()->first()->responsavel_id
        );
    }

    public function test_lists_only_visible_appointments_without_ver_todos(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $tecnico = $this->createUserRecord(['grupo_id' => 2, 'email' => 'tecnico@example.com']);

        AgendaCompromisso::query()->create([
            'titulo' => 'Do administrador', 'inicio_em' => '2026-09-10 09:00:00',
            'responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);
        AgendaCompromisso::query()->create([
            'titulo' => 'Do tecnico', 'inicio_em' => '2026-09-11 09:00:00',
            'responsavel_id' => $tecnico->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);
        AgendaCompromisso::query()->create([
            'titulo' => 'Sem dono', 'inicio_em' => '2026-09-12 09:00:00',
            'responsavel_id' => null, 'status' => 'pendente', 'tipo' => 'conta_pagar',
            'origem_tipo' => 'conta_pagar', 'origem_id' => 1,
        ]);

        Sanctum::actingAs($tecnico, ['*']);

        $response = $this->getJson('/api/v1/agenda?de=2026-09-01&ate=2026-09-30');

        $titles = collect($response->assertOk()->json('data.compromissos'))->pluck('titulo')->all();

        // Ve o proprio e o que nao tem dono; nao ve o do administrador.
        $this->assertEqualsCanonicalizing(['Do tecnico', 'Sem dono'], $titles);
        $response->assertJsonPath('data.pode_ver_todos', false);
    }

    public function test_ver_todos_sees_every_responsible(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        $outro = $this->createUserRecord(['grupo_id' => 2, 'email' => 'outro@example.com']);

        AgendaCompromisso::query()->create([
            'titulo' => 'De outro usuario', 'inicio_em' => '2026-09-10 09:00:00',
            'responsavel_id' => $outro->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);

        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/agenda?de=2026-09-01&ate=2026-09-30')
            ->assertOk()
            ->assertJsonCount(1, 'data.compromissos')
            ->assertJsonPath('data.pode_ver_todos', true);
    }

    public function test_cannot_edit_an_appointment_of_another_responsible(): void
    {
        $dono = $this->createUserRecord(['grupo_id' => 1]);
        $intruso = $this->createUserRecord(['grupo_id' => 2, 'email' => 'intruso@example.com']);

        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Particular', 'inicio_em' => '2026-09-10 09:00:00',
            'responsavel_id' => $dono->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);

        Sanctum::actingAs($intruso, ['*']);

        $this->patchJson('/api/v1/agenda/'.$item->id, ['titulo' => 'Sequestrado'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'AGENDA_FORBIDDEN');

        $this->assertSame('Particular', $item->refresh()->titulo);
    }

    public function test_completes_and_reopens(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Tarefa', 'inicio_em' => '2026-09-10 09:00:00',
            'responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);

        $this->postJson('/api/v1/agenda/'.$item->id.'/concluir')
            ->assertOk()
            ->assertJsonPath('data.compromisso.status', 'concluido');

        $this->assertSame((int) $admin->id, (int) $item->refresh()->concluido_por);

        $this->postJson('/api/v1/agenda/'.$item->id.'/reabrir')
            ->assertOk()
            ->assertJsonPath('data.compromisso.status', 'pendente');

        $this->assertNull($item->refresh()->concluido_em);
    }

    public function test_managed_appointment_ignores_date_and_title_edits(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Pagar: energia', 'inicio_em' => '2026-09-10 09:00:00',
            'status' => 'pendente', 'tipo' => 'conta_pagar',
            'origem_tipo' => 'conta_pagar', 'origem_id' => 42,
        ]);

        $this->patchJson('/api/v1/agenda/'.$item->id, [
            'titulo' => 'Outro titulo',
            'inicio_em' => '2027-01-01 08:00:00',
            'descricao' => 'minha observacao',
        ])->assertOk();

        $item->refresh();

        // Titulo e data pertencem ao modulo de origem...
        $this->assertSame('Pagar: energia', $item->titulo);
        $this->assertSame('2026-09-10', $item->inicio_em->toDateString());
        // ...mas a anotacao do usuario e dele.
        $this->assertSame('minha observacao', $item->descricao);
    }

    public function test_managed_appointment_cannot_be_deleted(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Prazo da OS 1', 'inicio_em' => '2026-09-10 08:00:00',
            'status' => 'pendente', 'tipo' => 'prazo_os',
            'origem_tipo' => 'prazo_os', 'origem_id' => 1,
        ]);

        $this->deleteJson('/api/v1/agenda/'.$item->id)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'AGENDA_DELETE_BLOCKED');

        $this->assertDatabaseHas('agenda_compromissos', ['id' => $item->id]);
    }

    public function test_manual_appointment_can_be_deleted(): void
    {
        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Lembrete', 'inicio_em' => '2026-09-10 09:00:00',
            'responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'manual',
        ]);

        $this->deleteJson('/api/v1/agenda/'.$item->id)->assertOk();

        $this->assertDatabaseMissing('agenda_compromissos', ['id' => $item->id]);
    }

    public function test_all_day_item_is_not_late_until_the_day_ends(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 15:30:00'));

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $base = ['responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'conta_pagar'];

        // Vence hoje, hora nominal 09:00, dia inteiro.
        $hoje = AgendaCompromisso::query()->create($base + [
            'titulo' => 'Pagar: vence hoje', 'inicio_em' => now()->setTime(9, 0),
            'dia_inteiro' => true, 'origem_tipo' => 'conta_pagar', 'origem_id' => 1,
        ]);
        // Venceu ontem.
        $ontem = AgendaCompromisso::query()->create($base + [
            'titulo' => 'Pagar: venceu ontem', 'inicio_em' => now()->subDay()->setTime(9, 0),
            'dia_inteiro' => true, 'origem_tipo' => 'conta_pagar', 'origem_id' => 2,
        ]);

        $items = collect(
            $this->getJson('/api/v1/agenda?de='.now()->subDays(2)->toDateString().'&ate='.now()->addDay()->toDateString())
                ->assertOk()
                ->json('data.compromissos')
        )->keyBy('id');

        $this->assertFalse($items[$hoje->id]['atrasado'], 'Vencimento de hoje não pode nascer atrasado.');
        $this->assertTrue($items[$ontem->id]['atrasado']);

        // O contador do topo precisa dar a MESMA resposta que a marca do item -
        // senão a tela se contradiz.
        $this->getJson('/api/v1/agenda/resumo')->assertJsonPath('data.atrasados', 1);
    }

    public function test_requires_permission(): void
    {
        $this->grantGroupPermissions(3, ['clientes' => ['visualizar']]);
        $semPermissao = $this->createUserRecord(['grupo_id' => 3, 'email' => 'sem@example.com']);

        Sanctum::actingAs($semPermissao, ['*']);

        $this->getJson('/api/v1/agenda')->assertForbidden();
    }

    public function test_summary_counts_late_today_and_next_week(): void
    {
        // Hora congelada: sem isto o item "Hoje às 15:00" conta como atrasado
        // quando a suite roda à noite, e o teste passa ou falha conforme o
        // relógio da máquina.
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00'));

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $base = [
            'responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'manual',
        ];

        AgendaCompromisso::query()->create($base + ['titulo' => 'Atrasado', 'inicio_em' => now()->subDays(3)]);
        AgendaCompromisso::query()->create($base + ['titulo' => 'Hoje', 'inicio_em' => now()->setTime(15, 0)]);
        // Vencimento de hoje: dia inteiro. Não é atrasado enquanto o dia não
        // acaba, mesmo que a hora nominal (09:00) já tenha passado.
        AgendaCompromisso::query()->create($base + [
            'titulo' => 'Vence hoje', 'inicio_em' => now()->setTime(9, 0), 'dia_inteiro' => true,
        ]);
        AgendaCompromisso::query()->create($base + ['titulo' => 'Semana', 'inicio_em' => now()->addDays(3)]);
        // Fora da janela dos 7 dias: nao pode contar em lugar nenhum dos tres.
        AgendaCompromisso::query()->create($base + ['titulo' => 'Longe', 'inicio_em' => now()->addDays(40)]);

        $this->getJson('/api/v1/agenda/resumo')
            ->assertOk()
            ->assertJsonPath('data.atrasados', 1)
            ->assertJsonPath('data.hoje', 2)
            ->assertJsonPath('data.proximos_7_dias', 1);
    }

    /**
     * `proximos` alimenta o painel de atenção do dashboard, não só o
     * contador. Sem teto de horizonte, num período calmo a lista buscava o
     * que fosse preciso lá na frente só para completar 5 itens — um boleto
     * a 3 semanas de distância aparecia com o mesmo peso visual de algo
     * realmente iminente.
     */
    public function test_proximos_list_stays_within_the_same_seven_day_horizon_as_the_counter(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00:00'));

        $admin = $this->createUserRecord(['grupo_id' => 1]);
        Sanctum::actingAs($admin, ['*']);

        $base = [
            'responsavel_id' => $admin->id, 'status' => 'pendente', 'tipo' => 'manual',
        ];

        AgendaCompromisso::query()->create($base + ['titulo' => 'Semana', 'inicio_em' => now()->addDays(3)]);
        AgendaCompromisso::query()->create($base + ['titulo' => 'Longe', 'inicio_em' => now()->addDays(40)]);

        $response = $this->getJson('/api/v1/agenda/resumo')->assertOk();

        $titulos = collect($response->json('data.proximos'))->pluck('titulo');

        $this->assertTrue($titulos->contains('Semana'));
        $this->assertFalse($titulos->contains('Longe'));
    }
}
