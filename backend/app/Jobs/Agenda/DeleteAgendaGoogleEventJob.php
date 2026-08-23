<?php

namespace App\Jobs\Agenda;

use App\Services\Agenda\Google\GoogleCalendarPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Remove do Google o evento de um compromisso ja apagado localmente.
 *
 * Carrega o id do evento em vez do modelo justamente porque a linha local nao
 * existe mais quando este job roda.
 */
class DeleteAgendaGoogleEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $googleEventId
    ) {
        $this->onQueue('default');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(GoogleCalendarPushService $push): void
    {
        $push->deleteEvent($this->googleEventId);
    }
}
