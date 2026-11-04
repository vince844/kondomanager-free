<?php

/**
 * Coda 455 (punto 6c) — il commento di `config/app.php` dice il valore di serie di `scheduler_queue_worker`, e il valore di serie è `false`.
 *
 * Il commento diceva «TRUE (default distro)» sopra una riga che dà `false` quando il `.env` non dice niente, e `.env.example` distribuisce
 * `SCHEDULE_QUEUE_WORKER=false`: chi leggeva il commento credeva il contrario del valore. Il commento non dice niente del pacchetto per gli
 * hosting condivisi, che non sta in questo repository: dice che cosa fa il valore, e quale è quello di serie.
 *
 * Il commento non ha un comportamento da provare dalla pagina: si leggono la riga che dà il valore, il commento che la precede e `.env.example`,
 * e si prova che dicono la stessa cosa. Che il valore acceso lanci il worker dentro lo scheduler lo dice `routes/console.php`, letto qui.
 */

/** Il commento sopra `'scheduler_queue_worker'`, su una riga sola. */
function commentoDelWorkerDelloScheduler(): string
{
    $config = file_get_contents(config_path('app.php'));
    preg_match('#/\*(?:(?!\*/).)*scheduler_queue_worker:(?:(?!\*/).)*\*/#s', $config, $blocco);
    expect($blocco)->not->toBeEmpty();

    // Le barre verticali dei righi del blocco, i filetti di trattini e i delimitatori spariscono; il testo si legge su una riga sola.
    return trim(preg_replace('/\s+/', ' ', preg_replace('#\||-{3,}|/\*|\*/#', ' ', $blocco[0])));
}

it('Coda 455 — la riga di `config/app.php` dà `false` senza il `.env`, e il commento lo dice', function () {
    $config = file_get_contents(config_path('app.php'));
    preg_match("/'scheduler_queue_worker' => env\('SCHEDULE_QUEUE_WORKER', (true|false)\)/", $config, $riga);
    expect($riga[1] ?? null)->toBe('false');

    $commento = commentoDelWorkerDelloScheduler();
    expect($commento)
        ->toContain('il valore di serie è FALSE')
        ->not->toContain('default distro')
        ->not->toContain('TRUE (default');
});

it('Coda 455 — il commento dice anche dove gira il worker con `false`: un cron separato, o sotto Supervisor nelle immagini Docker', function () {
    expect(commentoDelWorkerDelloScheduler())
        ->toContain('il worker gira come cron separato (queue:work --stop-when-empty) o, nelle immagini Docker, sotto Supervisor.');

    // Il fatto che la frase aggiunge, letto dove vive: ognuna delle configurazioni di Supervisor delle immagini avvia `queue:work`.
    foreach (['docker/production/supervisord.conf' => '[program:queue-worker]', 'docker/supervisord.conf' => '[program:laravel-worker]'] as $configurazione => $programma) {
        expect(file_get_contents(base_path($configurazione)))
            ->toContain($programma)
            ->toContain('artisan queue:work');
    }
});

it('Coda 455 — il commento dice che cosa fa `true`: l\'hosting condiviso senza accesso CLI, il worker dentro lo scheduler, un cron esterno', function () {
    $commento = commentoDelWorkerDelloScheduler();

    expect($commento)
        ->toContain('TRUE serve all\'hosting condiviso senza accesso CLI')
        ->toContain('lo scheduler lancia un worker sincrono ogni minuto')
        ->toContain('cron esterno')
        ->toContain('SCHEDULE_QUEUE_WORKER nel .env');

    // Il fatto che il commento descrive, letto dove vive: il worker nello scheduler solo con il valore acceso, ogni minuto, e si ferma a coda vuota.
    $scheduler = file_get_contents(base_path('routes/console.php'));
    expect($scheduler)
        ->toContain("if (config('app.scheduler_queue_worker')) {")
        ->toContain("'--stop-when-empty' => true")
        ->toContain("->name('sync-queue-worker')->everyMinute()");
});

it('Coda 455 — controprova: `.env.example` distribuisce lo stesso valore, `false`', function () {
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^SCHEDULE_QUEUE_WORKER=false$/m');
});
