<?php

namespace Tests\Feature\Agenda;

use App\Models\AgendaCompromisso;
use App\Services\Agenda\Google\AgendaEventPayload;
use App\Services\Agenda\Google\GoogleCalendarPullService;
use App\Services\Agenda\Google\GoogleCalendarPushService;
use App\Services\Agenda\Google\GoogleCalendarSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use App\Jobs\Agenda\PushAgendaCompromissoToGoogleJob;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsLegacyErpSchema;
use Tests\TestCase;

/**
 * Sync bidirecional com o calendario dedicado do Google.
 *
 * O caso mais importante aqui e o anti-loop: todo push nosso gera um "updated"
 * no Google, e sem a checagem de etag o pull seguinte leria esse update como
 * edicao do usuario e reescreveria o item - o que dispararia outro push, para
 * sempre.
 */
class GoogleCalendarSyncTest extends TestCase
{
    use BuildsLegacyErpSchema;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rebuildLegacySchema();
        $this->seedRbacCatalog();
        $this->connectGoogle();
    }

    private function connectGoogle(): void
    {
        $settings = app(GoogleCalendarSettingsService::class);
        $settings->put('agenda_google_client_id', 'client-id');
        $settings->put('agenda_google_client_secret', 'client-secret');
        $settings->put('agenda_google_refresh_token', 'refresh-token');
        $settings->put('agenda_google_calendar_id', 'calendario-erp@group.calendar.google.com');
    }

    private function fakeToken(array $extra = []): void
    {
        Http::fake(array_merge([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access-token', 'expires_in' => 3600,
            ]),
        ], $extra));
    }

    private function compromissoManual(array $overrides = []): AgendaCompromisso
    {
        return AgendaCompromisso::query()->create(array_merge([
            'titulo' => 'Ligar para o cliente',
            'inicio_em' => '2026-09-10 14:00:00',
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'tipo' => AgendaCompromisso::TIPO_MANUAL,
            'dia_inteiro' => false,
            'google_sync_estado' => AgendaCompromisso::SYNC_PENDENTE,
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Push
    // -----------------------------------------------------------------

    public function test_push_creates_the_event_and_stores_the_link(): void
    {
        $item = $this->compromissoManual();

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events' => Http::response([
                'id' => 'evento-123', 'etag' => '"etag-1"',
            ]),
        ]);

        app(GoogleCalendarPushService::class)->push((int) $item->id);

        $item->refresh();

        $this->assertSame('evento-123', $item->google_event_id);
        $this->assertSame('"etag-1"', $item->google_etag);
        $this->assertSame(AgendaCompromisso::SYNC_SINCRONIZADO, $item->google_sync_estado);
        $this->assertSame(AgendaEventPayload::contentHash($item), $item->google_sync_hash);
    }

    public function test_push_sends_the_reminder_that_makes_the_phone_ring(): void
    {
        $item = $this->compromissoManual(['lembrete_minutos' => 30]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events' => Http::response(['id' => 'e1', 'etag' => '"t1"']),
        ]);

        app(GoogleCalendarPushService::class)->push((int) $item->id);

        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/events')) {
                return false;
            }

            return ($request->data()['reminders']['overrides'][0]['minutes'] ?? null) === 30;
        });
    }

    public function test_all_day_event_ends_on_the_next_day(): void
    {
        // A Calendar API trata end.date como exclusivo: sem o +1 dia o evento
        // simplesmente nao aparece no calendario.
        $item = $this->compromissoManual([
            'dia_inteiro' => true,
            'inicio_em' => '2026-09-10 00:00:00',
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events' => Http::response(['id' => 'e1', 'etag' => '"t1"']),
        ]);

        app(GoogleCalendarPushService::class)->push((int) $item->id);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return ($data['start']['date'] ?? null) === '2026-09-10'
                && ($data['end']['date'] ?? null) === '2026-09-11';
        });
    }

    public function test_push_reinserts_when_the_event_was_deleted_in_google(): void
    {
        $item = $this->compromissoManual([
            'google_event_id' => 'evento-sumido',
            'google_etag' => '"velho"',
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events/*' => Http::response(['error' => ['message' => 'Not Found']], 404),
        ]);

        try {
            app(GoogleCalendarPushService::class)->push((int) $item->id);
        } catch (\Throwable) {
            // O job re-tenta; aqui interessa o estado que sobra na linha.
        }

        $item->refresh();

        // Vinculo limpo: a proxima tentativa cria um evento novo em vez de
        // insistir num id que nao existe mais.
        $this->assertNull($item->google_event_id);
        $this->assertSame(AgendaCompromisso::SYNC_ERRO, $item->google_sync_estado);
    }

    // -----------------------------------------------------------------
    // Pull
    // -----------------------------------------------------------------

    public function test_pull_imports_an_event_created_on_the_phone(): void
    {
        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-do-celular',
                    'etag' => '"etag-remoto"',
                    'status' => 'confirmed',
                    'summary' => 'Reunião com o contador',
                    'description' => 'Levar os balancetes',
                    'start' => ['dateTime' => '2026-09-15T09:00:00-03:00'],
                    'end' => ['dateTime' => '2026-09-15T10:00:00-03:00'],
                    'updated' => '2026-09-01T12:00:00Z',
                ]],
                'nextSyncToken' => 'token-1',
            ]),
        ]);

        $stats = app(GoogleCalendarPullService::class)->pull();

        $this->assertSame(1, $stats['importados']);

        $item = AgendaCompromisso::query()->where('google_event_id', 'evento-do-celular')->first();

        $this->assertNotNull($item);
        $this->assertSame('Reunião com o contador', $item->titulo);
        $this->assertSame(AgendaCompromisso::ORIGEM_GOOGLE, $item->origem_tipo);
        $this->assertFalse($item->isManaged());
        // O token da ultima pagina precisa ser guardado, senao a proxima
        // rodada refaz a carga completa.
        $this->assertSame('token-1', app(GoogleCalendarSettingsService::class)->get('agenda_google_sync_token'));
    }

    public function test_pull_ignores_the_echo_of_our_own_push(): void
    {
        // A trava anti-loop: mesmo etag = evento que nos mesmos acabamos de
        // escrever. Aplica-lo reescreveria o item e dispararia outro push.
        $item = $this->compromissoManual([
            'google_event_id' => 'evento-nosso',
            'google_etag' => '"etag-nosso"',
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
        ]);
        $updatedAt = $item->updated_at;

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-nosso',
                    'etag' => '"etag-nosso"',
                    'status' => 'confirmed',
                    'summary' => 'Título vindo do Google',
                    'start' => ['dateTime' => '2030-01-01T09:00:00-03:00'],
                    'end' => ['dateTime' => '2030-01-01T10:00:00-03:00'],
                    'updated' => '2030-01-01T09:00:00Z',
                ]],
                'nextSyncToken' => 'token-2',
            ]),
        ]);

        $stats = app(GoogleCalendarPullService::class)->pull();

        $item->refresh();

        $this->assertSame(1, $stats['ignorados']);
        $this->assertSame(0, $stats['atualizados']);
        $this->assertSame('Ligar para o cliente', $item->titulo);
        $this->assertEquals($updatedAt, $item->updated_at);
    }

    public function test_pull_applies_a_real_edit_made_on_the_phone(): void
    {
        $item = $this->compromissoManual([
            'google_event_id' => 'evento-nosso',
            'google_etag' => '"etag-antigo"',
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-nosso',
                    // Etag diferente do guardado: alteracao de verdade.
                    'etag' => '"etag-novo"',
                    'status' => 'confirmed',
                    'summary' => 'Ligar para o cliente (remarcado)',
                    'start' => ['dateTime' => '2026-09-11T16:00:00-03:00'],
                    'end' => ['dateTime' => '2026-09-11T17:00:00-03:00'],
                    'updated' => now()->addDay()->toIso8601String(),
                ]],
                'nextSyncToken' => 'token-3',
            ]),
        ]);

        $stats = app(GoogleCalendarPullService::class)->pull();

        $item->refresh();

        $this->assertSame(1, $stats['atualizados']);
        $this->assertSame('Ligar para o cliente (remarcado)', $item->titulo);
        $this->assertSame('2026-09-11 16:00', $item->inicio_em->format('Y-m-d H:i'));
    }

    public function test_managed_appointment_is_not_overwritten_by_the_phone(): void
    {
        // Arrastar o card do vencimento no celular nao pode mudar a data em que
        // a conta vence: o ERP reafirma a verdade empurrando de volta.
        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Pagar: energia',
            'inicio_em' => '2026-09-10 09:00:00',
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'tipo' => 'conta_pagar',
            'origem_tipo' => 'conta_pagar',
            'origem_id' => 7,
            'dia_inteiro' => true,
            'google_event_id' => 'evento-gerido',
            'google_etag' => '"etag-antigo"',
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-gerido',
                    'etag' => '"etag-novo"',
                    'status' => 'confirmed',
                    'summary' => 'Data que eu mudei no celular',
                    'start' => ['date' => '2027-05-05'],
                    'end' => ['date' => '2027-05-06'],
                    'updated' => now()->addDay()->toIso8601String(),
                ]],
                'nextSyncToken' => 'token-4',
            ]),
        ]);

        // Fila falsa para isolar o pull: sem isto o driver `sync` executaria o
        // push no meio do teste e mediria as duas direcoes de uma vez.
        Queue::fake();

        app(GoogleCalendarPullService::class)->pull();

        $item->refresh();

        $this->assertSame('Pagar: energia', $item->titulo);
        $this->assertSame('2026-09-10', $item->inicio_em->toDateString());
        $this->assertSame(AgendaCompromisso::SYNC_PENDENTE, $item->google_sync_estado);
        // E reafirma a verdade do ERP de volta no Google.
        Queue::assertPushed(PushAgendaCompromissoToGoogleJob::class);
    }

    public function test_pull_cancels_a_manual_item_deleted_on_the_phone(): void
    {
        $item = $this->compromissoManual([
            'google_event_id' => 'evento-apagado',
            'google_etag' => '"etag-antigo"',
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-apagado',
                    'etag' => '"etag-novo"',
                    'status' => 'cancelled',
                ]],
                'nextSyncToken' => 'token-5',
            ]),
        ]);

        $stats = app(GoogleCalendarPullService::class)->pull();

        $this->assertSame(1, $stats['cancelados']);
        $this->assertSame(AgendaCompromisso::STATUS_CANCELADO, $item->refresh()->status);
    }

    public function test_deleting_a_managed_event_on_the_phone_reinserts_it(): void
    {
        // Apagar o evento do celular nao faz a conta deixar de vencer.
        $item = AgendaCompromisso::query()->create([
            'titulo' => 'Prazo da OS 9',
            'inicio_em' => '2026-09-10 08:00:00',
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'tipo' => 'prazo_os',
            'origem_tipo' => 'prazo_os',
            'origem_id' => 9,
            'google_event_id' => 'evento-gerido-apagado',
            'google_etag' => '"etag-antigo"',
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
        ]);

        $this->fakeToken([
            '*/calendar/v3/calendars/*/events*' => Http::response([
                'items' => [[
                    'id' => 'evento-gerido-apagado',
                    'etag' => '"etag-novo"',
                    'status' => 'cancelled',
                ]],
                'nextSyncToken' => 'token-6',
            ]),
        ]);

        Queue::fake();

        app(GoogleCalendarPullService::class)->pull();

        $item->refresh();

        // A obrigacao continua de pe, sem vinculo com o evento apagado, e
        // marcada para ser reinserida no proximo push.
        $this->assertSame(AgendaCompromisso::STATUS_PENDENTE, $item->status);
        $this->assertNull($item->google_event_id);
        $this->assertSame(AgendaCompromisso::SYNC_PENDENTE, $item->google_sync_estado);
        Queue::assertPushed(PushAgendaCompromissoToGoogleJob::class);
    }

    public function test_expired_sync_token_triggers_a_full_resync(): void
    {
        app(GoogleCalendarSettingsService::class)->put('agenda_google_sync_token', 'token-expirado');

        $chamadas = 0;

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            '*/calendar/v3/calendars/*/events*' => function () use (&$chamadas) {
                $chamadas++;

                // Primeira chamada (com syncToken) devolve 410 Gone; a segunda
                // e a carga completa.
                return $chamadas === 1
                    ? Http::response(['error' => ['message' => 'Sync token is no longer valid']], 410)
                    : Http::response([
                        'items' => [[
                            'id' => 'evento-recarregado',
                            'etag' => '"e"',
                            'status' => 'confirmed',
                            'summary' => 'Depois do resync',
                            'start' => ['dateTime' => '2026-09-20T09:00:00-03:00'],
                            'end' => ['dateTime' => '2026-09-20T10:00:00-03:00'],
                            'updated' => '2026-09-01T12:00:00Z',
                        ]],
                        'nextSyncToken' => 'token-novo',
                    ]);
            },
        ]);

        $stats = app(GoogleCalendarPullService::class)->pull();

        $this->assertSame(2, $chamadas);
        $this->assertSame(1, $stats['importados']);
        $this->assertSame('token-novo', app(GoogleCalendarSettingsService::class)->get('agenda_google_sync_token'));
    }

    public function test_does_nothing_when_google_is_not_connected(): void
    {
        app(GoogleCalendarSettingsService::class)->disconnect();
        Http::fake();

        $stats = app(GoogleCalendarPullService::class)->pull();

        $this->assertSame(0, $stats['importados']);
        Http::assertNothingSent();
    }

    public function test_status_expoe_o_email_da_conta_vinculada(): void
    {
        app(GoogleCalendarSettingsService::class)->put('agenda_google_conta_email', 'assistencia@jovemtech.eco.br');

        $this->seedIntegrationsPermission();
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->getJson('/api/v1/agenda/google/status')
            ->assertOk()
            ->assertJsonPath('data.summary.conta_email', 'assistencia@jovemtech.eco.br')
            ->assertJsonPath('data.summary.connected', true);
    }

    public function test_email_ausente_e_recuperado_na_leitura_do_status(): void
    {
        // A captura no momento de conectar pode falhar em silêncio (rede, ou
        // consentimento sem o escopo de e-mail). Sem esta recuperação, a tela
        // mostraria "—" para sempre e só desconectar resolveria.
        app(GoogleCalendarSettingsService::class)->put('agenda_google_conta_email', '');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            '*/oauth2/v3/userinfo*' => Http::response(['email' => 'recuperado@jovemtech.eco.br']),
        ]);

        $this->seedIntegrationsPermission();
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->getJson('/api/v1/agenda/google/status')
            ->assertOk()
            ->assertJsonPath('data.summary.conta_email', 'recuperado@jovemtech.eco.br');

        // E fica gravado: a próxima leitura não repete a chamada externa.
        $this->assertSame(
            'recuperado@jovemtech.eco.br',
            app(GoogleCalendarSettingsService::class)->get('agenda_google_conta_email')
        );
    }

    public function test_falha_ao_buscar_email_nao_derruba_o_status_nem_apaga_o_conhecido(): void
    {
        app(GoogleCalendarSettingsService::class)->put('agenda_google_conta_email', 'conhecido@jovemtech.eco.br');

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([], 500),
            '*' => Http::response([], 500),
        ]);

        $this->seedIntegrationsPermission();
        Sanctum::actingAs($this->createUserRecord(['grupo_id' => 1]), ['*']);

        $this->getJson('/api/v1/agenda/google/status')
            ->assertOk()
            ->assertJsonPath('data.summary.conta_email', 'conhecido@jovemtech.eco.br');
    }

    private function seedIntegrationsPermission(): void
    {
        $this->grantGroupPermissions(1, ['configuracoes' => ['visualizar', 'editar']]);
    }

    public function test_refresh_token_is_stored_encrypted(): void
    {
        // Um dump da tabela `configuracoes` nao pode dar acesso de escrita a
        // agenda da empresa.
        $bruto = (string) \Illuminate\Support\Facades\DB::table('configuracoes')
            ->where('chave', 'agenda_google_refresh_token')
            ->value('valor');

        $this->assertNotSame('refresh-token', $bruto);
        $this->assertSame('refresh-token', app(GoogleCalendarSettingsService::class)->get('agenda_google_refresh_token'));
    }
}
