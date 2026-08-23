<?php

namespace App\Console\Commands\Agenda;

use App\Services\Agenda\Google\GoogleCalendarPullService;
use App\Services\Agenda\Google\GoogleCalendarPushService;
use App\Services\Agenda\Google\GoogleCalendarSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncAgendaWithGoogle extends Command
{
    protected $signature = 'agenda:sincronizar-google';

    protected $description = 'Sincroniza a agenda com o calendario dedicado do Google (nos dois sentidos).';

    public function handle(
        GoogleCalendarSettingsService $settings,
        GoogleCalendarPushService $push,
        GoogleCalendarPullService $pull
    ): int {
        if (! Schema::hasTable('agenda_compromissos')) {
            return self::SUCCESS;
        }

        if (! $settings->isConnected()) {
            $this->line('Google Agenda não conectado — nada a sincronizar.');

            return self::SUCCESS;
        }

        try {
            // Push primeiro: assim o pull ja encontra o etag do que acabou de
            // subir e reconhece o proprio eco, em vez de reimportar.
            $pushed = $push->pushPending();
            $stats = $pull->pull();

            $settings->recordSyncResult('sucesso');

            $this->info(sprintf(
                'Enviados: %d | Importados: %d | Atualizados: %d | Cancelados: %d | Ignorados: %d',
                $pushed,
                $stats['importados'],
                $stats['atualizados'],
                $stats['cancelados'],
                $stats['ignorados']
            ));
        } catch (Throwable $exception) {
            // O estado da falha fica visivel na tela de integracoes; sem isso o
            // usuario so descobriria que parou de sincronizar pela ausencia de
            // notificacao no celular.
            $settings->recordSyncResult('erro', $exception->getMessage());
            report($exception);

            $this->error('Falha na sincronização com o Google: '.$exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
