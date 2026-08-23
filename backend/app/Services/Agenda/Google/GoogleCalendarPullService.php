<?php

namespace App\Services\Agenda\Google;

use App\Models\AgendaCompromisso;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Direcao Google -> ERP, restrita ao calendario dedicado.
 *
 * Le SO o calendario que o proprio ERP criou. Os calendarios pessoais de quem
 * conectou a conta nao aparecem aqui - e nem poderiam: o escopo concedido
 * (calendar.app.created) nao da acesso a eles.
 *
 * Tres decisoes que sustentam o sync bidirecional:
 *
 *  1. ANTI-LOOP. Todo push nosso gera um "updated" no Google. Sem tratamento, o
 *     pull seguinte leria esse update como edicao do usuario e reescreveria o
 *     item, o que dispararia outro push - para sempre. O corte e o etag: se o
 *     etag recebido e exatamente o que gravamos no ultimo push, o evento e eco
 *     nosso e nao ha nada a aplicar.
 *
 *  2. AUTORIDADE. Item gerido por uma origem (vencimento, prazo de OS) nao
 *     aceita edicao vinda do celular: arrastar o card la nao pode mudar a data
 *     de vencimento de uma conta. O ERP reafirma a verdade empurrando de volta.
 *
 *  3. CONFLITO EM ITEM MANUAL. Vence quem editou por ultimo, comparando o
 *     `updated` do Google com o `updated_at` local.
 */
class GoogleCalendarPullService
{
    /** Janela da carga completa (primeira sincronizacao ou syncToken expirado). */
    private const FULL_SYNC_DAYS_BACK = 90;

    private const PAGE_LIMIT = 20;

    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleCalendarSettingsService $settings,
        private readonly GoogleCalendarSyncDispatcher $dispatcher
    ) {}

    /** @return array{importados: int, atualizados: int, cancelados: int, ignorados: int} */
    public function pull(): array
    {
        $stats = ['importados' => 0, 'atualizados' => 0, 'cancelados' => 0, 'ignorados' => 0];

        if (! $this->settings->isConnected()) {
            return $stats;
        }

        $calendarId = $this->settings->get('agenda_google_calendar_id');
        $syncToken = $this->settings->get('agenda_google_sync_token');

        try {
            $this->consume($calendarId, $syncToken, $stats);
        } catch (GoogleSyncTokenExpiredException) {
            // Nao e falha: o Google invalidou o token. Refaz a carga completa.
            Log::info('Sync token do Google Agenda expirado; refazendo carga completa.');
            $this->settings->put('agenda_google_sync_token', '');
            $this->consume($calendarId, '', $stats);
        }

        return $stats;
    }

    /** @param array<string, int> $stats */
    private function consume(string $calendarId, string $syncToken, array &$stats): void
    {
        $pageToken = '';
        $pages = 0;

        do {
            $query = ['showDeleted' => 'true', 'singleEvents' => 'true', 'maxResults' => 250];

            if ($syncToken !== '') {
                // Incremental: o Google proibe combinar syncToken com filtros de
                // tempo, entao a janela so existe na carga completa.
                $query['syncToken'] = $syncToken;
            } else {
                $query['timeMin'] = CarbonImmutable::now()
                    ->subDays(self::FULL_SYNC_DAYS_BACK)
                    ->startOfDay()
                    ->toRfc3339String();
            }

            if ($pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->client->listEvents($calendarId, $query);

            foreach ($response['items'] ?? [] as $event) {
                if (is_array($event)) {
                    $this->applyEvent($event, $stats);
                }
            }

            $pageToken = trim((string) ($response['nextPageToken'] ?? ''));
            $nextSyncToken = trim((string) ($response['nextSyncToken'] ?? ''));

            // O token so vem na ultima pagina - guardar antes disso perderia os
            // eventos das paginas restantes na proxima rodada.
            if ($nextSyncToken !== '') {
                $this->settings->put('agenda_google_sync_token', $nextSyncToken);
            }

            $pages++;
        } while ($pageToken !== '' && $pages < self::PAGE_LIMIT);
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, int> $stats
     */
    private function applyEvent(array $event, array &$stats): void
    {
        $eventId = trim((string) ($event['id'] ?? ''));
        if ($eventId === '') {
            return;
        }

        $item = $this->locate($event, $eventId);
        $cancelled = trim((string) ($event['status'] ?? '')) === 'cancelled';

        if (! $item instanceof AgendaCompromisso) {
            if ($cancelled) {
                // Evento criado e apagado no celular entre duas rodadas: nunca
                // existiu aqui, nao ha o que fazer.
                $stats['ignorados']++;

                return;
            }

            $this->createFromGoogle($event, $eventId);
            $stats['importados']++;

            return;
        }

        // (1) Eco do nosso proprio push.
        $etag = trim((string) ($event['etag'] ?? ''));
        if ($etag !== '' && $etag === trim((string) $item->google_etag)) {
            $stats['ignorados']++;

            return;
        }

        if ($cancelled) {
            $this->applyRemoteCancellation($item, $stats);

            return;
        }

        // (2) O ERP e autoritativo em item gerido: devolve a verdade.
        if ($item->isManaged()) {
            $item->forceFill(['google_sync_estado' => AgendaCompromisso::SYNC_PENDENTE])->saveQuietly();
            $this->dispatcher->queue($item);
            $stats['ignorados']++;

            return;
        }

        // (3) Item manual: vence a edicao mais recente.
        $remoteUpdated = $this->parseDate($event['updated'] ?? null);
        if ($remoteUpdated !== null
            && $item->updated_at !== null
            && $remoteUpdated->lessThanOrEqualTo(CarbonImmutable::parse($item->updated_at))) {
            $stats['ignorados']++;

            return;
        }

        $item->forceFill($this->attributesFromEvent($event, $eventId))->saveQuietly();
        $stats['atualizados']++;
    }

    /**
     * Cancelar, nao apagar: o item pode ter historico (foi concluido, tem
     * vinculo com cliente/OS) e sumir com ele apagaria o rastro.
     *
     * @param array<string, int> $stats
     */
    private function applyRemoteCancellation(AgendaCompromisso $item, array &$stats): void
    {
        if ($item->isManaged()) {
            // Apagar do celular nao mata a obrigacao: a conta continua a vencer.
            // Reinsere o evento no proximo push.
            $item->forceFill([
                'google_event_id' => null,
                'google_etag' => null,
                'google_sync_hash' => null,
                'google_sync_estado' => AgendaCompromisso::SYNC_PENDENTE,
            ])->saveQuietly();
            $this->dispatcher->queue($item);
            $stats['ignorados']++;

            return;
        }

        $item->forceFill([
            'status' => AgendaCompromisso::STATUS_CANCELADO,
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
            'google_sincronizado_em' => now(),
        ])->saveQuietly();

        $stats['cancelados']++;
    }

    /** @param array<string, mixed> $event */
    private function createFromGoogle(array $event, string $eventId): void
    {
        try {
            AgendaCompromisso::query()->create(array_merge(
                $this->attributesFromEvent($event, $eventId),
                [
                    'tipo' => AgendaCompromisso::TIPO_MANUAL,
                    'origem_tipo' => AgendaCompromisso::ORIGEM_GOOGLE,
                    'origem_id' => null,
                    'prioridade' => AgendaCompromisso::PRIORIDADE_NORMAL,
                    // Sem dono: quem criou pelo celular usou a conta da
                    // empresa, que nao identifica um usuario do ERP. Item sem
                    // responsavel e visivel para todos (ver scopeVisiveisPara).
                    'responsavel_id' => null,
                ]
            ));
        } catch (Throwable $exception) {
            // Corrida com outra rodada do comando: a unique de google_event_id
            // fez o trabalho dela.
            Log::info('Evento do Google ignorado na importação.', [
                'google_event_id' => $eventId,
                'erro' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function attributesFromEvent(array $event, string $eventId): array
    {
        $start = is_array($event['start'] ?? null) ? $event['start'] : [];
        $end = is_array($event['end'] ?? null) ? $event['end'] : [];

        $allDay = trim((string) ($start['date'] ?? '')) !== '';

        $startAt = $allDay
            ? CarbonImmutable::parse((string) $start['date'])->startOfDay()
            : $this->parseDate($start['dateTime'] ?? null);

        $endAt = $allDay
            // `end.date` do Google e exclusivo: volta um dia para virar o
            // ultimo dia real do evento.
            ? ($this->parseDate($end['date'] ?? null)?->subDay()->endOfDay())
            : $this->parseDate($end['dateTime'] ?? null);

        $startAt ??= CarbonImmutable::now();

        $titulo = trim((string) ($event['summary'] ?? ''));

        return [
            'titulo' => mb_substr($titulo !== '' ? $titulo : 'Compromisso sem título', 0, 180),
            'descricao' => trim((string) ($event['description'] ?? '')) ?: null,
            'inicio_em' => $startAt,
            'fim_em' => $endAt,
            'dia_inteiro' => $allDay,
            'status' => AgendaCompromisso::STATUS_PENDENTE,
            'google_event_id' => $eventId,
            'google_etag' => trim((string) ($event['etag'] ?? '')) ?: null,
            'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
            'google_sync_erro' => null,
            'google_sincronizado_em' => now(),
        ];
    }

    /** @param array<string, mixed> $event */
    private function locate(array $event, string $eventId): ?AgendaCompromisso
    {
        $item = AgendaCompromisso::query()->where('google_event_id', $eventId)->first();

        if ($item instanceof AgendaCompromisso) {
            return $item;
        }

        // Rede de seguranca: o push gravou o evento la mas morreu antes de
        // salvar o id aqui. A marca que deixamos no proprio evento reata o
        // vinculo em vez de gerar um duplicado.
        $marked = (int) data_get($event, 'extendedProperties.private.erp_compromisso_id');

        return $marked > 0
            ? AgendaCompromisso::query()->whereNull('google_event_id')->find($marked)
            : null;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
