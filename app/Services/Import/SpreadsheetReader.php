<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use RuntimeException;

/**
 * Apre un file e ne restituisce i fogli non vuoti, con i valori **grezzi**.
 *
 * Formati: `.xls` (BIFF, il formato con cui Danea esporta ancora oggi da un modello del 2004),
 * `.xlsx` e `.csv`.
 *
 * ## Perché i valori restano grezzi
 *
 * `setReadDataOnly(true)` e nessuna formattazione: le date escono come seriali, i decimali
 * come sono scritti. La conversione la fa il parser, che sa cosa sta leggendo — vedi `Foglio`.
 *
 * ## Il limite di dimensione è dichiarato, non scoperto
 *
 * PhpSpreadsheet carica il foglio intero in memoria. Un tetto va messo, ma va messo **prima**
 * del caricamento e detto all'utente in anticipo (§7 «Nessun limite di dimensione arbitrario»):
 * scoprire il limite a caricamento avvenuto è il momento peggiore per scoprirlo.
 */
final class SpreadsheetReader
{
    /**
     * Il tetto vale per il singolo file, e nasce dal tempo di elaborazione, non da un numero
     * tondo: oltre questa soglia l'apertura di un `.xls` sfonda regolarmente il limite di
     * memoria di un hosting condiviso. Va mostrato in interfaccia **prima** del caricamento.
     */
    public const DIMENSIONE_MASSIMA_BYTE = 25 * 1024 * 1024;

    /** @var list<string> */
    public const ESTENSIONI_AMMESSE = ['xls', 'xlsx', 'csv'];

    /**
     * @return list<Foglio>  solo i fogli con almeno una cella piena, nell'ordine del file
     *
     * @throws RuntimeException  se il file non è leggibile, è troppo grande o non è un formato ammesso
     */
    public function leggi(string $percorso): array
    {
        if (! is_readable($percorso)) {
            throw new RuntimeException("File non leggibile: {$percorso}");
        }

        $dimensione = filesize($percorso) ?: 0;
        if ($dimensione > self::DIMENSIONE_MASSIMA_BYTE) {
            throw new RuntimeException(sprintf(
                'Il file pesa %s e il limite è %s. Esporta un esercizio alla volta, oppure scrivici.',
                self::formattaByte($dimensione),
                self::formattaByte(self::DIMENSIONE_MASSIMA_BYTE),
            ));
        }

        $estensione = strtolower(pathinfo($percorso, PATHINFO_EXTENSION));
        if (! in_array($estensione, self::ESTENSIONI_AMMESSE, true)) {
            throw new RuntimeException(sprintf(
                'Formato «%s» non gestito. Sono accettati: %s.',
                $estensione !== '' ? $estensione : 'sconosciuto',
                implode(', ', self::ESTENSIONI_AMMESSE),
            ));
        }

        $reader = $this->creaReader($percorso, $estensione);
        $reader->setReadDataOnly(true);

        try {
            $spreadsheet = $reader->load($percorso);
        } catch (ReaderException $e) {
            throw new RuntimeException(
                'Il file non si apre: potrebbe essere danneggiato, protetto da password o '
                .'salvato in un formato diverso da quello che l\'estensione dichiara. '
                .'('.$e->getMessage().')',
                previous: $e,
            );
        }

        $fogli = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            // toArray(nullValue, calculateFormulas, formatData, returnCellRef).
            // `formatData: false` è ciò che tiene i valori grezzi: con `true` una data
            // tornerebbe già formattata secondo il formato della cella, cioè secondo una
            // decisione presa da chi ha salvato il file — e nei file reali quella decisione
            // cambia da colonna a colonna.
            $righe = $sheet->toArray(null, true, false, false);
            $foglio = new Foglio($sheet->getTitle(), array_values($righe));

            if (! $foglio->isVuoto()) {
                $fogli[] = $foglio;
            }
        }

        // Libera subito: PhpSpreadsheet tiene in memoria l'intero foglio finché l'oggetto vive.
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        if ($fogli === []) {
            throw new RuntimeException('Il file non contiene nessun foglio con dei dati.');
        }

        return $fogli;
    }

    private function creaReader(string $percorso, string $estensione): IReader
    {
        // Per il CSV non ci si affida a `identify()`: un CSV è testo, e l'identificazione
        // euristica può scambiarlo per altro. L'estensione qui è l'informazione più
        // affidabile che abbiamo.
        if ($estensione === 'csv') {
            /** @var \PhpOffice\PhpSpreadsheet\Reader\Csv $reader */
            $reader = IOFactory::createReader('Csv');
            $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);

            return $reader;
        }

        try {
            // `identify()` legge la firma del file, non l'estensione: intercetta il caso —
            // frequente — del file rinominato a mano in `.xls` ma salvato come `.xlsx`.
            return IOFactory::createReader(IOFactory::identify($percorso));
        } catch (ReaderException) {
            return IOFactory::createReader($estensione === 'xls' ? 'Xls' : 'Xlsx');
        }
    }

    private static function formattaByte(int $byte): string
    {
        return $byte >= 1024 * 1024
            ? round($byte / (1024 * 1024), 1).' MB'
            : round($byte / 1024).' KB';
    }
}
