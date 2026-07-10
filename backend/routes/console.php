<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('app:process-pending-os-collections')->everyFifteenMinutes();
// Roda de hora em hora (nao so 1x/dia): o dedupe interno garante um aviso por
// OS/tipo/dia, e OS cujo prazo foi definido AO LONGO do proprio dia ainda
// recebem o aviso de "termina hoje".
Schedule::command('app:notify-order-deadlines')->hourly();
