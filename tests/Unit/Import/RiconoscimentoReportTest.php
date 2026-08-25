<?php

use App\Services\Import\DetectionResult;
use App\Services\Import\Foglio;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\ReportType;
use App\Services\Import\SpreadsheetReader;

/**
 * Il riconoscimento automatico del tipo di report — il motore della schermata S2 (§14.1).
 *
 * L'amministratore trascina tutti i file insieme e non deve ricordare né l'ordine né quale
 * stampa serva a cosa. Questi test verificano che ci si arrivi davvero, sui file veri.
 *
 * ## Cosa questi test NON coprono
 *
 * - **Il file rinominato a mano** (`.xlsx` salvato come `.xls`): il ramo esiste in
 *   `SpreadsheetReader::creaReader()` e qui non è esercitato.
 * - **Il caso di due tipi a pari punteggio**: non si presenta sul corpus attuale, e la scelta
 *   ricadrebbe sul primo in ordine di enum. Se un giorno capitasse, va deciso e non lasciato
 *   all'ordine di dichiarazione.
 * - **La forzatura del tipo da parte dell'utente**: la colonna `tipo_forzato` esiste in
 *   `import_files`, ma il percorso che la scrive non è ancora costruito.
 */
function riconosciFixture(string $nome): DetectionResult
{
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/'.$nome);

    return (new ReportRecognizer)->riconosci($fogli[0]);
}

it('riconosce ogni export reale con il tipo giusto', function (string $file, ReportType $atteso) {
    $esito = riconosciFixture($file);

    expect($esito->riconosciuto())->toBeTrue("«{$file}» non riconosciuto (confidenza {$esito->confidenza})")
        ->and($esito->tipo)->toBe($atteso);
})->with([
    'elenco unità (33 colonne)' => ['elenco_unita.xls', ReportType::ElencoUnita],
    'anagrafica + millesimi' => ['anagrafica_millesimi.xls', ReportType::AnagraficaMillesimi],
    'millesimi con tabelle miste' => ['millesimi_tabelle_miste.xls', ReportType::AnagraficaMillesimi],
    'riparto consuntivo' => ['riparto_consuntivo.xls', ReportType::RipartoConsuntivo],
    'movimenti' => ['movimenti.xls', ReportType::Movimenti],
    'rate versate' => ['rate_versate.xls', ReportType::RateVersate],
]);

it('distingue i due report compatti, che a prima vista si somigliano', function () {
    // È il caso su cui un riconoscitore ingenuo sbaglia: entrambi iniziano con Palazzina /
    // Progressivo e hanno una colonna con il nome del proprietario. Li separa il fatto che
    // `elenco_unita` porta `Ruolo`, `CodFisc` e `Saldo prec`, e chiama `Denominazione` la
    // colonna che l'altro chiama `Proprietario` (§17.2).
    $unita = riconosciFixture('elenco_unita.xls');
    $compatto = riconosciFixture('anagrafica_millesimi.xls');

    expect($unita->tipo)->toBe(ReportType::ElencoUnita)
        ->and($compatto->tipo)->toBe(ReportType::AnagraficaMillesimi);

    // E non deve essere una vittoria di misura: se il secondo classificato fosse a ridosso,
    // il riconoscimento sarebbe fortunato, non corretto — e basterebbe una colonna in più nel
    // file del prossimo amministratore per ribaltarlo.
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/elenco_unita.xls');
    $classifica = (new ReportRecognizer)->classifica($fogli[0]);

    expect($classifica[0]->tipo)->toBe(ReportType::ElencoUnita);

    $secondo = $classifica[1]->confidenza ?? 0;
    expect($classifica[0]->confidenza - $secondo)->toBeGreaterThanOrEqual(30);
});

it('propone le alternative in ordine, per quando il riconoscimento sbaglia', function () {
    // Il pulsante «Cambia» della S2 mostra questa classifica. Quando il sistema sbaglia,
    // l'utente quasi sempre vuole il secondo: farglielo cercare in un elenco alfabetico
    // sarebbe fargli pagare il nostro errore.
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/riparto_consuntivo.xls');
    $classifica = (new ReportRecognizer)->classifica($fogli[0]);

    expect($classifica)->not->toBeEmpty()
        ->and($classifica[0]->tipo)->toBe(ReportType::RipartoConsuntivo);

    // Ordinata davvero, dal più probabile al meno.
    $punteggi = array_map(fn ($e) => $e->confidenza, $classifica);
    $ordinati = $punteggi;
    rsort($ordinati);
    expect($punteggi)->toBe($ordinati);
});

it('ha più fiducia sui report con la testata che su quelli senza', function () {
    // I due compatti escono senza titolo di stampa: sappiamo davvero un po' meno, e il
    // punteggio deve dirlo invece di fingere la stessa certezza.
    $conBanner = riconosciFixture('movimenti.xls');
    $senzaBanner = riconosciFixture('anagrafica_millesimi.xls');

    expect($conBanner->fiducia())->toBe('alta')
        ->and($conBanner->confidenza)->toBeGreaterThan($senzaBanner->confidenza);
});

it('trova la riga di intestazione giusta per ogni report', function (string $file, int $riga) {
    expect(riconosciFixture($file)->rigaIntestazione)->toBe($riga);
})->with([
    'senza banner → riga 0' => ['anagrafica_millesimi.xls', 0],
    'banner di 5 righe → riga 5' => ['movimenti.xls', 5],
    'banner di 6 righe → riga 6' => ['riparto_consuntivo.xls', 6],
    'rate versate → riga 5' => ['rate_versate.xls', 5],
]);

it('elenca fra le ignorate solo quelle che non legge davvero', function () {
    // È il pannello che il §14.0 rende obbligatorio: la paura di chi migra non è «sbaglia un
    // dato», è «perdo dei dati».
    //
    // La prima stesura lo costruiva sulle etichette del **riconoscimento**, che sono poche per
    // costruzione, e finiva per elencare come ignorate indirizzo, email, telefoni e note — che
    // il parser legge tutte. Diceva all'amministratore che stavamo buttando i recapiti dei suoi
    // condòmini. Trovato guardando la schermata, non da un'asserzione: il dato era coerente e
    // il significato sbagliato.
    $esito = riconosciFixture('elenco_unita.xls');

    // Quello che leggiamo davvero non compare fra le ignorate.
    expect($esito->colonneIgnote)->not->toContain('Email')
        ->and($esito->colonneIgnote)->not->toContain('Note')
        ->and($esito->colonneIgnote)->not->toContain('Indirizzo')
        ->and($esito->colonneIgnote)->not->toContain('Spedizioni: Indirizzo');

    // E quello che non leggiamo compare, invece di sparire in silenzio.
    expect($esito->colonneIgnote)->toContain('Titolo')
        ->and($esito->colonneIgnote)->toContain('Note sulla sicurezza');

    expect($esito->colonneRiconosciute)->toContain('Email')
        ->and($esito->colonneRiconosciute)->toContain('Note');
});

it('non segnala come ignorate le colonne che semplicemente non hanno un titolo', function () {
    // Nel riparto sono unità, ruolo e denominazione: le tre più importanti del file.
    // Metterle fra le «ignorate» sarebbe un falso allarme sulle colonne che contano di più.
    $esito = riconosciFixture('riparto_consuntivo.xls');

    expect($esito->colonneIgnote)->not->toContain('')
        ->and(implode(' ', $esito->colonneRiconosciute))->toContain('colonna senza titolo');
});

it('riconosce anche il report che poi va escluso, invece di lasciarlo fra i misteri', function () {
    // `Elenco Attività` non si importa. Ma un file «non riconosciuto» sembra un difetto del
    // sistema, mentre «riconosciuto e fuori ambito» è un'informazione. Sono due schermate
    // diverse per l'amministratore.
    expect(ReportType::ElencoAttivita->importabile())->toBeFalse()
        ->and(ReportType::ElencoAttivita->cosaProduce())->toContain('non importa');
});

it('non riconosce un foglio che non c\'entra niente, invece di indovinare', function () {
    $estraneo = new Foglio('Foglio1', [
        ['Data', 'Cliente', 'Articolo', 'Quantità', 'Prezzo'],
        ['01/01/2026', 'Mario', 'Vite', 10, 0.5],
    ]);

    $esito = (new ReportRecognizer)->riconosci($estraneo);

    expect($esito->riconosciuto())->toBeFalse()
        ->and($esito->tipo)->toBeNull()
        ->and($esito->fiducia())->toBe('nessuna');
});

it('dice all\'amministratore cosa ricava da ogni file, in parole sue', function () {
    // La colonna «Cosa ne ricavo» della schermata S2: l'utente non deve dedurre a cosa serve
    // il file che ha appena trascinato.
    expect(ReportType::ElencoUnita->cosaProduce())->toContain('Titolarità')
        ->and(ReportType::RipartoConsuntivo->cosaProduce())->toContain('Saldi di apertura');

    // Movimenti e rate versate dicevano «Archivio storico dei movimenti», ma l'archivio storico
    // è rimandato alla 1.10.1: il contenuto si legge e si butta, e né la conferma né l'esito ne
    // parlavano più. Chi migra ha paura di perdere pezzi, e questa era la frase che glieli
    // prometteva. Adesso dichiara cosa il file serve davvero a fare.
    expect(ReportType::Movimenti->cosaProduce())->not->toContain('Archivio storico')
        ->and(ReportType::Movimenti->cosaProduce())->toContain('non entra nella 1.10')
        ->and(ReportType::RateVersate->cosaProduce())->toContain('non entra nella 1.10');
});

it('dà a ogni report un nome che un amministratore riconosce', function () {
    // La colonna «Tipo riconosciuto» mostrava il valore dell'enum con gli underscore tolti —
    // «elenco unita», «riparto consuntivo» — minuscolo e senza accenti. La S2 esiste apposta
    // perché l'utente possa **smentire** il riconoscimento, e smentire un identificatore interno
    // è più difficile che smentire un nome.
    foreach (ReportType::cases() as $tipo) {
        expect($tipo->etichettaUmana())
            ->not->toContain('_')
            ->and($tipo->etichettaUmana())->not->toBe($tipo->value);
    }

    expect(ReportType::ElencoUnita->etichettaUmana())->toBe('Elenco unità')
        ->and(ReportType::AnagraficaMillesimi->etichettaUmana())->toBe('Anagrafica + millesimi');
});

it('espone l\'esito in una forma pronta per l\'interfaccia', function () {
    $array = riconosciFixture('riparto_consuntivo.xls')->toArray();

    expect($array)->toHaveKeys(['tipo', 'etichetta', 'importabile', 'confidenza', 'fiducia', 'riga_intestazione', 'colonne'])
        ->and($array['tipo'])->toBe('riparto_consuntivo')
        ->and($array['importabile'])->toBeTrue()
        ->and($array['colonne'])->toHaveKeys(['riconosciute', 'ignorate']);
});
