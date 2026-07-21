<?php

use App\Jobs\DispatchDocumentSignatureAssignmentJob;
use App\Models\DocumentSignatureDelivery;
use App\Models\DocumentSignatureRequest;
use App\Models\Order;
use App\Models\User;
use App\Services\Signatures\DocumentSignatureAssignmentNotifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:dispatch-pending-document-signature-notifications', function (): void {
    if (! Schema::hasTable('documento_assinatura_notificacoes')) {
        $this->info('Infraestrutura de avisos de assinatura ainda não migrada.');

        return;
    }

    $notifier = app(DocumentSignatureAssignmentNotifier::class);
    $backfilled = 0;

    DocumentSignatureRequest::query()
        ->with(['order', 'requester', 'responsibleUser'])
        ->where('status', 'pendente')
        ->where(function ($query): void {
            $query->whereNull('expira_em')->orWhere('expira_em', '>', now());
        })
        ->whereDoesntHave('notificationDeliveries', function ($query): void {
            $query->where('canal', 'in_app')->where('status', 'enviada');
        })
        ->orderBy('id')
        ->limit(100)
        ->get()
        ->each(function (DocumentSignatureRequest $request) use ($notifier, &$backfilled): void {
            if (! ($request->order instanceof Order)
                || ! ($request->requester instanceof User)
                || ! ($request->responsibleUser instanceof User)) {
                return;
            }

            $notifier->notifyAssignments(
                [$request],
                $request->order,
                $request->requester,
                $request->responsibleUser
            );
            $backfilled++;
        });

    $requestIds = DocumentSignatureDelivery::query()
        ->whereIn('canal', ['email', 'whatsapp'])
        ->whereIn('status', ['pendente', 'falha'])
        ->where('tentativas', '<', 3)
        ->where('updated_at', '<=', now()->subMinutes(2))
        ->distinct()
        ->limit(100)
        ->pluck('solicitacao_id');

    foreach ($requestIds as $requestId) {
        DispatchDocumentSignatureAssignmentJob::dispatch((int) $requestId);
    }

    $this->info(sprintf(
        '%d solicitação(ões) anterior(es) recuperada(s); %d aviso(s) externo(s) reenfileirado(s).',
        $backfilled,
        $requestIds->count()
    ));
})->purpose('Recupera designações sem aviso e reenfileira falhas transitórias.');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('app:process-pending-os-collections')->everyFifteenMinutes();
// Roda de hora em hora (nao so 1x/dia): o dedupe interno garante um aviso por
// OS/tipo/dia, e OS cujo prazo foi definido AO LONGO do proprio dia ainda
// recebem o aviso de "termina hoje".
Schedule::command('app:notify-order-deadlines')->hourly();
Schedule::command('app:dispatch-pending-document-signature-notifications')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

if ((bool) config('file-manager.automatic_sync.enabled', false)) {
    $fileSyncInterval = (int) config('file-manager.automatic_sync.interval_minutes', 5);
    Schedule::command('file-manager:sync --pending')
        ->everyMinute()
        ->name('file-manager-manual-sync')
        ->withoutOverlapping(10);
    Schedule::command('file-manager:sync')
        ->cron('*/'.$fileSyncInterval.' * * * *')
        ->name('file-manager-automatic-sync')
        ->withoutOverlapping(60);
}

Schedule::command('file-manager:purge-trash')
    ->dailyAt('02:30')
    ->name('file-manager-trash-retention')
    ->onOneServer()
    ->withoutOverlapping(180);
