<?php

use App\Enums\RuoloAnagraficaImmobile;
use App\Services\Import\Canonical\CanonicalTitolarita;
use App\Services\Import\Foglio;
use App\Services\Import\Parser\ElencoUnitaParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\Severita;
use App\Services\Import\SpreadsheetReader;

/**
 * `elenco_unita` → soggetti, unità e **titolarità** (livelli 4, 5 e 6).
 *
 * È la fetta che vale di più della migrazione: un'unità senza titolare non riceve rate, non
 * compare in morosità e non entra in nessun riparto. È anche il punto in cui il concorrente si
 * ferma — nel suo video dimostrativo, dopo dodici livelli importati, le unità hanno
 * proprietario e inquilino vuoti (§0.3-D).
 *
 * ## Cosa questi test NON coprono
 *
 * - **La scrittura in archivio**: qui si verifica solo la lettura. I livelli che creano
 *   anagrafiche, immobili e titolarità non esistono ancora, e con loro non è ancora verificato
 *   l'impatto dei vincoli `anagrafiche.email UNIQUE` e `anagrafiche.indirizzo NOT NULL`.
 * - **La divisione effettiva di «X / Y» in due soggetti**: qui si produce solo la richiesta di
 *   decisione; chi la esegue non c'è.
 * - **Il report compatto `anagrafica_millesimi`**, che ha ruoli e CF assenti per costruzione e
 *   avrà il suo parser.
 * - **Le quote di comproprietà**: si segnalano quando compaiono nelle note, ma non si leggono.
 *   Nel tracciato non esistono; l'unica fonte pulita è il database nativo (§17.7).
 */
function parsaElencoUnita(): array
{
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/elenco_unita.xls');
    $esito = (new ReportRecognizer)->riconosci($fogli[0]);

    return (new ElencoUnitaParser)->estrai($fogli[0], $esito->rigaIntestazione);
}

it('ricava soggetti, unità e titolarità da un elenco unità reale', function () {
    $out = parsaElencoUnita();

    expect($out['soggetti'])->not->toBeEmpty()
        ->and($out['immobili'])->not->toBeEmpty()
        ->and($out['titolarita'])->not->toBeEmpty();

    // Nove righe di dati, meno unità: è il segno che il raggruppamento funziona.
    expect(count($out['immobili']))->toBeLessThan(count($out['titolarita']));
});

it('traduce i codici di ruolo di Danea nel vocabolario di Kondomanager', function () {
    // `Pr` / `Co` / `Us`. Un parser che cercasse «proprietario» non troverebbe niente — e
    // `Co` è *conduttore*, non *condòmino*: stessa sillaba, significato opposto.
    $ruoli = array_map(fn (CanonicalTitolarita $t) => $t->ruolo, parsaElencoUnita()['titolarita']);

    expect($ruoli)->toContain(RuoloAnagraficaImmobile::PROPRIETARIO)
        ->and($ruoli)->toContain(RuoloAnagraficaImmobile::INQUILINO)
        ->and($ruoli)->toContain(RuoloAnagraficaImmobile::USUFRUTTUARIO);
});

it('raggruppa in una sola unità le righe che condividono palazzina, gruppo e progressivo', function () {
    // Quando un'unità ha proprietario e inquilino, la terna compare due volte. Sono due
    // titolarità sulla stessa unità, non due unità.
    $out = parsaElencoUnita();

    $perImmobile = [];
    foreach ($out['titolarita'] as $t) {
        $perImmobile[$t->immobileRef] = ($perImmobile[$t->immobileRef] ?? 0) + 1;
    }

    expect(max($perImmobile))->toBeGreaterThan(1)
        ->and(array_keys($out['immobili']))->toEqualCanonicalizing(array_keys($perImmobile));
});

it('riconosce lo stesso proprietario su più unità come una persona sola', function () {
    // De-duplicazione per codice fiscale: è ciò che evita di creare cinque «Rossi Maria» per
    // cinque appartamenti dello stesso proprietario.
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Saldo prec', 'Denominazione', 'CodFisc', 'Indirizzo'],
        ['1', '0', '1', 'Pr', '0', 'ROSSI MARIA', 'RSSMRA50A41L100X', 'Via A 1'],
        ['1', '0', '2', 'Pr', '0', 'ROSSI MARIA', 'RSSMRA50A41L100X', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    expect($out['soggetti'])->toHaveCount(1)
        ->and($out['immobili'])->toHaveCount(2)
        ->and($out['titolarita'])->toHaveCount(2);
});

it('inverte il segno del saldo, perché Danea e Kondomanager usano convenzioni opposte', function () {
    // Danea: negativo = debito. Kondomanager: positivo = debito. È l'inversione più pericolosa
    // di tutto l'import — un errore qui si trascina in ogni riparto successivo senza che niente
    // lo segnali.
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Saldo prec', 'Denominazione', 'Indirizzo'],
        ['1', '0', '1', 'Pr', '-827,88', 'DEBITORE', 'Via A 1'],   // deve 827,88 a Danea
        ['1', '0', '2', 'Pr', '124,25', 'CREDITORE', 'Via A 1'],   // ha versato in più
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    // Il debitore diventa POSITIVO nella nostra convenzione, il creditore NEGATIVO.
    expect($out['titolarita'][0]->saldoPrecedenteCents)->toBe(82788)
        ->and($out['titolarita'][1]->saldoPrecedenteCents)->toBe(-12425);
});

it('legge la virgola decimale come la scrive Excel italiano', function () {
    $out = parsaElencoUnita();

    $saldi = array_filter(array_map(fn (CanonicalTitolarita $t) => $t->saldoPrecedenteCents, $out['titolarita']));

    // Se la virgola fosse stata ignorata, «-827,88» diventerebbe -82788 euro invece di -827,88.
    expect($saldi)->not->toBeEmpty()
        ->and(max(array_map('abs', $saldi)))->toBeLessThan(1_000_00);
});

it('salta i ruoli cessati senza trattarli da errore', function () {
    // I ruoli storici non si importano (§3), ma le loro righe non sono sbagliate: segnalarle
    // significherebbe due errori per ogni unità che ha cambiato proprietario. Il loro saldo
    // non si perde — lo raccoglie il livello dei saldi di apertura.
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Denominazione', 'Indirizzo'],
        ['1', '0', '1', 'Pr', 'ATTUALE', 'Via A 1'],
        ['1', '0', '1', 'ex Pr  ', 'PRECEDENTE', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    expect($out['titolarita'])->toHaveCount(1)
        ->and($out['soggetti'])->toHaveCount(1)
        ->and($out['esito']->errori())->toBe(0);
});

it('rifiuta un ruolo che non conosce, spiegando quali esistono', function () {
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Denominazione', 'Indirizzo'],
        ['1', '0', '1', 'Xx', 'IGNOTO', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    expect($out['titolarita'])->toBeEmpty()
        ->and($out['esito']->errori())->toBe(1);

    $rilievo = $out['esito']->perSeverita(Severita::Errore)[0];
    expect($rilievo->codice)->toBe('titolarita.ruolo_sconosciuto')
        ->and($rilievo->riga)->toBe(2)                       // 1-based, come in Excel
        ->and($rilievo->rimedio)->toContain('conduttore');
});

it('chiede cosa fare quando in una cella ci sono due persone, invece di dividerle da solo', function () {
    // «ROSSI G. / BIANCHI N.» possono essere due comproprietari o un nome doppio. Dividendo
    // di nostra iniziativa si creerebbe una persona che non esiste.
    $out = parsaElencoUnita();

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->perSeverita(Severita::DaDecidere));

    expect($codici)->toContain('soggetto.due_persone_in_una_cella')
        ->and($out['esito']->confermabile())->toBeFalse();
});

it('segnala le quote di comproprietà che vivono solo nelle note', function () {
    // Il tracciato non ha un campo per «due proprietari al 50%»: l'amministratore lo scrive a
    // mano nelle note. È l'unica traccia di un'informazione contabilmente rilevante.
    $out = parsaElencoUnita();

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->perSeverita(Severita::Avviso));

    expect($codici)->toContain('titolarita.quota_solo_nelle_note');
});

it('avvisa una volta sola che l\'interno è dedotto dal progressivo', function () {
    // `immobili.interno` è NOT NULL, e negli otto export reali quella colonna è sempre vuota.
    // Sedici avvisi identici sarebbero rumore, e il rumore fa saltare anche quelli che contano.
    $out = parsaElencoUnita();

    $dedotti = array_filter(
        $out['esito']->perSeverita(Severita::Avviso),
        fn ($r) => $r->codice === 'unita.interno_dedotto',
    );

    expect($dedotti)->toHaveCount(1);

    $primo = reset($out['immobili']);
    expect($primo->interno)->toBe($primo->progressivo);
});

it('conserva i telefoni come sono scritti, anche quando sono scritti male', function () {
    // `331 / 1053945` e `377-19.17.332` sono numeri veri scritti male. Normalizzarli qui
    // significherebbe inventare un formato che l'amministratore non ha scelto.
    $out = parsaElencoUnita();

    $conTelefono = array_filter($out['soggetti'], fn ($s) => $s->telefoni !== []);

    expect($conTelefono)->not->toBeEmpty();

    $telefoni = array_merge(...array_map(fn ($s) => $s->telefoni, array_values($conTelefono)));
    expect(implode(' ', $telefoni))->toMatch('#[/.-]#');
});

it('tiene il progressivo come stringa, perché 016 non è 16', function () {
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Denominazione', 'Indirizzo'],
        ['1', '0', '016', 'Pr', 'UNO', 'Via A 1'],
        ['1', '0', '16', 'Pr', 'DUE', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    // Due unità distinte. Trattandoli da interi ne resterebbe una, con due titolarità
    // sovrapposte e un proprietario sparito.
    expect($out['immobili'])->toHaveCount(2)
        ->and(array_keys($out['immobili']))->toBe(['1-0-016', '1-0-16']);
});

it('non lascia passare una riga senza la chiave dell\'unità', function () {
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Denominazione', 'Indirizzo'],
        ['1', '0', '', 'Pr', 'SENZA PROGRESSIVO', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    expect($out['esito']->errori())->toBe(1)
        ->and($out['esito']->perSeverita(Severita::Errore)[0]->codice)->toBe('unita.chiave_incompleta');
});

it('accetta il gruppo che vale zero, che è un valore e non un\'assenza', function () {
    // Negli export reali il gruppo vale `A` in un file e `0` in un altro. Un controllo scritto
    // con `empty()` scarterebbe tutte le righe del secondo.
    $foglio = new Foglio('Unità', [
        ['Palazzina', 'Gruppo unità', 'Progressivo', 'Ruolo', 'Denominazione', 'Indirizzo'],
        ['1', '0', '1', 'Pr', 'VALIDA', 'Via A 1'],
    ]);

    $out = (new ElencoUnitaParser)->estrai($foglio, 0);

    expect($out['esito']->errori())->toBe(0)
        ->and($out['immobili'])->toHaveKey('1-0-1');
});
