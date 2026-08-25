<?php

use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\SpreadsheetReader;

/**
 * Il lettore e la localizzazione dell'intestazione, contro gli export reali di Danea.
 *
 * Le fixture in `tests/Fixtures/import/danea/` sono **derivate dai file veri** di un
 * amministratore: struttura, posizione dell'intestazione, nomi di colonna e formati dei valori
 * sono quelli originali; nomi, codici fiscali, indirizzi, telefoni ed email sono stati
 * sostituiti. Gli originali non sono nel repository e non devono entrarci (§17 di
 * `docs/import_migrazione_dati.md`).
 *
 * Ogni test qui sotto fissa una delle trappole del §17.8: sono difetti già visti sui file veri,
 * non casi di scuola.
 *
 * ## Cosa questi test NON coprono
 *
 * - **Il file con più condomìni dentro** (trappola 14): `Elenco Attività.xls` ha una colonna
 *   `Condominio` con quattro condomìni diversi. Non è fra le fixture perché quel report è fuori
 *   ambito per l'import, ma il presupposto «un file = un condominio» resta non verificato qui.
 * - **La conversione** dei valori: qui si verifica soltanto che arrivino **grezzi e integri**.
 *   Seriali→data, virgola→centesimi e `Pr`→`proprietario` sono compito dei parser, e avranno
 *   i loro test.
 * - **File danneggiati o protetti da password**: il ramo esiste in `SpreadsheetReader` ma qui
 *   non è esercitato, perché servirebbe una fixture appositamente corrotta.
 */
function fixtureDanea(string $nome): string
{
    return __DIR__.'/../../Fixtures/import/danea/'.$nome;
}

function leggiPrimoFoglio(string $nome): Foglio
{
    return (new SpreadsheetReader)->leggi(fixtureDanea($nome))[0];
}

it('legge un .xls di Danea e scarta i fogli vuoti', function () {
    // Il modello di export di Danea porta con sé `Foglio2` e `Foglio3` vuoti: aprire «il primo
    // foglio» e sperare è il modo più rapido di leggere niente.
    $fogli = (new SpreadsheetReader)->leggi(fixtureDanea('anagrafica_millesimi.xls'));

    expect($fogli)->toHaveCount(1)
        ->and($fogli[0]->numeroRighe())->toBe(9)
        ->and($fogli[0]->isVuoto())->toBeFalse();
});

it('conserva il nome del foglio quando non è quello di default', function () {
    expect(leggiPrimoFoglio('elenco_unita.xls')->nome)->toBe('Unità')
        ->and(leggiPrimoFoglio('movimenti.xls')->nome)->toBe('Movimenti');
});

it('trova l\'intestazione a riga 0 quando non c\'è banner', function () {
    $foglio = leggiPrimoFoglio('anagrafica_millesimi.xls');

    $esito = (new HeaderDetector)->trova($foglio, ['Palazzina', 'Gruppo', 'Progressivo', 'Proprietario']);

    expect($esito)->not->toBeNull()
        ->and($esito['riga'])->toBe(0);
});

it('trova l\'intestazione sotto il banner, a riga 5', function () {
    // `Movimenti.xls` ha cinque righe di testata: titolo, vuota, condominio + CF,
    // indirizzo + periodo, vuota. Assumere la riga 0 qui legge il titolo del report.
    $foglio = leggiPrimoFoglio('movimenti.xls');

    $esito = (new HeaderDetector)->trova($foglio, [
        'Protoc.', 'Dt.compet.', 'Descrizione', 'Importo', 'Fornitore', 'Conto',
    ]);

    expect($esito)->not->toBeNull()
        ->and($esito['riga'])->toBe(5);
});

it('trova l\'intestazione a riga 6 anche se le prime tre colonne non hanno nome', function () {
    // È il caso peggiore, e non è teorico: nel riparto consuntivo le colonne senza titolo sono
    // unità, ruolo e denominazione — le tre più importanti del file. Un rilevatore che pretende
    // la prima cella piena non trova questa riga.
    $foglio = leggiPrimoFoglio('riparto_consuntivo.xls');

    $esito = (new HeaderDetector)->trova($foglio, [
        'AMMINISTRAZIONE', 'mill.', 'Totale gestione', 'Saldi di fine Es. prec.',
        'Rate versate', 'Saldo finale',
    ]);

    expect($esito)->not->toBeNull()
        ->and($esito['riga'])->toBe(6);
});

it('non perde le colonne senza nome e numera quelle ripetute', function () {
    $foglio = leggiPrimoFoglio('riparto_consuntivo.xls');
    $mappa = (new HeaderDetector)->mappaColonne($foglio, 6);

    // Le tre colonne senza titolo restano raggiungibili per posizione.
    expect($mappa)->toHaveKeys(['col_0', 'col_1', 'col_2'])
        ->and($mappa['col_0'])->toBe(0)
        ->and($mappa['col_2'])->toBe(2);

    // `mill.` compare tre volte, una per ogni voce di spesa: la prima tiene il nome pulito,
    // le altre vengono numerate. Sovrascriverle in silenzio perderebbe due colonne.
    expect($mappa)->toHaveKeys(['mill', 'mill_2', 'mill_3'])
        ->and($mappa['mill'])->toBeLessThan($mappa['mill_2'])
        ->and($mappa['mill_2'])->toBeLessThan($mappa['mill_3']);
});

it('non si fa ingannare da una riga che contiene per caso una parola dell\'intestazione', function () {
    // La riga `TOTALE COMPLESSIVO` porta i valori di tutte le colonne: se il rilevatore si
    // fermasse alla prima riga che raggiunge il quorum, o contasse le ripetizioni della stessa
    // etichetta, potrebbe scegliere una riga di totali.
    $foglio = leggiPrimoFoglio('riparto_consuntivo.xls');

    $esito = (new HeaderDetector)->trova($foglio, ['AMMINISTRAZIONE', 'mill.', 'Saldo finale', 'Rate versate']);

    expect($esito['riga'])->toBe(6);
});

it('restituisce i valori grezzi: le date restano seriali Excel', function () {
    // `46036` è il 14/01/2026. Formattare qui significherebbe applicare la decisione di chi ha
    // salvato il file, che nei file reali cambia da colonna a colonna.
    $foglio = leggiPrimoFoglio('movimenti.xls');
    $riga = $foglio->riga(6);

    expect($riga[1])->toBeNumeric()
        ->and((int) $riga[1])->toBe(46036);
});

it('conserva i decimali con la virgola e quelli con il punto nello stesso corpus', function () {
    // Trappola 2: `Saldo prec` usa la virgola (arriva come stringa), i millesimi usano il punto
    // (arrivano come numero). Nello stesso set di export dello stesso gestionale.
    $saldo = leggiPrimoFoglio('elenco_unita.xls')->riga(2)[8];
    $millesimo = leggiPrimoFoglio('millesimi_tabelle_miste.xls')->riga(1)[4];

    expect($saldo)->toBeString()->toBe('-827,88')
        ->and($millesimo)->toBeNumeric()->toEqual(53.14);
});

it('conserva i codici unità con lo zero davanti', function () {
    // Trappola 4: `016` non è `16`. Trattarli da interi fonde due unità diverse.
    $foglio = leggiPrimoFoglio('riparto_consuntivo.xls');

    expect($foglio->riga(7)[0])->toBeString()->toBe('01')
        ->and($foglio->riga(10)[0])->toBeString()->toBe('02');
});

it('conserva le note libere, che sono l\'unico posto dove vive la comproprietà', function () {
    // Trappola 9: il tracciato non ha un campo per «due proprietari al 50%». L'amministratore
    // lo scrive a mano nelle note, punto interrogativo compreso. Se le note si perdono, si
    // perde l'unica traccia di un'informazione contabilmente rilevante.
    $foglio = leggiPrimoFoglio('elenco_unita.xls');

    $noteConQuota = collect($foglio->righe)
        ->skip(1)
        ->map(fn (array $r) => (string) ($r[31] ?? ''))
        ->first(fn (string $n) => str_contains($n, '%'));

    expect($noteConQuota)->not->toBeNull()
        ->and($noteConQuota)->toContain('50%');
});

it('normalizza le etichette abbastanza da reggere punteggiatura, accenti e maiuscole', function () {
    expect(HeaderDetector::normalizza('Dt.compet.'))->toBe('dt compet')
        ->and(HeaderDetector::normalizza('Gruppo unità'))->toBe('gruppo unita')
        ->and(HeaderDetector::normalizza('AMMINISTRAZIONE'))->toBe('amministrazione')
        ->and(HeaderDetector::normalizza('Saldi di fine Es. prec.'))->toBe('saldi di fine es prec')
        // Trappola 13: negli export reali alcuni accenti sono già corrotti in `?`
        // **nel file di origine**. Il confronto non può dipendere da loro.
        ->and(HeaderDetector::normalizza('Attivit?'))->toBe('attivit')
        // Trappola 16, vista nelle fixture: i codici di ruolo arrivano con spazi in coda.
        ->and(HeaderDetector::normalizza('ex Pr  '))->toBe('ex pr');
});

it('numera le righe come le vede l\'amministratore in Excel, non come le vede il codice', function () {
    // Un messaggio che dice «riga 14» deve indicare la riga 14 del suo file, o il rimedio che
    // gli stiamo dando non è eseguibile.
    expect(Foglio::rigaUtente(0))->toBe(1)
        ->and(Foglio::rigaUtente(13))->toBe(14);
});

it('rifiuta un formato che non sa leggere, dicendo quali accetta', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
    file_put_contents($tmp, '%PDF-1.4');

    expect(fn () => (new SpreadsheetReader)->leggi($tmp))
        ->toThrow(RuntimeException::class, 'non gestito');

    @unlink($tmp);
});

it('rifiuta un file più grande del limite prima di aprirlo', function () {
    // Il limite va detto **prima** del caricamento: scoprirlo dopo è il momento peggiore.
    // Qui si verifica che il rifiuto avvenga sulla dimensione, senza passare da PhpSpreadsheet.
    $tmp = tempnam(sys_get_temp_dir(), 'imp').'.xls';
    file_put_contents($tmp, str_repeat('x', SpreadsheetReader::DIMENSIONE_MASSIMA_BYTE + 1024));

    expect(fn () => (new SpreadsheetReader)->leggi($tmp))
        ->toThrow(RuntimeException::class, 'il limite è');

    @unlink($tmp);
});
