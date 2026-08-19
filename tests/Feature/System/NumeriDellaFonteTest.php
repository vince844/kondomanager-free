<?php

/**
 * I numeri che raccontiamo della fonte sono quelli che la fonte ha davvero.
 *
 * ## La lezione che questo file mette in pratica
 *
 * La beta.59 ha sparso cinque numeri fra changelog, docblock e roadmap — 7.894 comuni, 2.687 nomi
 * multi-parola, 314 con l'apostrofo, 1.145 storpiati dal Title Case, 121 bilingui — **senza una sola
 * asserzione che li presidiasse**. Reggevano per una ragione sola: il file viaggiava col codice e non
 * cambiava mai.
 *
 * ⚠️ **La beta.60 gli toglie proprio quella proprietà.** Da quando `--scrivi-file` rigenera l'elenco
 * dall'XLSX di ISTAT, quei cinque numeri possono diventare falsi **in silenzio, con la suite verde**
 * — e resterebbero scritti in un changelog pubblicato e in tre documenti.
 *
 * La regola scritta in `flusso_di_lavoro_rilascio.md` chiudendo la .59 dice: *un numero misurato su
 * un file di dati si può scrivere in prosa solo finché quel file è immutabile; o il numero si ricava
 * dal file, o non si scrive.* Questo file è il presidio: se l'elenco cambia, i numeri raccontati
 * smettono di corrispondere e il test lo dice **prima** che qualcuno legga il changelog.
 *
 * ## Cosa fare quando questo test fallisce
 *
 * Non si aggiusta il numero nel test: si aggiornano **i testi** che lo citano. Il messaggio di ogni
 * asserzione dice dove sono.
 *
 * ⚠️ **E deve dirlo per intero.** Alla prima stesura questi messaggi elencavano due posti su cinque,
 * e infatti la correzione dei bilingui (124 → 121) ha lasciato il numero vecchio in `roadmap.md` e —
 * peggio — proprio nella lezione di `flusso_di_lavoro_rilascio.md` che insegna a non scrivere in
 * prosa i numeri di un file. Un presidio che porta a metà dei posti è un presidio che lascia in giro
 * l'altra metà.
 */

use App\Models\Comune;

/** L'elenco spedito col codice, che è la fonte di tutti i numeri raccontati. */
function elencoDellaFonte(): array
{
    return json_decode(
        file_get_contents(resource_path('data/comuni/comuni-italiani.json')),
        true, 512, JSON_THROW_ON_ERROR
    )['comuni'];
}

it('il totale dei comuni è quello raccontato', function () {
    expect(count(elencoDellaFonte()))->toBe(7894,
        'il numero di comuni è cambiato: va aggiornato in docs/changelog.md, '
        .'resources/data/changelogs/*/1.10.0-beta.59.json e nei docblock che lo citano');
});

it('i nomi di più parole sono quelli raccontati', function () {
    $multi = collect(elencoDellaFonte())->filter(fn ($c) => str_contains($c['nome'], ' '))->count();

    expect($multi)->toBe(2687,
        'i nomi multi-parola sono cambiati: il numero è citato in app/Models/Comune.php '
        .'(docblock di scopeCerca), in tests/Feature/System/RicercaComuniTest.php e nel changelog');
});

it('i nomi con l\'apostrofo sono quelli raccontati', function () {
    $apostrofo = collect(elencoDellaFonte())->filter(fn ($c) => str_contains($c['nome'], "'"))->count();

    expect($apostrofo)->toBe(314,
        'i nomi con apostrofo sono cambiati: il numero è citato in '
        .'tests/Feature/System/RicercaComuniTest.php e nel changelog della beta.59');
});

it('i comuni bilingui sono quelli raccontati', function () {
    $bilingui = collect(elencoDellaFonte())->filter(fn ($c) => ! empty($c['altra']))->count();

    expect($bilingui)->toBe(121,
        'i comuni bilingui sono cambiati. Il numero è citato in: '
        .'tests/Feature/System/RicercaComuniTest.php, docs/changelog.md, '
        .'resources/data/changelogs/{it,en,pt}/1.10.0-beta.59.json, docs/roadmap.md (scheda ㊹) '
        .'e docs/flusso_di_lavoro_rilascio.md (lezione 4 della .59)');
});

it('i nomi che il Title Case storpiava sono quelli raccontati', function () {
    // È il numero che giustifica la rimozione di `Str::title()` da `CondominioResource`: se un
    // giorno scendesse a zero, quella correzione non avrebbe più una ragione scritta.
    $storpiati = collect(elencoDellaFonte())
        ->filter(fn ($c) => \Illuminate\Support\Str::title($c['nome']) !== $c['nome'])
        ->count();

    expect($storpiati)->toBe(1145,
        'i nomi storpiati dal Title Case sono cambiati: il numero è citato in '
        .'tests/Feature/Gestionale/NomeComuneNonSiRiscriveTest.php e nel changelog della beta.59');
});

it('il codice catastale resta una chiave naturale, che è ciò su cui poggia l\'aggiornamento', function () {
    // ⚠️ Non è un numero da raccontare: è l'**invariante** su cui poggia l'`upsert`. Se una fonte
    // futura la smentisse, l'aggiornamento comincerebbe a sovrascrivere comuni diversi fra loro.
    $codici = array_column(elencoDellaFonte(), 'codice');

    expect(count(array_unique($codici)))->toBe(count($codici))
        ->and(array_filter($codici, fn ($c) => strlen((string) $c) !== 4))->toBe([]);
});

it('quello che si racconta corrisponde a quello che finisce a database', function () {
    \Illuminate\Support\Facades\Artisan::call('kondomanager:aggiorna-comuni');

    expect(Comune::count())->toBe(count(elencoDellaFonte()));
})->uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);
