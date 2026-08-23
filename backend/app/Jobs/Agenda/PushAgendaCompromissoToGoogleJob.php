<?php

namespace App\Jobs\Agenda;

use App\Services\Agenda\Google\GoogleCalendarPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Empurra um compromisso para o Google Agenda.
 *
 * ShouldBeUnique por compromisso: editar o mesmo item tres vezes em dez
 * segundos nao pode virar tres chamadas concorrentes a Calendar API - duas
 * delas criariam eventos duplicados antes de a primeira gravar o
 * google_event_id.
 */
class PushAgendaCompromissoToGoogleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $compromissoId
    ) {
        $this->onQueue('default');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function uniqueId(): string
    {
        return 'agenda-google-push:'.$this->compromissoId;
    }

    public function handle(GoogleCalendarPushService $push): void
    {
        $push->push($this->compromissoId);
    }
}
