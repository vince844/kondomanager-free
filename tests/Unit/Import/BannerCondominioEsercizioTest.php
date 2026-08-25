<?php

use App\Services\Import\Foglio;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\Severita;
use App\Services\Import\SpreadsheetReader;

/**
 * La testata delle stampe → condominio ed esercizio (livelli 1 e 2 del §5).
 *
 * È la fetta che rende vera la frase «carichi un file e vedi comparire il tuo condominio», ed è
 * il punto in cui l'importatore smette di leggere tabelle e comincia a capire *di chi* sono i
 * dati.
 *
 * ## Cosa questi test NON coprono
 *
 * - **Il file che contiene più condomìni** (trappola 14 del §17.8): `Elenco Attività` ha una
 *   colonna `Condominio` con quattro condomìni diversi. Il parser qui assume una testata sola,
 *   che è vero per tutte le stampe che importiamo, e falso in generale.
 * - **Il condominio già esistente in archivio**: la de-duplicazione per codice fiscale è del
 *   committer, non di questo parser. Qui non c'è nessun `da_decidere` perché non c'è ancora
 *   nulla con cui confrontarsi.
 * - **Le stampe senza banner** (`elenco_unita`, `anagrafica_millesimi`): non hanno testata per
 *   costruzione, e il condominio va preso da un altro file del lotto. Verificato qui solo che
 *   il parser lo dica invece di inventarlo.
 */
function bannerDi(string $fixture): array
{
    $fogli = (new SpreadsheetReader)->leggi(__DIR__.'/../../Fixtures/import/danea/'.$fixture);

    return (new BannerParser)->estrai($fogli[0]);
}

it('ricava il condominio dalla testata di una stampa reale', function () {
    $out = bannerDi('movimenti.xls');

    expect($out['condominio'])->not->toBeNull()
        ->and($out['condominio']->nome)->toBe('Fixture')
        ->and($out['condominio']->codiceFiscale)->toBe('00000000001')
        ->and($out['condominio']->cap)->toBe('00100')
        ->and($out['condominio']->comune)->toBe('Roma')
        ->and($out['condominio']->provincia)->toBe('RM');
});

it('compatta i doppi spazi dell\'indirizzo, che negli export ci sono davvero', function () {
    // Gli originali scrivono «Via Nazionale  92»: due spazi che non significano niente e che,
    // se conservati, rendono l'indirizzo diverso da quello scritto a mano dallo stesso utente.
    $out = bannerDi('riparto_consuntivo.xls');

    expect($out['condominio']->indirizzo)->toBe('Via delle Fixture 1')
        ->and($out['condominio']->indirizzo)->not->toContain('  ');
});

it('legge le date dell\'esercizio dal periodo, NON dall\'etichetta', function () {
    // È la regola che questo parser esiste per far rispettare. L'etichetta dice «2024/2025»;
    // dedurne il 1° gennaio – 31 dicembre sposterebbe l'esercizio di due mesi, e con lui ogni
    // riparto che ne discende.
    $out = bannerDi('riparto_consuntivo.xls');
    $esercizio = $out['esercizio'];

    expect($esercizio)->not->toBeNull()
        ->and($esercizio->etichetta)->toBe('2024/2025')
        ->and($esercizio->dataInizio->toDateString())->toBe('2024-11-01')
        ->and($esercizio->dataFine->toDateString())->toBe('2025-10-31')
        ->and($esercizio->isSolare())->toBeFalse();
});

it('riconosce anche l\'esercizio solare, senza trattarlo come il caso normale', function () {
    $out = bannerDi('movimenti.xls');

    expect($out['esercizio']->etichetta)->toBe('2026')
        ->and($out['esercizio']->dataInizio->toDateString())->toBe('2026-01-01')
        ->and($out['esercizio']->dataFine->toDateString())->toBe('2026-12-31')
        ->and($out['esercizio']->isSolare())->toBeTrue()
        ->and($out['esercizio']->tipo)->toBe('ordinario');
});

it('estrae condominio ed esercizio da tutte le stampe con testata', function (string $fixture) {
    $out = bannerDi($fixture);

    expect($out['condominio'])->not->toBeNull()
        ->and($out['esercizio'])->not->toBeNull()
        ->and($out['esito']->confermabile())->toBeTrue();
})->with(['movimenti.xls', 'riparto_consuntivo.xls', 'rate_versate.xls']);

it('non inventa niente quando la stampa non ha testata', function () {
    // `elenco_unita` comincia direttamente dall'intestazione delle colonne. Il condominio
    // andrà preso da un altro file del lotto — ma il parser deve **dirlo**, non dedurlo.
    $out = bannerDi('elenco_unita.xls');

    expect($out['condominio'])->toBeNull()
        ->and($out['esercizio'])->toBeNull()
        ->and($out['esito']->confermabile())->toBeFalse()
        ->and($out['esito']->errori())->toBeGreaterThan(0);

    $codici = array_map(fn ($r) => $r->codice, $out['esito']->rilievi);
    expect($codici)->toContain('condominio.assente')
        ->and($codici)->toContain('esercizio.periodo_assente');
});

it('rifiuta l\'esercizio senza periodo invece di dedurlo dall\'anno', function () {
    // Il caso peggiore possibile: una stampa che dichiara l'anno ma non le date. Dedurre
    // 1 gennaio – 31 dicembre sarebbe corretto per la maggioranza dei condomìni e **sbagliato
    // in silenzio** per tutti quelli con esercizio sfalsato.
    $foglio = new Foglio('Foglio1', [
        ['Movimenti'],
        [],
        ['Condominio Prova - C. Fisc. 00321760282', null, 'Esercizio ordinario "2026"'],
        ['Via Roma, 15 - 35100 Padova (PD)'],
    ]);

    $out = (new BannerParser)->estrai($foglio);

    expect($out['condominio'])->not->toBeNull()
        ->and($out['esercizio'])->toBeNull()
        ->and($out['esito']->confermabile())->toBeFalse();

    $rilievo = $out['esito']->perSeverita(Severita::Errore)[0];
    expect($rilievo->codice)->toBe('esercizio.periodo_assente')
        // Il rimedio non è opzionale: senza, la diagnosi lascia l'amministratore peggio di prima.
        ->and($rilievo->rimedio)->not->toBeNull()
        ->and($rilievo->rimedio)->toContain('sfalsato');
});

it('blocca un periodo che finisce prima di cominciare', function () {
    $foglio = new Foglio('Foglio1', [
        ['Movimenti'],
        ['Condominio Prova - C. Fisc. 00321760282'],
        ['Periodo: 31/12/2026 - 01/01/2026'],
    ]);

    $out = (new BannerParser)->estrai($foglio);

    expect($out['esercizio'])->toBeNull();
    expect(array_map(fn ($r) => $r->codice, $out['esito']->rilievi))
        ->toContain('esercizio.periodo_incoerente');
});

it('importa il condominio anche senza codice fiscale, ma lo dice', function () {
    // Severità diversa dallo stesso tipo di mancanza: il CF assente è un avviso (si può
    // aggiungere dopo), il periodo assente è un errore (non si può indovinare). Un validatore
    // binario dovrebbe trattarli uguali, e sbaglierebbe in un verso o nell'altro.
    $foglio = new Foglio('Foglio1', [
        ['Movimenti'],
        ['Condominio Senza Codice - C. Fisc. '],
        ['Periodo: 01/01/2026 - 31/12/2026'],
    ]);

    $out = (new BannerParser)->estrai($foglio);

    expect($out['condominio'])->not->toBeNull()
        ->and($out['condominio']->codiceFiscale)->toBeNull()
        ->and($out['esito']->confermabile())->toBeTrue()      // l'avviso non blocca
        ->and($out['esito']->valide())->toBe(1)
        ->and($out['esito']->avvisi())->toBe(1);

    expect(array_map(fn ($r) => $r->codice, $out['esito']->rilievi))
        ->toContain('condominio.cf_mancante');
});

it('segnala un codice fiscale di forma sbagliata senza rifiutarlo', function () {
    $foglio = new Foglio('Foglio1', [
        ['Condominio Prova - C. Fisc. 123'],
        ['Periodo: 01/01/2026 - 31/12/2026'],
    ]);

    $out = (new BannerParser)->estrai($foglio);

    expect($out['condominio']->codiceFiscale)->toBe('123')
        ->and($out['esito']->confermabile())->toBeTrue();

    expect(array_map(fn ($r) => $r->codice, $out['esito']->rilievi))
        ->toContain('condominio.cf_malformato');
});

it('segnala il nome con gli accenti già rovinati nel file di origine', function () {
    // Trappola 13: il `?` è nel file di partenza, non nella nostra lettura. Non possiamo
    // ripararlo — non sappiamo quale lettera c'era — ma nasconderlo sarebbe peggio: quel nome
    // finisce sulle convocazioni.
    $foglio = new Foglio('Foglio1', [
        ['Condominio Citt? Giardino - C. Fisc. 00321760282'],
        ['Periodo: 01/01/2026 - 31/12/2026'],
    ]);

    $out = (new BannerParser)->estrai($foglio);

    expect($out['condominio']->nome)->toBe('Citt? Giardino')
        ->and($out['esito']->confermabile())->toBeTrue();

    expect(array_map(fn ($r) => $r->codice, $out['esito']->rilievi))
        ->toContain('condominio.nome_corrotto');
});

it('usa il codice fiscale come chiave di collegamento fra i livelli', function () {
    // §8.2: ogni chiave di join è un dato che l'utente possiede già, mai un id nostro.
    $out = bannerDi('movimenti.xls');

    expect($out['condominio']->chiave())->toBe('00000000001');
});
