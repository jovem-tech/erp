<?php

namespace App\Services\Agenda\Google;

use App\Jobs\Agenda\DeleteAgendaGoogleEventJob;
use App\Jobs\Agenda\PushAgendaCompromissoToGoogleJob;
use App\Models\AgendaCompromisso;

/**
 * Ponto unico entre "a agenda mudou" e "o Google precisa saber".
 *
 * Isolado num dispatcher para que AgendaService e o reconciliador nao precisem
 * saber se ha conexao ativa, nem depender das classes de job - o que tambem
 * mantem os testes de agenda rodando sem tocar em fila nem em HTTP.
 */
class GoogleCalendarSyncDispatcher
{
    public function __construct(
        private readonly GoogleCalendarSettingsService $settings
    ) {}

    public function queue(AgendaCompromisso $item): void
    {
        if (! $this->settings->isConnected()) {
            // Sem conexao o item nao fica "pendente" para sempre acumulando
            // fila: marca desligado e segue. Ao conectar, o comando de
            // sincronizacao varre e empurra o que estiver nesse estado.
            $item->forceFill(['google_sync_estado' => AgendaCompromisso::SYNC_DESLIGADO])->saveQuietly();

            return;
        }

        $hash = AgendaEventPayload::contentHash($item);

        // Nada relevante mudou para o Google (ex.: so o campo interno de quem
        // concluiu). Empurrar de novo geraria um "updated" la, que o pull
        // seguinte leria como alteracao do usuario - o loop que essa checagem
        // existe para cortar.
        if ($item->google_sync_hash === $hash
            && $item->google_sync_estado === AgendaCompromisso::SYNC_SINCRONIZADO) {
            return;
        }

        $item->forceFill(['google_sync_estado' => AgendaCompromisso::SYNC_PENDENTE])->saveQuietly();

        PushAgendaCompromissoToGoogleJob::dispatch((int) $item->id);
    }

    public function queueDeletion(AgendaCompromisso $item): void
    {
        $eventId = trim((string) $item->google_event_id);

        if ($eventId === '' || ! $this->settings->isConnected()) {
            return;
        }

        // Leva o id do evento, nao o modelo: quando o job rodar a linha local
        // ja nao existe mais.
        DeleteAgendaGoogleEventJob::dispatch($eventId);
    }
}
