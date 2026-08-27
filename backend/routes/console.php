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

// Certificado do Banco Inter: a falha classica desta integracao e' o
// certificado vencer e tudo parar em silencio. O alerta antecipado (D-30,
// D-15, D-7, D-1) e' o que troca "cliente ligou reclamando" por "avisado com
// um mes de antecedencia". O proprio comando deduplica, entao rodar todo dia
// nao vira spam.
Schedule::command('inter:verificar-certificado --alertar')
    ->dailyAt('07:10')
    ->withoutOverlapping();

// Conferencia das cobrancas Pix abertas. Este e' o caminho PRINCIPAL de baixa,
// nao um plano B: funciona sem webhook, sem porta aberta e sem VPS. O webhook
// (Fase 6) so' reduz a latencia de ~15 min para ~1 min.
Schedule::command('inter:conciliar')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
Schedule::command('app:process-pending-os-collections')->everyFifteenMinutes();
// Roda de hora em hora (nao so 1x/dia): o dedupe interno garante um aviso por
// OS/tipo/dia, e OS cujo prazo foi definido AO LONGO do proprio dia ainda
// recebem o aviso de "termina hoje".
Schedule::command('app:notify-order-deadlines')->hourly();
// De hora em hora: a validade fecha no fim do dia, mas o token pode vencer em
// qualquer horario, e o painel nao pode continuar mostrando "Aguardando
// resposta" num orcamento cujo link publico ja devolve 410.
Schedule::command('app:expire-budgets')
    ->hourly()
    ->name('expire-stale-budgets')
    ->onOneServer()
    ->withoutOverlapping(10);
Schedule::command('app:dispatch-pending-document-signature-notifications')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);

// ---------------------------------------------------------------------------
// Agenda
// ---------------------------------------------------------------------------
// Reconciliacao das origens (vencimentos, prazos, retornos, cobrancas). E
// idempotente por construcao - compara fonte com agenda e converge -, entao
// rodar de 15 em 15 minutos nao duplica nada e mantem a agenda coerente com o
// financeiro no mesmo dia em que o titulo e lancado.
Schedule::command('agenda:sincronizar-origens')
    ->everyFifteenMinutes()
    ->name('agenda-reconciliar-origens')
    ->onOneServer()
    ->withoutOverlapping(20);

// Sincronizacao com o Google. Cinco minutos e o compromisso entre "o que criei
// no celular aparece rapido no ERP" e o volume de chamadas a Calendar API; a
// leitura e incremental (syncToken), entao cada tique custa uma requisicao
// quando nada mudou. Sai cedo sozinha quando nao ha conexao configurada.
Schedule::command('agenda:sincronizar-google')
    ->everyFiveMinutes()
    ->name('agenda-sincronizar-google')
    ->onOneServer()
    ->withoutOverlapping(10);

// Rede de segurança operacional: o Supervisor continua sendo o consumidor
// principal. Este worker curto impede que uma implantação parcial deixe as filas
// paradas indefinidamente após o Supervisor entrar em FATAL/BACKOFF.
Schedule::command('queue:work --queue=documents,default --max-jobs=50 --max-time=55 --sleep=1 --tries=3 --timeout=120')
    ->everyMinute()
    ->name('queue-supervisor-fallback')
    ->onOneServer()
    ->withoutOverlapping(10)
    ->runInBackground();

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

// ---------------------------------------------------------------------------
// Backup completo do sistema
// ---------------------------------------------------------------------------
// Nao usa fila de proposito: retry_after=180 na conexao redis faria um backup
// de mais de 3 minutos ser re-reservado e rodar DUAS VEZES em paralelo, e o
// Supervisor so consome as filas "documents,default". O scheduler ja roda a
// cada minuto como www-data, e runInBackground() destaca o processo - sem teto
// de 60s do FPM e sem disputar worker com a entrega de documentos.
if ((bool) config('backup.enabled', true)) {
    // Atende ao botao "Gerar backup agora": a API grava uma linha pendente e
    // este tique a executa em ate 60s. Mesmo idioma de file-manager:sync --pending.
    Schedule::command('backup:executar --pendente')
        ->everyMinute()
        ->name('backup-pendente')
        ->onOneServer()
        ->withoutOverlapping(240)
        ->runInBackground();

    // 02:00 (cron de root) e 02:30 (file-manager:purge-trash) ja estao ocupados.
    Schedule::command('backup:executar --tipo=completo')
        ->dailyAt((string) config('backup.schedule.daily_time', '03:15'))
        ->name('backup-diario')
        ->onOneServer()
        ->withoutOverlapping(240)
        ->runInBackground();

    Schedule::command('backup:expurgar')
        ->dailyAt((string) config('backup.schedule.prune_time', '03:50'))
        ->name('backup-retencao')
        ->onOneServer()
        ->withoutOverlapping(60);

    // O painel e o catalogo unico: varre o disco e cataloga tambem os backups
    // que este sistema nao gerou (dumps do cron de root, pre-deploy, manuais).
    Schedule::command('backup:varrer')
        ->everyFifteenMinutes()
        ->name('backup-catalogo')
        ->withoutOverlapping(10);
}
