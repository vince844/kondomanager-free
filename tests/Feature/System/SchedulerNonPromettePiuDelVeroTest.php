<?php

use Illuminate\Console\Scheduling\Schedule;

/**
 * La riga «Il Motore Invisibile» delle impostazioni del cron dice che cosa fa lo scheduler. Diceva che
 * «controlla le scadenze, emette le rate e gestisce gli alert»: `routes/console.php` registra solo la prova
 * di vita, la pulizia dei dati vecchi e della cache scaduta, il controllo degli aggiornamenti, l'aggiornamento
 * degli IP di cron-job.org e, dove è acceso, il worker della coda. Le rate si emettono a mano, dal piano.
 *
 * Il primo controllo legge le voci vere dello scheduler e fissa il fatto; gli altri, il testo nelle lingue
 * in cui è tradotto. Se un giorno lo scheduler cominciasse a emettere le rate, si cambia il fatto qui e il testo là.
 */

it('nessuna voce dello scheduler emette rate, controlla scadenze o manda alert', function () {
    $voci = collect(app(Schedule::class)->events())
        ->map(fn ($evento) => strtolower(trim(($evento->description ?? '').' '.($evento->command ?? ''))))
        ->filter()
        ->values();

    // Le voci ci sono (la prova di vita è sempre registrata): senza, il controllo qui sotto non direbbe niente.
    expect($voci->all())->toContain('system-heartbeat');

    expect($voci->implode(' '))->not->toMatch('/\brate\b|emission|emett|scadenz|alert|sollecit/');
});

it('il testo del «Motore Invisibile» non promette ciò che lo scheduler non fa', function (string $lingua, string $promessaFalsa) {
    $testo = trans('impostazioni.dialogs.cron_guide_1_desc', [], $lingua);

    expect($testo)->not->toBe('impostazioni.dialogs.cron_guide_1_desc')
        ->and($testo)->not->toMatch($promessaFalsa);
})->with([
    'it' => ['it', '/emette le rate|scadenze|alert/iu'],
    'en' => ['en', '/issues installments|deadlines|alerts/i'],
    'pt' => ['pt', '/emite parcelas|prazos|alertas/iu'],
    'es' => ['es', '/emite cuotas|plazos|alertas/iu'],
]);
