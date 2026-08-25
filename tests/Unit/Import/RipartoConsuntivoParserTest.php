<?php

use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\Foglio;
use App\Services\Import\Parser\RipartoConsuntivoParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\Severita;
use App\Services\Import\SpreadsheetReader;

/**
 * Il riparto consuntivo → saldi di apertura e totale di riferimento (livello 10).
 *
 * È il dato con le conseguenze più pesanti dell'intera migrazione: da qui nascono le morosità,
 * i solleciti e i decreti ingiuntivi. Un saldo sbagliato non si nota subito, si trascina.
 *
 * ## Cosa questi test NON coprono
 *
 * - **La scrittura nel wallet** e la quadratura bloccante: sono del livello, che verrà dopo.
 * - **La risoluzione dell'unità**: il riparto stampa solo il progressivo (`01`, `016`) e non la
 *   terna dell'elenco unità. Su un condominio con più palazzine è **ambiguo**, e il parser non
 *   lo risolve: espone progressivo e nome, e lascia decidere a chi ha davanti le unità vere.
 * - **Il caso in cui il totale del file è sbagliato** rispetto alle sue stesse righe: qui si
 *   legge quello che c'è, il confronto è del livello.
 */
function riparto(): array
{
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/riparto_consuntivo.xls');
    $riga = (new ReportRecognizer)->riconosci($fogli[0])->rigaIntestazione;

    return (new RipartoConsuntivoParser)->estrai($fogli[0], $riga);
}

it('legge il totale di riferimento dalla stampa, senza chiederlo all\'utente', function () {
    // Nessun altro legge questo numero. È il motivo per cui possiamo mostrare la riga «scarto
    // 0,00» che nessun concorrente può mostrare: il dato di verifica è dentro il file che
    // l'amministratore ha appena caricato.
    $out = riparto();

    expect($out['totale_riferimento_cents'])->not->toBeNull()
        // −1.261,74 in Danea → +126.174 centesimi nella nostra convenzione (positivo = debito)
        ->and($out['totale_riferimento_cents'])->toBe(126174);
});

it('inverte il segno: chi in Danea è negativo qui è a debito', function () {
    $out = riparto();

    $perNome = [];
    foreach ($out['saldi'] as $s) {
        $perNome[$s->soggettoNome ?? 'solidale'] = $s->importoCents;
    }

    // GIALLI FRANCESCA ha −1.202,84 nel riparto: deve quei soldi.
    expect($perNome['GIALLI FRANCESCA'])->toBe(120284)
        // ROSSI MARIA GRAZIA ha +169,57: ha versato in più, quindi è a credito.
        ->and($perNome['ROSSI MARIA GRAZIA'])->toBe(-16957);
});

it('la somma delle righe più gli arrotondamenti fa il totale dichiarato', function () {
    // È la quadratura, verificata sul file reale. Se questa non torna, o il parser sbaglia o
    // il file è stato manomesso — e in entrambi i casi l'import non deve partire.
    $out = riparto();

    $somma = array_sum(array_map(fn (CanonicalSaldo $s) => $s->importoCents, $out['saldi']));

    expect($somma + $out['arrotondamenti_cents'])->toBe($out['totale_riferimento_cents']);
});

it('non trasforma la riga Arrotondamenti in un condòmino', function () {
    // Trattarla da soggetto creerebbe una persona di nome «Arrotondamenti»; scartarla e basta
    // farebbe fallire la quadratura per pochi centesimi, bloccando l'import di tutti.
    $out = riparto();

    $nomi = array_map(fn (CanonicalSaldo $s) => $s->soggettoNome, $out['saldi']);

    expect($nomi)->not->toContain('Arrotondamenti')
        ->and($out['arrotondamenti_cents'])->not->toBe(0);
});

it('non importa la riga del totale come se fosse una posizione', function () {
    $out = riparto();

    $unita = array_map(fn (CanonicalSaldo $s) => $s->immobileRef, $out['saldi']);

    expect($unita)->not->toContain('TOTALE COMPLESSIVO');
});

it('porta il saldo del titolare cessato sull\'unità invece di perderlo', function () {
    // Il §3 esclude i ruoli storici, ma il loro debito fa parte del totale: scartarlo
    // significherebbe non quadrare. Diventa un saldo **solidale** sull'unità — la forma con cui
    // Kondomanager rappresenta un debito che segue la casa e non la persona (art. 63).
    $out = riparto();

    $cessati = array_values(array_filter($out['saldi'], fn (CanonicalSaldo $s) => $s->daTitolareCessato));

    expect($cessati)->toHaveCount(2)
        ->and($cessati[0]->isSolidale())->toBeTrue()
        ->and($cessati[0]->soggettoNome)->toBeNull();

    // E lo dice, perché è una cosa che l'amministratore deve controllare.
    $codici = array_map(fn ($r) => $r->codice, $out['esito']->perSeverita(Severita::Avviso));
    expect($codici)->toContain('riparto.saldi_di_titolari_cessati');
});

it('scarta i saldi a zero invece di creare righe che non dicono niente', function () {
    $foglio = new Foglio('Foglio1', [
        ['', '', '', 'Saldo finale'],
        ['01', 'Pr', 'CHI DEVE', '-100,00'],
        ['02', 'Pr', 'CHI È IN PARI', '0'],
    ]);

    $out = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    expect($out['saldi'])->toHaveCount(1)
        ->and($out['saldi'][0]->soggettoNome)->toBe('CHI DEVE');
});

it('espone il progressivo così come lo stampa il riparto, senza normalizzarlo', function () {
    // `01` e `016` non sono `1` e `16`: la risoluzione dell'unità è del livello, che ha davanti
    // le unità vere e può disambiguare. Normalizzare qui butterebbe l'informazione che serve.
    $out = riparto();

    $unita = array_map(fn (CanonicalSaldo $s) => $s->immobileRef, $out['saldi']);

    expect($unita)->toContain('01')
        ->and($unita)->toContain('03');
});

it('si ferma se manca la colonna del saldo finale', function () {
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Nome', 'Totale gestione'],
        ['01', 'Pr', 'TIZIO', '-100,00'],
    ]);

    $out = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    expect($out['saldi'])->toBeEmpty()
        ->and($out['esito']->errori())->toBe(1)
        ->and($out['esito']->perSeverita(Severita::Errore)[0]->codice)->toBe('riparto.colonna_saldo_assente');
});

it('avvisa se il file non porta il totale, invece di fingere di poter quadrare', function () {
    $foglio = new Foglio('Foglio1', [
        ['', '', '', 'Saldo finale'],
        ['01', 'Pr', 'TIZIO', '-100,00'],
    ]);

    $out = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    expect($out['totale_riferimento_cents'])->toBeNull();

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->rilievi);
    expect($codici)->toContain('riparto.totale_assente')
        // Non blocca: si può importare lo stesso, ma la verifica automatica non c'è.
        ->and($out['esito']->confermabile())->toBeTrue();
});

it('segnala un saldo illeggibile indicando di chi è e su quale riga', function () {
    $foglio = new Foglio('Foglio1', [
        ['', '', '', 'Saldo finale'],
        ['01', 'Pr', 'TIZIO', 'n.d.'],
    ]);

    $out = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    $rilievo = $out['esito']->perSeverita(Severita::Errore)[0];

    expect($rilievo->codice)->toBe('riparto.saldo_non_numerico')
        ->and($rilievo->messaggio)->toContain('TIZIO')
        ->and($rilievo->riga)->toBe(2);
});

it('riconosce il cessato anche quando Danea gli attacca il conteggio dei giorni', function () {
    // Trovato sul primo riparto vero: quando la titolarità cambia durante l'esercizio, Danea
    // scrive il pro-rata dentro la colonna del ruolo — «ex Pr 336 gg», «ex Us 336 gg»,
    // «Pr 29 gg». Il confronto era **esatto** su «ex pr», quindi quelle righe non risultavano
    // cessate: il loro saldo veniva cercato fra le persone dell'elenco unità, dove per
    // definizione non c'è più, e l'importazione si fermava con un errore irrisolvibile.
    //
    // È il caso più comune che esista — una compravendita a metà anno — e nessuna fixture
    // costruita a tavolino lo conteneva.
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Denominazione', 'Saldo finale'],
        ['01', 'Pr', 'ROSSI MARIO', '-100,00'],
        ['02', 'ex Pr 336 gg', 'BIANCHI LUIGI', '-50,00'],
        ['02', 'Pr 29 gg', 'VERDI ANNA', '-25,00'],
        ['03', 'ex Us 336 gg', 'NERI CARLO', '-30,00'],
        ['', '', 'TOTALE COMPLESSIVO', '-205,00'],
    ]);

    $esito = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    $perNome = [];
    foreach ($esito['saldi'] as $s) {
        $perNome[$s->importoCents] = $s;
    }

    // Gli importi sono già in centesimi **col segno invertito**: in Danea negativo = a credito,
    // qui positivo = a debito. «-50,00» nel file diventa 5000.
    //
    // -50,00 → «ex Pr 336 gg» e -30,00 → «ex Us 336 gg»: cessati, quindi solidali sull'unità.
    expect($perNome[5000]->daTitolareCessato)->toBeTrue()
        ->and($perNome[5000]->isSolidale())->toBeTrue()
        ->and($perNome[3000]->daTitolareCessato)->toBeTrue()
        ->and($perNome[3000]->isSolidale())->toBeTrue()
        // «Pr 29 gg» è un titolare **attuale** entrato in corso d'anno: resta suo, col nome.
        ->and($perNome[2500]->daTitolareCessato)->toBeFalse()
        ->and($perNome[2500]->soggettoNome)->toBe('VERDI ANNA');
});

it('segnala che la titolarità è cambiata in corso d\'anno, invece di buttare via il dato', function () {
    // Il conteggio dei giorni è l'**unico** posto in cui il file dichiara una compravendita
    // durante l'esercizio. Kondomanager non modella ancora il subentro: la titolarità entra
    // senza decorrenza e il saldo di chi è uscito finisce sull'unità. È una semplificazione
    // ragionevole — buttare via l'informazione senza dirla no, perché chi deve ripartire una
    // spesa pro-rata fra i due la scoprirebbe solo sbagliando.
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Denominazione', 'Saldo finale'],
        ['01', 'Pr', 'ROSSI MARIO', '-100,00'],
        ['02', 'ex Pr 336 gg', 'BIANCHI LUIGI', '-50,00'],
        ['02', 'Pr 29 gg', 'VERDI ANNA', '-25,00'],
        ['', '', 'TOTALE COMPLESSIVO', '-175,00'],
    ]);

    $codici = array_map(
        fn ($r) => $r->codice,
        (new RipartoConsuntivoParser)->estrai($foglio, 0)['esito']->perSeverita(Severita::Avviso),
    );

    expect($codici)->toContain('riparto.titolarita_cambiata_in_corso_anno');
});

it('non confonde una particella del cognome con un ruolo o un separatore', function () {
    // «DAL PONTE GIOVANNI», «DE MAGISTRIS GLORIA», «DI LUCA ANNA»: nel corpus vero ci sono, e
    // funzionano perché il nome è **un campo solo** e il confronto è sull'intera stringa
    // normalizzata. Questo test esiste perché qualcuno, un giorno, sarà tentato di spezzare
    // nome e cognome sullo spazio: da lì in poi «DE MAGISTRIS» diventerebbe cognome «DE».
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Denominazione', 'Saldo finale'],
        ['01', 'Pr', 'DE MAGISTRIS GLORIA', '-100,00'],
        ['02', 'Pr', 'DAL PONTE GIOVANNI', '-50,00'],
        ['03', 'Pr', "D'AMICO LUCA /  DI LUCA ANNA", '-30,00'],
        ['', '', 'TOTALE COMPLESSIVO', '-180,00'],
    ]);

    $nomi = array_map(
        fn ($s) => $s->soggettoNome,
        (new RipartoConsuntivoParser)->estrai($foglio, 0)['saldi'],
    );

    expect($nomi)->toBe(['DE MAGISTRIS GLORIA', 'DAL PONTE GIOVANNI', "D'AMICO LUCA /  DI LUCA ANNA"]);
});

it('legge un saldo testuale sopra i mille euro in formattazione italiana', function () {
    // Trovato dalla revisione avversariale: la validazione convertiva la virgola in punto
    // PRIMA di togliere il punto delle migliaia, quindi «-1.202,84» diventava «-1.202.84» —
    // due punti — e is_numeric() lo scartava come testo, prima ancora di arrivare a
    // MoneyHelper::toCents() (che invece gestisce il caso correttamente). Il saldo di un
    // condòmino sopra i mille euro spariva con l'errore «non è un numero».
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Denominazione', 'Saldo finale'],
        ['01', 'Pr', 'GRANDE DEBITORE', '-1.202,84'],
        ['TOTALE COMPLESSIVO', '', '', '-1.202,84'],
    ]);

    $esito = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    expect($esito['saldi'])->toHaveCount(1)
        ->and($esito['saldi'][0]->importoCents)->toBe(120284)
        ->and($esito['totale_riferimento_cents'])->toBe(120284)
        ->and($esito['esito']->errori())->toBe(0);
});

it('rifiuta ancora un saldo genuinamente illeggibile, sotto i mille euro', function () {
    // Il controllo esiste per un motivo vero: una cella con del testo al posto di un
    // importo. La correzione dell'ordine virgola/punto non deve indebolirlo.
    $foglio = new Foglio('Foglio1', [
        ['Unità', 'Ruolo', 'Denominazione', 'Saldo finale'],
        ['01', 'Pr', 'CELLA ROTTA', 'vedi nota a margine'],
    ]);

    $esito = (new RipartoConsuntivoParser)->estrai($foglio, 0);

    expect($esito['saldi'])->toBeEmpty()
        ->and($esito['esito']->errori())->toBe(1);
});
