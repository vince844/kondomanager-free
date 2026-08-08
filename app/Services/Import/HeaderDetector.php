<?php

namespace App\Services\Import;

/**
 * Trova la riga di intestazione cercando un **quorum di etichette note**, mai assumendo la riga 0.
 *
 * ## Perché non basta guardare la prima riga
 *
 * Sui file reali di Danea l'intestazione sta in quattro posti diversi (§17.1):
 * `anagrafica.xls` a riga 0, `Elenco Attività.xls` a riga 2, `Movimenti.xls` e
 * `rate versate.xls` a riga 5, `riparto consuntivo` a riga 6. Sopra c'è un banner con il
 * titolo del report, il condominio con il suo codice fiscale, l'indirizzo e il periodo — righe
 * che servono, ma che non sono l'intestazione.
 *
 * ## Le due cose che i file veri hanno insegnato
 *
 * 1. **Le colonne chiave possono non avere nome.** Nel riparto consuntivo le prime tre celle
 *    della riga di intestazione sono **vuote**: sono unità, ruolo e denominazione. Un
 *    rilevatore che pretende la prima cella piena non trova quella riga. Qui si contano le
 *    corrispondenze fra le celle **non vuote**, e la posizione non conta.
 * 2. **Il quorum va misurato sulle etichette trovate, non sulla percentuale di colonne.** Un
 *    report con 33 colonne e uno con 8 hanno la stessa intestazione riconoscibile da poche
 *    parole caratteristiche.
 */
final class HeaderDetector
{
    /**
     * Quante righe dall'alto vale la pena ispezionare. Il banner più lungo fra i file reali
     * occupa sei righe; dodici lascia margine senza far scorrere un foglio intero.
     */
    public const RIGHE_DA_ISPEZIONARE = 12;

    /**
     * @param  list<string>  $etichetteNote  le etichette caratteristiche del report-type
     * @param  int  $quorum  quante ne devono comparire sulla stessa riga
     * @return array{riga: int, trovate: list<string>}|null  `riga` è 0-based
     */
    public function trova(Foglio $foglio, array $etichetteNote, int $quorum = 3): ?array
    {
        $attese = [];
        foreach ($etichetteNote as $etichetta) {
            $attese[self::normalizza($etichetta)] = $etichetta;
        }

        $limite = min($foglio->numeroRighe(), self::RIGHE_DA_ISPEZIONARE);
        $migliore = null;

        for ($i = 0; $i < $limite; $i++) {
            $trovate = [];

            foreach ($foglio->riga($i) as $cella) {
                $chiave = self::normalizza((string) ($cella ?? ''));

                if ($chiave === '' || ! isset($attese[$chiave])) {
                    continue;
                }

                // Una stessa etichetta può comparire più volte sulla riga — nel riparto
                // consuntivo `mill.` si ripete accanto a ogni voce di spesa. Conta una volta:
                // altrimenti tre ripetizioni della stessa parola raggiungerebbero il quorum
                // da sole, e una riga di totali potrebbe spacciarsi per intestazione.
                $trovate[$chiave] = $attese[$chiave];
            }

            if (count($trovate) < $quorum) {
                continue;
            }

            // Si tiene la riga con più corrispondenze, non la prima che raggiunge il quorum:
            // nei report con banner può capitare che una riga di testata contenga per caso
            // una parola dell'intestazione.
            if ($migliore === null || count($trovate) > count($migliore['trovate'])) {
                $migliore = ['riga' => $i, 'trovate' => array_values($trovate)];
            }
        }

        return $migliore;
    }

    /**
     * Mappa nome di colonna → indice, per la riga di intestazione trovata.
     *
     * Le colonne senza nome **non spariscono**: prendono la chiave `col_<indice>`. Nel riparto
     * consuntivo sono unità, ruolo e denominazione, cioè le tre colonne più importanti del
     * file: perderle perché non hanno un titolo sarebbe il difetto più costoso possibile.
     *
     * @return array<string, int>
     */
    public function mappaColonne(Foglio $foglio, int $rigaHeader): array
    {
        $mappa = [];

        foreach ($foglio->riga($rigaHeader) as $indice => $cella) {
            $nome = self::normalizza((string) ($cella ?? ''));

            if ($nome === '') {
                $mappa['col_'.$indice] = $indice;

                continue;
            }

            // Etichette ripetute (`mill.` nel riparto): la prima tiene il nome pulito, le
            // successive vengono numerate. Sovrascriverle silenziosamente perderebbe colonne.
            if (isset($mappa[$nome])) {
                $n = 2;
                while (isset($mappa[$nome.'_'.$n])) {
                    $n++;
                }
                $mappa[$nome.'_'.$n] = $indice;

                continue;
            }

            $mappa[$nome] = $indice;
        }

        return $mappa;
    }

    /**
     * Minuscolo, senza accenti, senza punteggiatura di coda, spazi normalizzati.
     *
     * Serve perché le etichette reali arrivano in tutte le forme: `Protoc.`, `Dt.compet.`,
     * `Gruppo unità`, `AMMINISTRAZIONE`, `Sub. cat.` — e in almeno due file gli accenti sono
     * già corrotti in `?` nel file di origine (trappola 13 del §17.8), quindi il confronto non
     * può dipendere da loro.
     */
    public static function normalizza(string $valore): string
    {
        $v = trim($valore);
        if ($v === '') {
            return '';
        }

        $v = mb_strtolower($v, 'UTF-8');

        $v = strtr($v, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', '’' => "'", '?' => '',
        ]);

        // Punteggiatura e simboli via, spazi compattati: `Dt.compet.` e `dt compet` devono
        // essere la stessa cosa.
        $v = preg_replace('/[^a-z0-9]+/', ' ', $v) ?? '';

        return trim(preg_replace('/\s+/', ' ', $v) ?? '');
    }
}
