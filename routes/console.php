<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 1. PULIZIA AUTOMATICA (Garbage Collector)
// Esegue il pruning dei modelli (es. Eventi vecchi di 2 anni) ogni notte.
Schedule::command('model:prune')->daily();

// 2. WORKER PER HOSTING CONDIVISI (Logica "Svuota e Spegni")
// Si attiva solo se configurato in config/app.php o .env
if (config('app.scheduler_queue_worker')) {
    Schedule::command('queue:work --stop-when-empty --time-limit=55')
        ->everyMinute()
        ->withoutOverlapping();
}
