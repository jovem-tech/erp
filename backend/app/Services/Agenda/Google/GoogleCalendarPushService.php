<?php

namespace App\Services\Agenda\Google;

use App\Models\AgendaCompromisso;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Direcao ERP -> Google.
 *
 * Grava, no proprio compromisso, o etag e o hash do que foi empurrado. Esses
 * dois campos sao o que impede o sync bidirecional de entrar em loop: o pull
 * reconhece o eco do nosso proprio push e o ignora (ver GoogleCalendarPullService).
 */
class GoogleCalendarPushService
{
    public function __construct(
        private readonly GoogleCalendarClient $client,
        private readonly GoogleCalendarSettingsService $settings
    ) {}

    public function push(int $compromissoId): void
    {
        $item = AgendaCompromisso::query()->find($compromissoId);

        if (! $item instanceof AgendaCompromisso || ! $this->settings->isConnected()) {
            return;
        }

        $calendarId = $this->settings->get('agenda_google_calendar_id');
        $payload = AgendaEventPayload::toGoogleEvent($item);

        try {
            $eventId = trim((string) $item->google_event_id);

            $event = $eventId === ''
                ? $this->client->insertEvent($calendarId, $payload)
                : $this->client->patchEvent($calendarId, $eventId, $payload);

            // Coalesce para null, nunca string vazia: a coluna tem unique, e
            // duas linhas com '' colidiriam entre si na segunda gravacao.
            $savedEventId = trim((string) ($event['id'] ?? $eventId));
            $savedEtag = trim((string) ($event['etag'] ?? ''));

            $item->forceFill([
                'google_event_id' => $savedEventId !== '' ? $savedEventId : null,
                'google_etag' => $savedEtag !== '' ? $savedEtag : null,
                'google_sync_hash' => AgendaEventPayload::contentHash($item),
                'google_sync_estado' => AgendaCompromisso::SYNC_SINCRONIZADO,
                'google_sync_erro' => null,
                'google_sincronizado_em' => now(),
            ])->saveQuietly();
        } catch (Throwable $exception) {
            // Evento apagado direto no Google: reinsere na proxima tentativa em
            // vez de ficar tentando dar patch num id que nao existe mais.
            if ($this->isMissingEvent($exception)) {
                $item->forceFill([
                    'google_event_id' => null,
                    'google_etag' => null,
                    'google_sync_hash' => null,
                ])->saveQuietly();
            }

            $item->forceFill([
                'google_sync_estado' => AgendaCompromisso::SYNC_ERRO,
                'google_sync_erro' => mb_substr($exception->getMessage(), 0, 500),
            ])->saveQuietly();

            Log::warning('Falha ao empurrar compromisso para o Google Agenda.', [
                'compromisso_id' => $compromissoId,
                'erro' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function deleteEvent(string $googleEventId): void
    {
        if (! $this->settings->isConnected()) {
            return;
        }

        $this->client->deleteEvent($this->settings->get('agenda_google_calendar_id'), $googleEventId);
    }

    /**
     * Varre o que ficou para tras - itens marcados enquanto a conexao estava
     * desligada, ou cujo push falhou. Chamado ao conectar e pelo comando de
     * sincronizacao.
     */
    public function pushPending(int $limit = 200): int
    {
        if (! $this->settings->isConnected()) {
            return 0;
        }

        $pending = AgendaCompromisso::query()
            ->whereIn('google_sync_estado', [
                AgendaCompromisso::SYNC_PENDENTE,
                AgendaCompromisso::SYNC_DESLIGADO,
                AgendaCompromisso::SYNC_ERRO,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $pushed = 0;

        foreach ($pending as $id) {
            try {
                $this->push((int) $id);
                $pushed++;
            } catch (Throwable) {
                // Ja registrado na linha e no log por push(). Um item com
                // problema nao pode interromper a varredura dos demais.
                continue;
            }
        }

        return $pushed;
    }

    private function isMissingEvent(Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), 'HTTP 404')
            || str_contains($exception->getMessage(), 'HTTP 410');
    }
}
