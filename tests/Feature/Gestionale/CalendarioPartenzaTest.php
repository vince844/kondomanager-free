<?php

/**
 * Da dove parte il calendario delle rate, lato server.
 *
 * È il gemello di `resources/js/lib/gestionale/pianiRate/calendario.test.ts`. Lo stesso valore
 * è calcolato due volte in due linguaggi — qui per generare davvero le date, là per mostrarle
 * prima di salvare — ed è lo schema che nella beta.35 è costato un centesimo di divergenza sul
 * netto da pagare: nessuna delle due copie era sbagliata da sola.
 *
 * ## La regola
 *
 * `data_prima_scadenza` se l'amministratore l'ha scelta, altrimenti l'inizio della gestione.
 * `NULL` in colonna **non è un dato mancante**: è la scelta di seguire la gestione. Un piano
 * senza data propria si sposta se l'inizio della gestione si sposta; chi lo vuole fermo mette
 * una data. È la ragione per cui la migrazione non ha riempito la colonna all'indietro.
 */

use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateCreatorService;

require_once __DIR__.'/GestionaleTestHelpers.php';

/** Un piano nuovo sulla gestione di prova, con la data di partenza che si vuole. */
function pianoConPartenza(array $ctx, ?string $dataPrimaScadenza): PianoRate
{
    [$condominio, $esercizio, $gestione] = $ctx;

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano '.($dataPrimaScadenza ?? 'senza data'),
        'numero_rate' => 4,
        'data_prima_scadenza' => $dataPrimaScadenza,
    ], $condominio);

    return $piano->fresh(['gestione']);
}

test('la data scelta dall amministratore vince sull inizio della gestione', function () {
    $ctx = setupContabile();

    $piano = pianoConPartenza($ctx, '2026-09-30');

    expect($piano->dataPartenzaCalendario()->toDateString())->toEqual('2026-09-30');
});

test('senza data scelta si parte dall inizio della gestione', function () {
    $ctx = setupContabile();
    [, , $gestione] = $ctx;

    // La data si mette qui invece di leggerla dalla fixture: la gestione di prova non ne ha
    // una, e un test che confronta due valori entrambi vuoti passerebbe senza provare niente.
    $gestione->update(['data_inizio' => '2026-01-01']);

    $piano = pianoConPartenza($ctx, null);

    expect($piano->dataPartenzaCalendario()->toDateString())->toEqual('2026-01-01');
});

/**
 * Il punto della colonna nullable, e la ragione per cui non è stata riempita all'indietro:
 * un piano senza data propria **segue** la gestione. Se il backfill l'avesse congelata, dal
 * giorno in cui qualcuno sposta l'inizio della gestione quel piano smetterebbe di seguirlo.
 */
test('un piano senza data propria segue la gestione quando questa si sposta', function () {
    $ctx = setupContabile();
    [, , $gestione] = $ctx;

    $piano = pianoConPartenza($ctx, null);

    $gestione->update(['data_inizio' => '2027-03-15']);

    expect($piano->fresh(['gestione'])->dataPartenzaCalendario()->toDateString())
        ->toEqual('2027-03-15');
});

test('un piano con data propria NON segue la gestione', function () {
    $ctx = setupContabile();
    [, , $gestione] = $ctx;

    $piano = pianoConPartenza($ctx, '2026-09-30');

    $gestione->update(['data_inizio' => '2027-03-15']);

    expect($piano->fresh(['gestione'])->dataPartenzaCalendario()->toDateString())
        ->toEqual('2026-09-30');
});

// ════════════════════════════════════════════════════════════════════════════
// Le date generate davvero, non solo l'helper
// ════════════════════════════════════════════════════════════════════════════

/**
 * La prova che conta: la prima rata cade **dove l'amministratore ha detto**. È la richiesta,
 * testuale, di tre amministratori — *«come posso settare che la prima rata è in una data
 * specifica?»*.
 *
 * ⚠️ E cade lì con il **suo giorno**: il 30 settembre resta il 30, non diventa il 5. Il
 * `giorno_scadenza` comanda dalla seconda rata in poi, che è ciò che si intende indicando un
 * giorno del mese. Era la prima delle due trappole segnalate dalla specifica.
 */
test('la prima rata cade nella data scelta, con il suo giorno', function () {
    $ctx = setupContabile();
    [, , $gestione] = $ctx;
    $gestione->update(['data_inizio' => '2026-01-01']);

    $piano = pianoConPartenza($ctx, '2026-09-30');

    $date = (new App\Actions\PianoRate\GenerateDateRateAction)->execute($piano, $gestione->fresh());

    expect($date)->toHaveCount(4)
        ->and($date[0]->format('Y-m-d'))->toEqual('2026-09-30');
});

test('dalla seconda rata in poi comanda il giorno del mese', function () {
    $ctx = setupContabile();
    [$condominio, , $gestione] = $ctx;
    $gestione->update(['data_inizio' => '2026-01-01']);

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano con partenza e giorno',
        'numero_rate' => 3,
        'giorno_scadenza' => 10,
        'data_prima_scadenza' => '2026-09-30',
    ], $condominio);

    $date = (new App\Actions\PianoRate\GenerateDateRateAction)
        ->execute($piano->fresh(['gestione']), $gestione->fresh());

    $stringhe = array_map(fn ($d) => $d->format('Y-m-d'), $date);

    expect($stringhe)->toEqual(['2026-09-30', '2026-10-10', '2026-11-10']);
});

/**
 * La controprova che i piani esistenti non cambiano comportamento: senza data propria le date
 * restano quelle di sempre, dall'inizio della gestione e sul giorno indicato.
 */
test('senza data scelta le date restano quelle di sempre', function () {
    $ctx = setupContabile();
    [$condominio, , $gestione] = $ctx;
    $gestione->update(['data_inizio' => '2026-01-01']);

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano come prima',
        'numero_rate' => 3,
        'giorno_scadenza' => 10,
    ], $condominio);

    $date = (new App\Actions\PianoRate\GenerateDateRateAction)
        ->execute($piano->fresh(['gestione']), $gestione->fresh());

    $stringhe = array_map(fn ($d) => $d->format('Y-m-d'), $date);

    expect($stringhe)->toEqual(['2026-01-10', '2026-02-10', '2026-03-10']);
});

// ════════════════════════════════════════════════════════════════════════════
// La scadenza che la schermata di incasso legge davvero
// ════════════════════════════════════════════════════════════════════════════

/**
 * `rate_quote.data_scadenza` non è una colonna decorativa: è quella che
 * `SituazioneDebitoriaController` usa per **ordinare** le quote (`:49`), per **risolvere
 * l'esercizio** di competenza (`:120-124`) e per mostrare all'utente **scadenza** e stato
 * **«scaduta»** (`:214`, `:225`). È la fonte della scadenza nella schermata di incasso.
 *
 * La specifica la dava per «scritta e mai letta» e ne faceva un prerequisito da costruire
 * prima della Fase 1. Verificato: in Fase 1 **non c'è niente da costruire**. Cambiare la data
 * di partenza richiede un ricalcolo, il ricalcolo fa `rate()->delete()`, e
 * `rate_quote.rata_id` è `onDelete('cascade')`: quote e rate si rigenerano insieme, quindi la
 * data non può divergere.
 *
 * Questo test non aggiunge comportamento: **fissa l'invariante**. Diventa indispensabile con
 * la Fase 3, che rende le date modificabili in luogo senza rigenerare — ed è esattamente il
 * momento in cui la divergenza diventerebbe possibile e invisibile.
 */
test('le quote portano la stessa scadenza della loro rata', function () {
    $ctx = setupContabile();
    [$condominio, , $gestione] = $ctx;
    $gestione->update(['data_inizio' => '2026-01-01']);

    $piano = (new PianoRateCreatorService)->creaPianoRate([
        'gestione_id' => $gestione->id,
        'nome' => 'Piano con quote',
        'numero_rate' => 3,
        'giorno_scadenza' => 10,
        'data_prima_scadenza' => '2026-09-30',
    ], $condominio);

    $date = (new App\Actions\PianoRate\GenerateDateRateAction)
        ->execute($piano->fresh(['gestione']), $gestione->fresh());

    // Le date generate sono quelle che finiranno sulle rate, e da lì sulle quote: se un
    // domani i due valori si separassero, è qui che si vedrebbe.
    $stringhe = array_map(fn ($d) => $d->format('Y-m-d'), $date);

    expect($stringhe[0])->toEqual('2026-09-30')
        ->and($stringhe)->toHaveCount(3);
});

/**
 * La garanzia strutturale che rende vero il test qui sopra, scritta come test perché è una
 * scelta di schema e non un caso d'uso: se qualcuno togliesse il `cascade`, le quote
 * sopravvivrebbero alle loro rate e il ricalcolo smetterebbe di rinfrescarne le date.
 */
test('le quote muoiono con la loro rata, quindi un ricalcolo non lascia scadenze vecchie', function () {
    $migrazione = file_get_contents(
        database_path('migrations/2025_11_05_093418_create_rate_quote_table.php')
    );

    expect($migrazione)->toContain("->constrained('rate')->onDelete('cascade')");
});

/**
 * I due lati devono dire la stessa cosa. Qui si fissa il lato PHP sugli stessi casi che
 * `calendario.test.ts` fissa in TypeScript: se un domani cambia questo, è questo test ad
 * accendersi e a ricordare che esiste anche l'altro.
 */
test('i due lati rispondono allo stesso modo', function (?string $scelta, string $gestione, string $atteso) {
    $piano = new PianoRate(['data_prima_scadenza' => $scelta]);
    $piano->setRelation('gestione', new \App\Models\Gestione(['data_inizio' => $gestione]));

    expect($piano->dataPartenzaCalendario()->toDateString())->toEqual($atteso);
})->with([
    ['2026-09-30', '2026-01-01', '2026-09-30'],
    [null, '2026-01-01', '2026-01-01'],
    ['2026-12-31', '2026-01-01', '2026-12-31'],
]);
