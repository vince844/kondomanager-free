<?php

namespace App\Services\Import\Parser;

use App\Services\Import\Canonical\CanonicalImmobile;
use App\Services\Import\Canonical\CanonicalTabella;
use App\Services\Import\EsitoVerifica;
use App\Services\Import\Foglio;
use App\Services\Import\HeaderDetector;
use App\Services\Import\Rilievo;

/**
 * Il report compatto `anagrafica_millesimi` → unità e **tabelle millesimali** (livelli 5 e 7).
 *
 * Le prime quattro colonne sono fisse — palazzina, gruppo, progressivo, proprietario — e
 * **tutte le altre sono tabelle**, una per colonna. Il numero e i nomi cambiano da condominio a
 * condominio, perché li decide l'amministratore: un parser che si aspettasse un elenco noto non
 * leggerebbe il secondo file che gli capita.
 *
 * ## Cosa questo report non può dare
 *
 * Niente codici fiscali, niente ruoli, niente indirizzi. I nomi ci sono, ma `anagrafiche` ha
 * `indirizzo` NOT NULL: **da qui i soggetti non si possono creare**. È il motivo per cui il
 * §19 consiglia di esportare anche l'elenco unità, e per cui questo report resta il ripiego.
 * Il parser lo dice invece di lasciarlo scoprire.
 */
final class AnagraficaMillesimiParser
{
    /** Le colonne che non sono tabelle. Tutto il resto lo è. */
    private const COLONNE_FISSE = ['palazzina', 'gruppo', 'progressivo', 'proprietario'];

    /**
     * @return array{
     *     immobili: array<string, CanonicalImmobile>,
     *     tabelle: array<string, CanonicalTabella>,
     *     esito: EsitoVerifica
     * }
     */
    public function estrai(Foglio $foglio, int $rigaIntestazione): array
    {
        $mappa = (new HeaderDetector)->mappaColonne($foglio, $rigaIntestazione);
        $etichette = $foglio->riga($rigaIntestazione);

        $colonneTabella = [];
        foreach ($mappa as $normalizzata => $indice) {
            if (in_array($normalizzata, self::COLONNE_FISSE, true) || str_starts_with($normalizzata, 'col_')) {
                continue;
            }

            $colonneTabella[$indice] = trim((string) ($etichette[$indice] ?? $normalizzata));
        }

        $immobili = [];
        $quote = [];
        $rilievi = [];
        $righeDati = 0;

        for ($i = $rigaIntestazione + 1; $i < $foglio->numeroRighe(); $i++) {
            $riga = $foglio->riga($i);

            if ($this->vuota($riga)) {
                continue;
            }

            $righeDati++;
            $rigaUtente = Foglio::rigaUtente($i);

            $chiave = $this->chiaveImmobile($riga, $mappa);

            if ($chiave === null) {
                $rilievi[] = Rilievo::errore(
                    'unita.chiave_incompleta',
                    'Mancano palazzina, gruppo o progressivo: non so a quale unità appartiene questa riga.',
                    'Sono le prime tre colonne del file.',
                    $rigaUtente,
                );

                continue;
            }

            [$palazzina, $gruppo, $progressivo] = explode('-', $chiave, 3);

            $immobili[$chiave] ??= new CanonicalImmobile(
                palazzina: $palazzina,
                gruppo: $gruppo,
                progressivo: $progressivo,
                interno: $progressivo,
            );

            foreach ($colonneTabella as $indice => $nomeTabella) {
                $grezzo = trim((string) ($riga[$indice] ?? ''));

                // **La cella vuota non è uno zero.** È il cuore della tabella parziale: l'unità
                // non partecipa, e non deve comparire fra le quote.
                if ($grezzo === '') {
                    continue;
                }

                $valore = $this->numero($grezzo);

                if ($valore === null) {
                    $rilievi[] = Rilievo::errore(
                        'tabella.valore_non_numerico',
                        sprintf('Nella tabella «%s» il valore «%s» non è un numero.', $nomeTabella, $grezzo),
                        'Controlla la cella nel file: i millesimi devono essere numeri.',
                        $rigaUtente,
                        $nomeTabella,
                    );

                    continue;
                }

                /*
                 * ⚠️ **Il negativo si rifiuta anche qui, non solo sulla porta HTTP.**
                 *
                 * La beta.61 ha vietato i millesimi negativi in `UpdateQuoteRequest`, ma quella
                 * guardia sta su **una porta sola**: da qui il valore entra senza passarci. Un
                 * `-900` in una cella — un refuso, o una formattazione contabile letta male, che
                 * sui file veri capita — non avvisa (non è `null`), non partecipa (è ≤ 0) e però
                 * **entra nel divisore** `sum('valore')`, **rimpicciolendolo**. Ogni quota della
                 * tabella diventa allora una frazione più grande, la tabella pesa **più** del suo
                 * coefficiente rispetto alle altre collegate allo stesso capitolo, e a pagare di
                 * più sono **gli altri partecipanti di quella stessa tabella**: denaro che cambia
                 * persona senza che niente lo segnali.
                 *
                 * Misurato il 21/08/2026: due tabelle al 50/50 su una spesa da € 1.000,00, un solo
                 * `-900` in una — chi doveva pagare € 333,33 riceve **€ 484,85**, e l'unità con il
                 * valore negativo scende a € 30,30.
                 *
                 * Si rifiuta invece di raddrizzarlo: un millesimo negativo non ha una lettura
                 * corretta ovvia — non è né «zero» né «900» — e indovinarla vorrebbe dire
                 * scrivere in archivio un numero che nel file non c'era.
                 */
                if ($valore < 0.0) {
                    $rilievi[] = Rilievo::errore(
                        'tabella.valore_negativo',
                        sprintf('Nella tabella «%s» il valore «%s» è negativo.', $nomeTabella, $grezzo),
                        'Un millesimo non può essere negativo: correggi la cella nel file. '
                        .'Se quell\'unità non partecipa alla tabella, lascia la cella vuota o scrivi zero.',
                        $rigaUtente,
                        $nomeTabella,
                    );

                    continue;
                }

                $quote[$nomeTabella][$chiave] = $valore;
            }
        }

        $tabelle = [];

        foreach ($quote as $nome => $valori) {
            $tabelle[$nome] = new CanonicalTabella($nome, $valori);
        }

        $rilievi = [...$rilievi, ...$this->rilieviSulleTabelle($tabelle, count($immobili))];

        if ($righeDati > 0) {
            $rilievi[] = Rilievo::avviso(
                'soggetti.non_ricavabili_da_questo_report',
                'Questo report non contiene codici fiscali, ruoli né indirizzi delle persone.',
                'Le unità e le tabelle entrano; le persone no, perché Kondomanager richiede un '
                .'indirizzo. Esporta anche l\'«Elenco unità» di Danea per portarle.',
            );
        }

        return [
            'immobili' => $immobili,
            'tabelle' => $tabelle,
            'esito' => new EsitoVerifica($righeDati, $rilievi),
        ];
    }

    /**
     * Le due forme che a colpo d'occhio sembrano errori e non lo sono.
     *
     * Vanno dette **prima** che l'amministratore le scopra guardando i numeri, o passerà mezza
     * giornata a cercare un difetto che non c'è.
     *
     * @param  array<string, CanonicalTabella>  $tabelle
     * @return list<Rilievo>
     */
    private function rilieviSulleTabelle(array $tabelle, int $totaleUnita): array
    {
        $rilievi = [];

        foreach ($tabelle as $tabella) {
            if ($tabella->partecipanti() < $totaleUnita) {
                $rilievi[] = Rilievo::avviso(
                    'tabella.parziale',
                    sprintf(
                        'La tabella «%s» riguarda %d unità su %d, e i suoi valori sommano a %s.',
                        $tabella->nome,
                        $tabella->partecipanti(),
                        $totaleUnita,
                        rtrim(rtrim(number_format($tabella->somma(), 4, ',', '.'), '0'), ','),
                    ),
                    'Non è un errore: è una tabella parziale — come l\'impianto che serve solo '
                    .'una parte del condominio. Le unità senza valore semplicemente non vi '
                    .'partecipano.',
                );
            }

            if ($tabella->isPartiUguali()) {
                $rilievi[] = Rilievo::avviso(
                    'tabella.parti_uguali',
                    sprintf('La tabella «%s» ha lo stesso valore per ogni unità.', $tabella->nome),
                    'È una ripartizione a parti uguali, non un errore di compilazione: il riparto '
                    .'divide in parti identiche fra i partecipanti.',
                );
            }
        }

        return $rilievi;
    }

    /**
     * @param  list<mixed>  $riga
     * @param  array<string, int>  $mappa
     */
    private function chiaveImmobile(array $riga, array $mappa): ?string
    {
        $pezzi = [];

        foreach (['palazzina', 'gruppo', 'progressivo'] as $colonna) {
            $indice = $mappa[$colonna] ?? null;
            $valore = $indice === null ? '' : trim((string) ($riga[$indice] ?? ''));

            if ($valore === '') {
                return null;
            }

            $pezzi[] = $valore;
        }

        return implode('-', $pezzi);
    }

    /**
     * I millesimi arrivano col punto in un file e con la virgola in un altro (trappola 2):
     * `53.14` e `-827,88` convivono nello stesso corpus.
     */
    private function numero(string $grezzo): ?float
    {
        // Stesso doppio percorso di MoneyHelper::toCents(), qui applicato a un valore che non
        // è denaro ma segue la stessa doppia notazione: se è già un numero pulito si accetta
        // così com'è; solo altrimenti si tenta la forma testuale italiana, punto delle
        // migliaia tolto PRIMA della virgola decimale.
        //
        // Convertirli nell'ordine sbagliato (prima la virgola, come qui prima) trasformava
        // «1.000,0000» in «1.000.0000» — due punti, is_numeric() lo scartava — e un'unità con
        // l'intera proprietà (1.000 millesimi, scritta come testo) faceva sparire l'intera
        // tabella con l'errore «non è un numero».
        if (is_numeric($grezzo)) {
            return (float) $grezzo;
        }

        $normalizzato = str_replace(',', '.', str_replace('.', '', $grezzo));

        return is_numeric($normalizzato) ? (float) $normalizzato : null;
    }

    /**
     * @param  list<mixed>  $riga
     */
    private function vuota(array $riga): bool
    {
        foreach ($riga as $cella) {
            if (trim((string) ($cella ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }
}
