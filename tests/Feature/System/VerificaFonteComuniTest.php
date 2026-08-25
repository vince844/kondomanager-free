<?php

/**
 * Il comando che chiede a ISTAT se ha pubblicato un elenco più fresco del nostro.
 *
 * ## Perché esiste
 *
 * La beta.59 ha spedito l'elenco dei Comuni dentro il codice, e funziona: chi aggiorna
 * KondoManager riceve l'elenco nuovo senza fare niente. Restava scoperto **il passo prima**, cioè
 * accorgersi che ISTAT ne ha pubblicato uno. Alla domanda di Vincenzo — *«controlleremo manualmente
 * ogni tanto?»* — la risposta onesta era: sì, e senza qualcosa che lo ricordi non sarebbe successo.
 *
 * ## Cosa NON fa, ed è una scelta
 *
 * **Non è pianificato.** Nessuna installazione lo esegue da sola: sarebbe una dipendenza dalla rete
 * per una funzione che deve reggere anche senza. Lo lanciamo noi, e il documento di processo dice
 * quando — alla prima beta dell'anno, perché le fusioni decorrono dal 1° gennaio e ISTAT pubblica
 * fra gennaio e febbraio.
 *
 * ## Perché confronta il nome del foglio e non il `last-modified`
 *
 * Misurato il 19/08/2026: l'intestazione HTTP dice 26/02/2026, il foglio dice «CODICI al
 * 21_02_2026». Cinque giorni di scarto — la prima è quando ISTAT ha caricato, il secondo è a quando
 * l'elenco è aggiornato. E il `last-modified` **non è nemmeno stabile fra client**: `curl` riceve
 * `16:17:51`, Guzzle `16:17:49`. Un controllo costruito su quello griderebbe al lupo.
 */

use App\Models\Comune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Support/fogliettoIstat.php';

/** La risposta finta di ISTAT: un foglio con la data che vogliamo. */
function istatRisponde(string $nomeFoglio): void
{
    $percorso = fogliettoIstat($nomeFoglio, [['Roma', null, 'Lazio', 'Roma', 'RM', 'H501']]);

    Http::fake([
        '*istat.it*' => Http::response(file_get_contents($percorso), 200),
    ]);

    register_shutdown_function(fn () => @unlink($percorso));
}

it('dice che siamo aggiornati quando la fonte non è più fresca di noi', function () {
    Artisan::call('kondomanager:aggiorna-comuni');
    istatRisponde('CODICI al 21_02_2026');

    $esito = Artisan::call('kondomanager:verifica-fonte-comuni');

    expect($esito)->toBe(0)
        ->and(Artisan::output())->toContain('aggiornato');
});

it('avvisa quando ISTAT ha pubblicato un elenco più recente, e dice cosa fare', function () {
    Artisan::call('kondomanager:aggiorna-comuni');
    istatRisponde('CODICI al 01_01_2030');

    $esito = Artisan::call('kondomanager:verifica-fonte-comuni');
    $detto = Artisan::output();

    expect($detto)->toContain('01/01/2030')
        // ⚠️ Un avviso che non dice cosa fare costringe chi lo legge a cercarselo, e fra un anno
        // quel qualcuno sarà uno che non ha mai visto questo codice.
        ->and($detto)->toContain('--scrivi-file')
        // Esce diverso da zero, così si può metterlo in una catena che si ferma.
        ->and($esito)->not->toBe(0);
});

it('non scarica niente se non serve: chiede prima quanto pesa', function () {
    // Il controllo dev'essere economico o non lo si lancia. Qui si prova che la prima richiesta è
    // una HEAD — che non porta corpo — e che il corpo arriva solo dopo.
    Artisan::call('kondomanager:aggiorna-comuni');
    istatRisponde('CODICI al 21_02_2026');

    Artisan::call('kondomanager:verifica-fonte-comuni');

    Http::assertSent(fn (Request $r) => $r->method() === 'HEAD');
});

it('la rete che non risponde è un esito, non un errore da stack trace', function () {
    Artisan::call('kondomanager:aggiorna-comuni');

    Http::fake(['*istat.it*' => Http::response('', 503)]);

    $esito = Artisan::call('kondomanager:verifica-fonte-comuni');

    expect($esito)->not->toBe(0)
        ->and(Artisan::output())->toContain('ISTAT');
});

it('con la tabella vuota lo dice, invece di confrontare con niente', function () {
    istatRisponde('CODICI al 21_02_2026');

    expect(Comune::count())->toBe(0);

    $esito = Artisan::call('kondomanager:verifica-fonte-comuni');

    expect($esito)->not->toBe(0)
        ->and(Artisan::output())->toContain('kondomanager:aggiorna-comuni');
});

it('non è pianificato: nessuna installazione esce in rete da sola per i Comuni', function () {
    // ⚠️ È la decisione che tiene in piedi tutto il disegno: l'elenco viaggia col codice **proprio**
    // perché nessuna installazione debba dipendere dalla rete. Pianificare questo comando la
    // rimetterebbe dentro dalla finestra.
    $console = file_get_contents(base_path('routes/console.php'));

    expect($console)->not->toContain('verifica-fonte-comuni');
});
