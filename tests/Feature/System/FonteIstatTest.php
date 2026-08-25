<?php

/**
 * Leggere la fonte ISTAT: il foglio, la data, la conversione.
 *
 * ## La domanda da cui nasce
 *
 * *«Come faremo ad aggiornare il file dell'ISTAT? Controlleremo manualmente ogni tanto?»* — Vincenzo,
 * 19/08/2026. La risposta misurata era: **sì manualmente, e per come stava non sarebbe successo**,
 * perché la ricetta di conversione non era scritta da nessuna parte e niente avvisava.
 *
 * ## Le due trappole della fonte, misurate prima di scrivere il codice
 *
 * **1. Il `last-modified` non è la data che la fonte dichiara.** L'intestazione HTTP dice 26/02/2026;
 * il **nome del foglio** dice «CODICI al 21_02_2026». Sono cinque giorni di scarto, e il primo è la
 * data in cui ISTAT ha caricato il file, il secondo è la data a cui l'elenco è aggiornato. Peggio:
 * il `last-modified` **non è nemmeno stabile fra client** — `curl` riceve `16:17:51` e Guzzle
 * `16:17:49`. Un controllo costruito su quell'intestazione griderebbe al lupo. Si confronta il
 * **nome del foglio**.
 *
 * **2. Aprire l'XLSX costa 142 MB**, cioè muore su un hosting con `memory_limit = 128M`. Ma il nome
 * del foglio **non richiede di aprirlo**: un `.xlsx` è uno zip, e il nome sta in `xl/workbook.xml`.
 * Misurato su cinquanta giri dopo cinque di riscaldamento: **0,13 ms e 2 MB**. È la ragione per cui
 * il controllo è economico e la conversione no.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la rete: nessun test scarica niente da ISTAT. La conversione si prova su un foglio
 * costruito qui, con la stessa forma di quello vero.
 */

use App\Support\LettoreElencoIstat;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as ScrittoreXlsx;

require_once __DIR__.'/../../Support/fogliettoIstat.php';

// ---------------------------------------------------------------------------------------------
// La data della fonte, letta senza aprire il foglio
// ---------------------------------------------------------------------------------------------

it('legge la data della fonte dal nome del foglio, che è dove ISTAT la dichiara', function () {
    $percorso = fogliettoIstat('CODICI al 21_02_2026');

    expect(LettoreElencoIstat::dataDichiarata($percorso))->toBe('2026-02-21');

    unlink($percorso);
});

it('non apre il foglio per leggerne il nome: un xlsx è uno zip', function () {
    // ⚠️ È la ragione per cui il controllo periodico è economico. Aprire il file vero con
    // PhpSpreadsheet costa 142 MB; il nome del foglio sta in `xl/workbook.xml` e si legge con
    // `ZipArchive` — misurato sul file ISTAT vero: 0,13 ms e 2 MB.
    $sorgente = file_get_contents(app_path('Support/LettoreElencoIstat.php'));

    $pezzo = function (string $da) use ($sorgente): string {
        $t = substr($sorgente, strpos($sorgente, $da));

        return substr($t, 0, strpos($t, "\n    }"));
    };

    // La strada economica — nome del foglio e data — non deve toccare PhpSpreadsheet.
    expect($pezzo('function nomeFoglio'))->toContain('ZipArchive')
        ->and($pezzo('function nomeFoglio'))->not->toContain('IOFactory')
        ->and($pezzo('function dataDichiarata'))->not->toContain('IOFactory')
        ->and($pezzo('function dataDa'))->not->toContain('IOFactory');

    // E la strada cara, che è l'unica che può aprirlo, deve almeno filtrare le colonne: senza,
    // porta in memoria anche i codici NUTS e le sei numerazioni storiche delle province.
    expect($pezzo('function converti'))->toContain('setReadFilter')
        ->and($pezzo('function converti'))->toContain('setReadDataOnly');
});

it('un foglio con un nome che non porta una data non passa per buono', function () {
    $percorso = fogliettoIstat('Foglio1');

    expect(fn () => LettoreElencoIstat::dataDichiarata($percorso))
        ->toThrow(RuntimeException::class);

    unlink($percorso);
});

// ---------------------------------------------------------------------------------------------
// La conversione
// ---------------------------------------------------------------------------------------------

it('converte il foglio ISTAT nella forma che il comando sa leggere', function () {
    $percorso = fogliettoIstat();

    $doc = LettoreElencoIstat::converti($percorso);

    expect($doc['aggiornato_al'])->toBe('2026-02-21')
        ->and($doc['foglio'])->toBe('CODICI al 21_02_2026')
        ->and($doc['comuni'])->toHaveCount(3);

    $perCodice = collect($doc['comuni'])->keyBy('codice');

    expect($perCodice['H501']['nome'])->toBe('Roma')
        ->and($perCodice['H501']['sigla'])->toBe('RM')
        ->and($perCodice['H501']['provincia'])->toBe('Roma')
        ->and($perCodice['A179']['altra'])->toBe('Aldein')
        ->and($perCodice['E602']['nome'])->toBe('Linguaglossa');

    unlink($percorso);
});

it('cerca le colonne per intestazione e non per posizione, perché ISTAT le sposta', function () {
    // ⚠️ Questo test **guardava il sorgente** e si accontentava di trovarci due stringhe: la
    // revisione della .60 ha mostrato che la promessa era falsa lo stesso, perché il filtro di
    // lettura era su lettere fisse. Adesso si prova quello che dice: un foglio con **una colonna in
    // più in testa** — cosa che ISTAT ha già fatto, visto che l'XLSX ne ha una che il CSV non ha —
    // deve convertirsi comunque.
    $percorso = fogliettoIstat('CODICI al 21_02_2026', null, spostaDiUnaColonna: true);

    $doc = LettoreElencoIstat::converti($percorso);

    $perCodice = collect($doc['comuni'])->keyBy('codice');

    expect($doc['comuni'])->toHaveCount(3)
        ->and($perCodice['H501']['nome'])->toBe('Roma')
        ->and($perCodice['H501']['provincia'])->toBe('Roma')
        ->and($perCodice['A179']['altra'])->toBe('Aldein');

    unlink($percorso);
});

it('la seconda denominazione resta nulla quando è uguale alla prima', function () {
    $percorso = fogliettoIstat('CODICI al 01_01_2030', [
        ['Roma', 'Roma', 'Lazio', 'Roma', 'RM', 'H501'],
    ]);

    $doc = LettoreElencoIstat::converti($percorso);

    expect($doc['comuni'][0]['altra'])->toBeNull();

    unlink($percorso);
});

it('un foglio senza le colonne che servono si rifiuta, invece di produrre righe monche', function () {
    $libro = new Spreadsheet();
    $libro->getActiveSheet()->setTitle('CODICI al 21_02_2026');
    $libro->getActiveSheet()->fromArray(['Tutt\'altro', 'Roba'], null, 'A1');
    $percorso = sys_get_temp_dir().'/istat-rotto-'.getmypid().'.xlsx';
    (new ScrittoreXlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    expect(fn () => LettoreElencoIstat::converti($percorso))->toThrow(RuntimeException::class);

    unlink($percorso);
});
