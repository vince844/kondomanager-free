<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;

/**
 * Legge il foglio ISTAT «Struttura ATECO» e lo converte nella forma che il comando carica.
 *
 * ## Cosa c'è nel file, misurato e non dedotto
 *
 * `StrutturaATECO-2025-IT-EN-1.xlsx` ha due fogli — «Legenda» e «ATECO 2025 Struttura» — e il
 * secondo contiene **3.258 righe**, di cui la prima è l'intestazione. Le colonne che ci servono sono
 * sette su otto:
 *
 * | col | variabile ISTAT              | qui           |
 * | :-- | :--------------------------- | :------------ |
 * | A   | `ORDINE_CODICE_ATECO_2025`   | `ordine`      |
 * | B   | `CODICE_ATECO_2025`          | `codice`      |
 * | C   | `TITOLO_ITALIANO_ATECO_2025` | `titolo`      |
 * | D   | `TITOLO_INGLESE_ATECO_2025`  | `titolo_en`   |
 * | E   | `GERARCHIA_ATECO_2025`       | `livello`     |
 * | F   | `CODICE_PADRE_ATECO_2025`    | `codice_padre`|
 *
 * ## ⚠️ La data non c'è, ed è una misura
 *
 * Sui Comuni ISTAT dichiara la data nel **nome del foglio** («CODICI al 21_02_2026») e
 * `LettoreElencoIstat::dataDa()` la estrae da lì. Qui **non esiste**: verificata cella per cella su
 * entrambi i fogli, nessuna data. Non è una dimenticanza — l'ATECO cambia per **revisione**, non in
 * continuazione, e la sua identità è il nome della revisione.
 *
 * Per questo il documento prodotto porta `versione_fonte` («ATECO 2025», letta dal nome del foglio)
 * e **non** un `aggiornato_al`. Chi vuole comunque timbrare una data la dichiara al comando.
 *
 * ## Perché il filtro di lettura
 *
 * Stessa ragione dei Comuni: `setReadDataOnly(true)` più un `IReadFilter` che lascia passare le sole
 * colonne A–F evita di costruire in memoria stili e colonne che non guardiamo. Il file è piccolo
 * (196 KB, 3.258 righe contro le 7.894 dei Comuni), ma il comando deve girare anche dove il
 * `memory_limit` è 128M.
 */
class LettoreStrutturaAteco
{
    /** Le colonne che leggiamo, in ordine. */
    private const COLONNE = ['A', 'B', 'C', 'D', 'E', 'F'];

    /** Il foglio dei dati si riconosce dal nome: contiene «Struttura». */
    public static function nomeFoglioDati(string $percorso): string
    {
        foreach (self::nomiFogli($percorso) as $nome) {
            if (str_contains(mb_strtolower($nome), 'struttura')) {
                return $nome;
            }
        }

        throw new RuntimeException(
            'Nel file non c\'è nessun foglio con «Struttura» nel nome: non sembra la struttura ATECO di ISTAT. '
            . 'Fogli trovati: ' . implode(', ', self::nomiFogli($percorso)) . '.'
        );
    }

    /**
     * La revisione, ricavata dal nome del foglio: «ATECO 2025 Struttura» → «ATECO 2025».
     *
     * È il timbro che finisce su ogni riga. Se il nome del foglio non la contenesse, ci si ferma
     * invece di inventarla: una tabella che non sa da quale classificazione viene non è
     * interrogabile con onestà, ed è meglio un comando che si rifiuta di un dato che mente.
     */
    public static function versioneDa(string $nomeFoglio): string
    {
        if (! preg_match('/ATECO\s*(\d{4})/i', $nomeFoglio, $m)) {
            throw new RuntimeException(
                "Dal nome del foglio «{$nomeFoglio}» non si ricava la revisione (attesa una forma «ATECO 2025»). "
                . 'Se ISTAT ha cambiato il nome del foglio, va aggiornato questo lettore invece di indovinare.'
            );
        }

        return 'ATECO ' . $m[1];
    }

    /** I nomi dei fogli, letti dallo zip senza aprire il documento. */
    public static function nomiFogli(string $percorso): array
    {
        if (! is_file($percorso) || ! is_readable($percorso)) {
            throw new RuntimeException("File non leggibile: {$percorso}");
        }

        $zip = new \ZipArchive();

        if ($zip->open($percorso) !== true) {
            throw new RuntimeException("Non è un file XLSX: {$percorso}");
        }

        $xml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($xml === false || ! preg_match_all('/<sheet[^>]*name="([^"]+)"/', $xml, $m)) {
            throw new RuntimeException('Il file non contiene nessun foglio leggibile.');
        }

        return array_map(fn (string $n) => html_entity_decode($n, ENT_QUOTES | ENT_XML1), $m[1]);
    }

    /**
     * @return array{fonte: string, versione: string, codici: array<int, array<string, mixed>>}
     */
    public static function converti(string $percorso): array
    {
        $foglio = self::nomeFoglioDati($percorso);
        $versione = self::versioneDa($foglio);

        $lettore = IOFactory::createReader('Xlsx');
        $lettore->setReadDataOnly(true);
        $lettore->setLoadSheetsOnly([$foglio]);
        $lettore->setReadFilter(new class implements IReadFilter
        {
            public function readCell($colonna, $riga, $foglio = null): bool
            {
                return in_array($colonna, LettoreStrutturaAteco::colonne(), true);
            }
        });

        $righe = $lettore->load($percorso)->getSheetByName($foglio)->toArray(null, true, false, false);

        // La prima riga è l'intestazione delle variabili, come dichiara la «Legenda».
        array_shift($righe);

        $codici = [];

        foreach ($righe as $r) {
            $codice = trim((string) ($r[1] ?? ''));

            if ($codice === '') {
                continue;
            }

            $livello = (int) trim((string) ($r[4] ?? 0));

            if ($livello < 1 || $livello > 6) {
                throw new RuntimeException(
                    "Il codice «{$codice}» dichiara il livello «{$livello}», fuori dall'intervallo 1–6 della classificazione. "
                    . 'O la fonte è cambiata, o il file non è quello giusto.'
                );
            }

            $padre = trim((string) ($r[5] ?? ''));

            $codici[] = [
                'ordine'       => (int) trim((string) ($r[0] ?? 0)),
                'codice'       => $codice,
                'titolo'       => trim((string) ($r[2] ?? '')),
                'titolo_en'    => trim((string) ($r[3] ?? '')) ?: null,
                'livello'      => $livello,
                'codice_padre' => $padre !== '' ? $padre : null,
            ];
        }

        if ($codici === []) {
            throw new RuntimeException('Il foglio non contiene nessun codice.');
        }

        return [
            'fonte'    => 'ISTAT — Struttura ' . $versione,
            'versione' => $versione,
            'codici'   => $codici,
        ];
    }

    /** Esposto perché la classe anonima del filtro possa leggerlo. */
    public static function colonne(): array
    {
        return self::COLONNE;
    }
}
