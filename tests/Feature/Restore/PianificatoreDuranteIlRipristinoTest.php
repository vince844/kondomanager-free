<?php

use App\Services\Restore\RestoreMode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;

/*
 * Durante un ripristino il database è a metà import. La modalità ripristino ferma le richieste HTTP, compreso il
 * webhook del pianificatore; gli eventi del pianificatore lanciati da riga di comando (`schedule:run` del cron,
 * `schedule:work` nell'immagine Docker) non passano da lì, e girerebbero sulle tabelle a metà: nessuno parte finché
 * la modalità ripristino è attiva, compreso il worker della coda degli hosting condivisi.
 */

afterEach(function () {
    app(RestoreMode::class)->exit();
});

/** @return array<int, string> i nomi degli eventi del pianificatore che passano i loro filtri adesso */
function pdrEventiCheSiEseguono(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn ($evento) => $evento->filtersPass(app()))
        ->map(fn ($evento) => $evento->description)
        ->values()
        ->all();
}

test('durante un ripristino nessun evento del pianificatore si esegue; a ripristino chiuso tutti', function (bool $workerDalPianificatore) {
    if ($workerDalPianificatore) {
        // Il worker degli hosting condivisi si registra solo con la sua impostazione, letta quando si caricano gli eventi.
        config(['app.scheduler_queue_worker' => true]);
        require base_path('routes/console.php');
    }
    $tutti = collect(app(Schedule::class)->events())->map(fn ($evento) => $evento->description)->values()->all();
    expect($tutti)->toContain('system-heartbeat', 'system-prune', 'pulizia-cache-scaduta', 'check-updates', 'update-cron-ips');
    if ($workerDalPianificatore) {
        expect($tutti)->toContain('sync-queue-worker');
    }

    app(RestoreMode::class)->enter('r-in-corso', 'it');
    $durante = pdrEventiCheSiEseguono();
    app(RestoreMode::class)->exit();
    $dopo = pdrEventiCheSiEseguono();

    expect($durante)->toBe([])
        ->and($dopo)->toBe($tutti);
})->with(['senza il worker della coda' => false, 'con il worker della coda' => true]);

test('durante un ripristino «schedule:run» non scrive nemmeno il battito; a ripristino chiuso sì, e il webhook del pianificatore resta fermo dalla modalità ripristino', function () {
    // Un'ora in cui è in scadenza solo il battito di ogni minuto.
    $this->travelTo(now()->startOfWeek()->setTime(12, 30));
    Cache::forget('system_cron_heartbeat');

    app(RestoreMode::class)->enter('r-in-corso', 'it');
    $this->artisan('schedule:run')->assertSuccessful();
    $battitoDurante = Cache::get('system_cron_heartbeat');
    $webhook = $this->get('/system/run-scheduler?token=qualsiasi');
    app(RestoreMode::class)->exit();

    $this->artisan('schedule:run')->assertSuccessful();

    expect($battitoDurante)->toBeNull()
        ->and($webhook->status())->toBe(503)
        ->and(Cache::get('system_cron_heartbeat'))->not->toBeNull();
});
