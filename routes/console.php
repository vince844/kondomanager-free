<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Restore\RestoreMode;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// ============================================================================
// 0. HEARTBEAT — prova di vita dello scheduler.
// ============================================================================
// Registrato per PRIMO, fuori da ogni if, SENZA withoutOverlapping.
Schedule::call(function () {
    // 'system' se schedule:run gira da CLI (cron nativo Plesk),
    // 'webhook' se invocato via /system/run-scheduler (cron-job.org)
    $source = PHP_SAPI === 'cli' ? 'system' : 'webhook';

    Cache::put('system_cron_heartbeat', now()->timestamp, now()->addMinutes(10));
    Cache::put('system_cron_source', $source, now()->addMinutes(10));
})->name('system-heartbeat')->everyMinute();

// ============================================================================
// 1. MANUTENZIONE DATABASE (Garbage Collector)
// ============================================================================
Schedule::call(function () {
    Artisan::call('model:prune');
})->name('system-prune')->daily(); 

// ============================================================================
// 1-bis. CACHE SCADUTA SUL DATABASE (1.11.0-beta.32)
// ============================================================================
// Con CACHE_STORE=database (l'immagine Docker) una riga scaduta sparisce solo quando qualcuno la
// rilegge: quelle che nessuno rilegge restano per sempre. Una pulizia a settimana; la tabella
// c'è in ogni installazione (migrazione di base), quindi gira ovunque e non costa niente dove la
// cache è su file. `expiration` è un timestamp Unix. Le sessioni non hanno bisogno di questo:
// Laravel le pulisce da solo (lotteria di `session.lottery`).
Schedule::call(function () {
    DB::table(config('cache.stores.database.table', 'cache'))
        ->where('expiration', '<', now()->getTimestamp())
        ->delete();
})->name('pulizia-cache-scaduta')->weeklyOn(0, '03:30');

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
    })->name('sync-queue-worker')->everyMinute()->withoutOverlapping();
}

// ============================================================================
// 4-bis. PUBBLICAZIONE COMUNICAZIONI PROGRAMMATE (Coda 589)
// ============================================================================
Schedule::call(function () {
    Artisan::call('comunicazioni:pubblica-programmate');
})->name('pubblica-comunicazioni-programmate')->everyMinute();

// ============================================================================
// 4-ter. SIGILLO DEL REGISTRO DELLE MODIFICHE (Coda 679)
// ============================================================================
// Le righe si sigillano dopo il commit che le scrive; una riga rimasta senza posto (un processo interrotto fra il commit e il sigillo) la prende questo
// lavoro. Prima del blocco 5: durante un ripristino non gira.
Schedule::call(function () {
    Artisan::call('kondomanager:sigilla-registro');
})->name('sigilla-registro-modifiche')->everyFiveMinutes()->withoutOverlapping();

// ============================================================================
// 4-quater. CONSERVAZIONE DEL REGISTRO DELLE MODIFICHE (Coda 679, §11)
// ============================================================================
// Una volta al giorno si tolgono le righe più vecchie della durata scelta (di serie 10 anni): un prefisso della catena, mai un buco, saltando i condomini
// con la cancellazione sospesa. Prima del blocco 5: durante un ripristino non gira.
Schedule::call(function () {
    Artisan::call('kondomanager:conserva-registro');
})->name('conserva-registro-modifiche')->dailyAt('03:15')->withoutOverlapping();

// ============================================================================
// 4-quinquies. PROMEMORIA DELLE CONVOCAZIONI DA SPEDIRE (Coda 679)
// ============================================================================
// Ogni mattina alle 8:15 di Roma: a chi ha preparato l'assemblea, due giorni lavorativi prima del giorno limite del compito «Spedire la convocazione entro
// il …» e la mattina di quel giorno, se restano avvisi da spedire. Manda ciò che è dovuto e non è ancora partito: un giorno che il pianificatore non gira,
// parte il giorno dopo. Prima del blocco 5: durante un ripristino non gira.
Schedule::call(function () {
    Artisan::call('assemblee:promemoria-convocazioni');
})->name('promemoria-convocazioni')->dailyAt('08:15')->timezone('Europe/Rome')->withoutOverlapping();

// ============================================================================
// 5. NIENTE DURANTE UN RIPRISTINO
// ============================================================================
// Mentre un ripristino è in corso il database è a metà import. La modalità ripristino ferma le
// richieste HTTP, compreso il webhook /system/run-scheduler; gli eventi lanciati da riga di comando
// (`schedule:run` del cron, `schedule:work` nell'immagine Docker) li ferma questo blocco, per ogni
// evento registrato sopra, compreso il worker degli hosting condivisi. Il filtro controlla solo un file
// (`RestoreMode`), niente database; il comando `schedule:run` invece, prima dei filtri, legge la cache per
// sapere se il pianificatore è in pausa, e con la cache nel database legge la tabella a metà import (Coda 400).
// Un evento nuovo va scritto PRIMA di questo blocco.
foreach (Schedule::events() as $evento) {
    $evento->skip(fn () => app(RestoreMode::class)->active());
}
