<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;

// ============================================================================
// 1. MANUTENZIONE DATABASE (Garbage Collector)
// ============================================================================
Schedule::call(function () {
    Artisan::call('model:prune');
})->name('system-prune')->daily(); 

// ============================================================================
// 2. CONTROLLO AGGIORNAMENTI SISTEMA (Notifica Badge)
// ============================================================================
Schedule::call(function () {
    Artisan::call('system:check-updates');
})->name('check-updates')->dailyAt('04:00')->withoutOverlapping(); 

// ============================================================================
// 3. CONTROLLO AGGIORNAMENTI IP CRON-JOB.ORG
// ============================================================================
Schedule::call(function () {
    Artisan::call('cronjob:update-ips');
})->name('update-cron-ips')->dailyAt('05:00')->withoutOverlapping(); 

// ============================================================================
// 4. WORKER PER HOSTING CONDIVISI (Logica "Svuota e Spegni")
// ============================================================================
if (config('app.scheduler_queue_worker')) {
    Schedule::call(function () {
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 55,
            '--tries' => 3,
            '--backoff' => 10,
        ]);
    })->name('sync-queue-worker')->everyMinute()->withoutOverlapping(); // <-- Aggiunto ->name('sync-queue-worker')
}