<?php

/**
 * Fabbrica condivisa di fogli ISTAT finti, per i test.
 *
 * Sta qui e non dentro un file di test perché la usano due file — `FonteIstatTest` e
 * `ElencoComuniTest` — e Pest carica ogni file di test una volta sola: dichiararla in tutti e due
 * darebbe un «cannot redeclare».
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as ScrittoreXlsx;

/**
 * Un foglio con la forma di quello ISTAT: stesse intestazioni, stesso nome, poche righe.
 *
 * Le intestazioni sono copiate **alla lettera** dal file vero, perché il lettore le cerca per
 * contenuto e non per posizione: nel CSV e nell'XLSX le colonne stanno in ordine diverso, ed è la
 * trappola che ha reso i due file non interscambiabili.
 */
function fogliettoIstat(string $nomeFoglio = 'CODICI al 21_02_2026', ?array $righe = null, bool $spostaDiUnaColonna = false): string
{
    $intestazioni = [
        'Codice Regione', 'Codice dell\'Unità territoriale sovracomunale', 'Codice Provincia (Storico)(1)',
        'Progressivo del Comune (2)', 'Codice Comune formato alfanumerico',
        'Denominazione (Italiana e straniera)', 'Denominazione in italiano', 'Denominazione altra lingua',
        'Codice Ripartizione Geografica', 'Ripartizione geografica', 'Denominazione Regione',
        'Denominazione dell\'Unità territoriale sovracomunale', 'Tipologia di Unità territoriale sovracomunale',
        'Flag Comune capoluogo', 'Sigla automobilistica', 'Codice Comune formato numerico',
        'x', 'y', 'z', 'w', 'Codice Catastale del Comune',
    ];

    $righe ??= [
        ['Roma', null, 'Lazio', 'Roma', 'RM', 'H501'],
        ['Aldino', 'Aldein', 'Trentino-Alto Adige/Südtirol', 'Bolzano/Bozen', 'BZ', 'A179'],
        ['Linguaglossa', null, 'Sicilia', 'Catania', 'CT', 'E602'],
    ];

    // `$spostaDiUnaColonna` simula quello che ISTAT ha già fatto una volta: una colonna in più in
    // testa. Serve a provare che il lettore trova le colonne per intestazione e non per lettera.
    if ($spostaDiUnaColonna) {
        array_unshift($intestazioni, 'Colonna nuova che ISTAT ha aggiunto');
    }

    $prima = $spostaDiUnaColonna ? 'B' : 'A';

    $libro = new Spreadsheet();
    $foglio = $libro->getActiveSheet();
    $foglio->setTitle($nomeFoglio);
    $foglio->fromArray($intestazioni, null, 'A1');

    $lettera = function (string $base) use ($spostaDiUnaColonna): string {
        $i = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($base) + ($spostaDiUnaColonna ? 1 : 0);

        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    };

    foreach ($righe as $i => $r) {
        [$nome, $altra, $regione, $provincia, $sigla, $codice] = $r;
        $foglio->setCellValue($lettera('G').($i + 2), $nome);
        $foglio->setCellValue($lettera('H').($i + 2), $altra);
        $foglio->setCellValue($lettera('K').($i + 2), $regione);
        $foglio->setCellValue($lettera('L').($i + 2), $provincia);
        $foglio->setCellValue($lettera('O').($i + 2), $sigla);
        $foglio->setCellValue($lettera('U').($i + 2), $codice);
    }

    $percorso = sys_get_temp_dir().'/istat-prova-'.getmypid().'-'.uniqid().'.xlsx';
    (new ScrittoreXlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    return $percorso;
}
