<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalCondominio;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * **Un foglio lasciato in bianco non butta via il lavoro degli altri.**
 *
 * Fino alla 1.11.0-beta.5 qualunque prerequisito mancante faceva `return` in
 * `ImportRunner::esegui()`, e i livelli successivi non venivano nemmeno tentati. Con l'ordine
 * `condominio → esercizi → capitoli → soggetti → unità → titolarità → tabelle → saldi`, misurato:
 * un lotto senza il foglio delle **persone** si fermava al quarto livello e lasciava fuori unità,
 * tabelle e saldi — che dalle persone non dipendono affatto, e che erano stati compilati.
 *
 * Per i file di Danea aveva una sua logica: sono un pacchetto esportato tutto insieme, e se manca
 * un pezzo probabilmente l'esportazione è andata storta. Per il **modello compilabile a mano** no:
 * là un foglio vuoto è una scelta — «i saldi non li ho», «le tabelle le metto dopo».
 *
 * La distinzione che rende possibile la differenza è su `PrerequisitoMancante::$bloccante`:
 * «scrivere sarebbe incoerente» resta un muro, «quel file non c'è» diventa un salto.
 */
function contestoConCondominio(array $canonici = []): ImportContext
{
    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'manuale']));

    $ctx->conCanonico(LivelloCondominio::CHIAVE, new CanonicalCondominio(
        nome: 'CONDOMINIO FOGLI VUOTI',
        codiceFiscale: '97000000088',
        indirizzo: 'Via dei Fogli 1',
    ));

    $ctx->conCanonico(LivelloEsercizi::CHIAVE, new CanonicalEsercizio(
        '2026',
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    ));

    foreach ($canonici as $chiave => $valore) {
        $ctx->conCanonico($chiave, $valore);
    }

    return $ctx;
}

it('senza il foglio delle persone, le unità entrano lo stesso', function () {
    // È il caso che prima costava tre livelli: unità, tabelle e saldi venivano scartati solo
    // perché stavano più in basso nella catena delle persone, da cui non dipendono.
    $ctx = contestoConCondominio([
        LivelloUnita::CHIAVE => ['1-0-1' => new CanonicalImmobile(palazzina: '1', gruppo: '0', progressivo: '1')],
    ]);

    $esiti = (new ImportRunner)->esegui($ctx, []);

    expect($esiti[LivelloSoggetti::CHIAVE]->prerequisitiMancanti[0]->codice)->toBe('soggetti.dati_assenti')
        // Il livello successivo viene tentato, e le unità entrano davvero.
        ->and($esiti[LivelloUnita::CHIAVE]->riuscito())->toBeTrue()
        ->and(Immobile::count())->toBe(1);
});

it('chi dipende da un foglio saltato viene saltato a sua volta, dicendo perché', function () {
    $ctx = contestoConCondominio([
        LivelloUnita::CHIAVE => ['1-0-1' => new CanonicalImmobile(palazzina: '1', gruppo: '0', progressivo: '1')],
    ]);

    $esiti = (new ImportRunner)->esegui($ctx, []);

    $titolarita = $esiti[LivelloTitolarita::CHIAVE];

    expect($titolarita->prerequisitiMancanti[0]->codice)->toBe('titolarita.dipendenza_saltata')
        // Non è un errore: è la conseguenza di un foglio in bianco, e il messaggio nomina la causa.
        ->and($titolarita->prerequisitiMancanti[0]->bloccante)->toBeFalse()
        ->and($titolarita->prerequisitiMancanti[0]->cosaManca)->toContain('Persone');
});

it('l\'importazione risulta completata, non interrotta, se non è andato storto niente', function () {
    // ⚠️ **È il difetto che si vedeva a video.** Anche quando tutto quello che era stato fornito
    // entrava, se l'ultimo livello si fermava per mancanza di dati l'esito si intitolava
    // «Importazione interrotta» — e con il modello manuale sarebbe capitato a chiunque lasciasse
    // vuoti i saldi, cioè spesso. Dire «interrotta» a chi non ha sbagliato niente è una bugia che
    // costa una segnalazione.
    $ctx = contestoConCondominio([
        LivelloUnita::CHIAVE => ['1-0-1' => new CanonicalImmobile(palazzina: '1', gruppo: '0', progressivo: '1')],
    ]);

    (new ImportRunner)->esegui($ctx, []);

    expect($ctx->batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Esercizio::count())->toBe(1)
        ->and(Immobile::count())->toBe(1);
});

it('i fogli saltati compaiono nell\'esito, così l\'amministratore sa cosa manca', function () {
    // Un salto silenzioso sarebbe peggio del blocco: chi ha lasciato un foglio in bianco per
    // sbaglio — ha compilato e poi ricaricato il file vecchio — non avrebbe modo di accorgersene.
    $ctx = contestoConCondominio([
        LivelloUnita::CHIAVE => ['1-0-1' => new CanonicalImmobile(palazzina: '1', gruppo: '0', progressivo: '1')],
    ]);

    $esiti = (new ImportRunner)->esegui($ctx, []);

    foreach ([LivelloSoggetti::CHIAVE, LivelloTabelle::CHIAVE, LivelloSaldi::CHIAVE] as $chiave) {
        expect($esiti)->toHaveKey($chiave);
        expect($esiti[$chiave]->prerequisitiMancanti)->not->toBe([]);
        // ⚠️ Avviso e non errore: mostrare in rosso «non hai i saldi» a chi i saldi non li ha è
        // come dirgli che ha sbagliato, e non ha sbagliato niente.
        expect($esiti[$chiave]->prerequisitiMancanti[0]->comeRilievo()->severita->value)->toBe('avviso');
    }
});

it('senza condominio invece si ferma davvero: non c\'è nessun posto in cui scrivere', function () {
    // ⚠️ L'unico «dati assenti» rimasto bloccante, e non per simmetria: ogni livello dipende dal
    // condominio, direttamente o per cascata. Non è «un file che ho scelto di non dare», è
    // l'assenza del contenitore. Chi arriva qui ha già avuto la sua via d'uscita nella schermata
    // di verifica, dove la destinazione si sceglie a mano.
    $ctx = new ImportContext(ImportBatch::create(['sorgente' => 'manuale']));
    $ctx->conCanonico(LivelloUnita::CHIAVE, ['1-0-1' => new CanonicalImmobile(palazzina: '1', gruppo: '0', progressivo: '1')]);

    $esiti = (new ImportRunner)->esegui($ctx, []);

    expect($esiti[LivelloCondominio::CHIAVE]->prerequisitiMancanti[0]->bloccante)->toBeTrue()
        ->and($esiti)->not->toHaveKey(LivelloUnita::CHIAVE)
        ->and($ctx->batch->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and(Immobile::count())->toBe(0);
});
