<?php

namespace App\Services\Import;

/**
 * Guarda un foglio e dice cosa crede che sia, con quanta fiducia e cosa ha capito delle colonne.
 *
 * È il motore della schermata S2 (§14.1): l'amministratore trascina tutti i file insieme e non
 * deve ricordarsi né l'ordine né il nome giusto. Il punteggio che esce da qui è un
 * **suggerimento**, e l'ultima parola resta sua — per questo `import_files` ha la colonna
 * `tipo_forzato`.
 *
 * ## Come si compone il punteggio
 *
 * Due segnali indipendenti, perché nessuno dei due copre tutti i report:
 *
 * - **il titolo in testata** vale 40 su 100. Forte, ma i due report compatti non ce l'hanno;
 * - **le etichette di colonna** valgono i restanti 60, in proporzione a quante se ne trovano.
 *
 * Un report con banner riconosciuto e tutte le sue etichette arriva a 100; uno compatto con
 * tutte le etichette arriva a 60, che è oltre la soglia ma sotto la fiducia «alta» — ed è
 * corretto così: senza titolo, sappiamo davvero un po' meno.
 */
final class ReportRecognizer
{
    public function __construct(
        private readonly HeaderDetector $header = new HeaderDetector,
    ) {}

    /** Quanto pesa il titolo della stampa, quando il report ne ha uno. */
    private const PESO_BANNER = 40;

    /** Quanto pesano le etichette di colonna. */
    private const PESO_ETICHETTE = 60;

    /** Entro quante righe dall'alto si cerca il titolo della stampa. */
    private const RIGHE_BANNER = 3;

    public function riconosci(Foglio $foglio): DetectionResult
    {
        $migliore = $this->classifica($foglio)[0] ?? null;

        return $migliore?->riconosciuto()
            ? $migliore
            : DetectionResult::nonRiconosciuto($foglio->nome);
    }

    /**
     * Tutti i tipi che hanno ottenuto un punteggio, dal più probabile al meno.
     *
     * Serve a due cose, e la seconda è quella che conta di più:
     *
     * 1. `riconosci()` prende il primo;
     * 2. il pulsante **«Cambia»** della schermata S2 mostra le alternative **ordinate**, invece
     *    di un elenco cieco di tutti i report esistenti. Quando il sistema sbaglia, l'utente
     *    quasi sempre vuole il secondo classificato: farglielo cercare in una lista alfabetica
     *    sarebbe far pagare a lui il nostro errore.
     *
     * @return list<DetectionResult>
     */
    public function classifica(Foglio $foglio): array
    {
        $esiti = [];

        foreach (ReportType::cases() as $tipo) {
            $candidato = $this->valuta($foglio, $tipo);

            if ($candidato->tipo !== null && $candidato->confidenza > 0) {
                $esiti[] = $candidato;
            }
        }

        usort($esiti, fn (DetectionResult $a, DetectionResult $b) => $b->confidenza <=> $a->confidenza);

        return $esiti;
    }

    private function valuta(Foglio $foglio, ReportType $tipo): DetectionResult
    {
        $etichette = $tipo->etichette();

        $esito = $this->header->trova($foglio, $etichette, $tipo->quorum());

        // Senza intestazione non c'è report: il titolo da solo non basta. Un file può contenere
        // la parola «Movimenti» in una cella qualsiasi senza essere quel report.
        if ($esito === null) {
            return DetectionResult::nonRiconosciuto($foglio->nome);
        }

        $punteggio = (int) round(self::PESO_ETICHETTE * count($esito['trovate']) / count($etichette));

        if ($this->banner($foglio, $tipo)) {
            $punteggio += self::PESO_BANNER;
        }

        $mappa = $this->header->mappaColonne($foglio, $esito['riga']);

        // Il pannello mostra cosa **leggiamo**, non cosa serve a riconoscere: sono due elenchi
        // diversi, e confonderli fa dire all'utente che stiamo buttando via i suoi dati.
        [$riconosciute, $ignote] = $this->classificaColonne($foglio, $esito['riga'], $tipo->colonneLette());

        return new DetectionResult(
            tipo: $tipo,
            confidenza: min(100, $punteggio),
            rigaIntestazione: $esito['riga'],
            colonneRiconosciute: $riconosciute,
            colonneIgnote: $ignote,
            mappaColonne: $mappa,
            nomeFoglio: $foglio->nome,
        );
    }

    /**
     * Il titolo della stampa compare in una delle prime righe?
     *
     * Confronto su prefisso normalizzato: la testata reale del riparto è
     * «Consuntivo ripartizioni per unità / anagrafica», e un domani potrebbe portarsi dietro
     * l'esercizio o un suffisso. Pretendere l'uguaglianza esatta renderebbe il segnale fragile
     * proprio dove serve robustezza.
     */
    private function banner(Foglio $foglio, ReportType $tipo): bool
    {
        $titolo = $tipo->titoloBanner();

        if ($titolo === null) {
            return false;
        }

        $atteso = HeaderDetector::normalizza($titolo);

        $limite = min($foglio->numeroRighe(), self::RIGHE_BANNER);

        for ($i = 0; $i < $limite; $i++) {
            foreach ($foglio->riga($i) as $cella) {
                $valore = HeaderDetector::normalizza((string) ($cella ?? ''));

                if ($valore !== '' && str_starts_with($valore, $atteso)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Divide le colonne del file fra quelle che sappiamo leggere e quelle che ignoriamo.
     *
     * Le colonne **senza titolo** non finiscono fra le ignorate: sarebbero un falso allarme, e
     * per giunta su colonne che spesso sono le più importanti (nel riparto sono unità, ruolo e
     * denominazione). Compaiono come «colonna senza titolo», che è ciò che sono.
     *
     * @param  list<string>  $etichetteNote
     * @return array{0: list<string>, 1: list<string>}
     */
    private function classificaColonne(Foglio $foglio, int $rigaHeader, array $etichetteNote): array
    {
        // `['*']` significa «ogni colonna di questo report è un dato che leggo»: è il caso dei
        // report in cui le colonne le decide l'amministratore — i millesimi, dove ogni colonna
        // oltre le quattro fisse è una tabella — e di quelli che finiscono in archivio storico
        // così come sono.
        if (in_array('*', $etichetteNote, true)) {
            $tutte = [];

            foreach ($foglio->riga($rigaHeader) as $indice => $cella) {
                $originale = trim((string) ($cella ?? ''));
                $tutte[] = $originale === '' ? 'colonna senza titolo ('.($indice + 1).')' : $originale;
            }

            return [$tutte, []];
        }

        $note = [];
        foreach ($etichetteNote as $e) {
            $note[HeaderDetector::normalizza($e)] = true;
        }

        $riconosciute = [];
        $ignote = [];

        foreach ($foglio->riga($rigaHeader) as $indice => $cella) {
            $originale = trim((string) ($cella ?? ''));

            if ($originale === '') {
                $riconosciute[] = 'colonna senza titolo ('.($indice + 1).')';

                continue;
            }

            if (isset($note[HeaderDetector::normalizza($originale)])) {
                $riconosciute[] = $originale;
            } else {
                $ignote[] = $originale;
            }
        }

        return [$riconosciute, $ignote];
    }
}
