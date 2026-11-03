<?php

namespace App\Services\Riparto;

use App\Enums\NaturaGestione;
use App\Support\InsiemePeriodi;
use App\Support\PeriodoCompetenza;
use DateTimeInterface;

/**
 * Quando matura una spesa — la cascata di D3, come funzione pura.
 *
 * D3 del progetto `docs/subentro_e_competenza_temporale.md` (riscritta il 16/09/2026, decisioni 11, 12,
 * 19 e 20): **la regola è doppia** e si aggancia alla natura della gestione ({@see NaturaGestione}).
 *
 * - **Straordinaria** ({@see perStraordinaria()}): competenza dichiarata sulla fattura o sulla copertura
 *   → data della delibera attuativa, come periodo lungo un giorno (D2) → **stop**. L'obbligazione
 *   sorge con la delibera che approva opere e prezzo (Cass. 24654/2010, 25839/2019, 11199/2021,
 *   24236/2025): se la data non c'è il motore **non scende** al periodo della gestione, che darebbe un
 *   pro rata per giorni — l'opposto della funzione a gradino. Risponde `richiedeDelibera` e chi lo
 *   chiama si ferma e chiede (decisione 12: «non si tira a indovinare»).
 * - **Ordinaria** ({@see perOrdinaria()}): tratti di competenza del capitolo (decisione 20) → periodo
 *   della gestione, se chiusa → periodo dell'esercizio. L'approvazione del preventivo **non è
 *   costitutiva** (Cass. 24654/2010, 24069/2022): la delibera non è un gradino, e la firma non la
 *   accetta. Nel **piano da capitoli** la competenza dichiarata su una singola fattura del capitolo **non guida le
 *   rate** (decisione 19): il motore ordinario ripartisce il conto, non la fattura. Nel **piano da fatture** invece
 *   l'unità è la fattura, e lì la competenza dichiarata prevale anche sull'ordinaria ({@see perFattura()}, decisione 26).
 *
 * Riceve date e periodi, non modelli, così i test non hanno bisogno del database. Chi ha i modelli li
 * traduce e chiama: `CompetenzaDelPiano::perPiano()` per la base del piano (generazione, anteprima,
 * conguaglio del passaggio) e `CalcoloQuoteService::calcolaDaFattureStraordinarie()` fattura per fattura.
 */
class RisolutoreCompetenza
{
    /**
     * @param DateTimeInterface|string|null $competenzaDal dichiarata sulla fattura o sulla copertura
     * @param DateTimeInterface|string|null $competenzaAl  idem; conta solo se ci sono entrambe
     * @param DateTimeInterface|string|null $dataDelibera  `piani_rate.data_delibera_assemblea`
     */
    public function perStraordinaria(
        DateTimeInterface|string|null $competenzaDal,
        DateTimeInterface|string|null $competenzaAl,
        DateTimeInterface|string|null $dataDelibera,
        bool $divergenzaTipoPiano = false,
    ): EsitoCompetenza {
        $natura = NaturaGestione::Straordinaria;

        if ($dichiarata = $this->dichiarata($competenzaDal, $competenzaAl)) {
            return new EsitoCompetenza($natura, InsiemePeriodi::uno($dichiarata), GradinoCompetenza::Dichiarata, divergenzaTipoPiano: $divergenzaTipoPiano);
        }

        if ($dataDelibera !== null && $dataDelibera !== '') {
            return new EsitoCompetenza(
                $natura,
                InsiemePeriodi::uno(PeriodoCompetenza::puntuale($this->data($dataDelibera))),
                GradinoCompetenza::Delibera,
                divergenzaTipoPiano: $divergenzaTipoPiano,
            );
        }

        // Decisione 12: sotto la delibera non si scende. Niente periodo, niente gradino.
        return new EsitoCompetenza($natura, null, null, richiedeDelibera: true, divergenzaTipoPiano: $divergenzaTipoPiano);
    }

    /**
     * @param InsiemePeriodi|null     $capitolo          i tratti di `competenze_capitolo`; `null` = nessuna riga
     * @param PeriodoCompetenza|null  $gestione          `PeriodoCompetenza::daGestione()`; `null` se la gestione è aperta
     * @param PeriodoCompetenza       $esercizio         il fondo della cascata: c'è sempre
     * @param PeriodoCompetenza|null  $competenzaFattura accettata e ignorata, di proposito (decisione 19)
     */
    public function perOrdinaria(
        ?InsiemePeriodi $capitolo,
        ?PeriodoCompetenza $gestione,
        PeriodoCompetenza $esercizio,
        ?PeriodoCompetenza $competenzaFattura = null,
        bool $divergenzaTipoPiano = false,
    ): EsitoCompetenza {
        $natura = NaturaGestione::Ordinaria;
        $ignorata = $competenzaFattura !== null;

        if ($capitolo !== null) {
            return new EsitoCompetenza($natura, $capitolo, GradinoCompetenza::Capitolo, divergenzaTipoPiano: $divergenzaTipoPiano, competenzaFatturaIgnorata: $ignorata);
        }

        if ($gestione !== null) {
            return new EsitoCompetenza($natura, InsiemePeriodi::uno($gestione), GradinoCompetenza::Gestione, divergenzaTipoPiano: $divergenzaTipoPiano, competenzaFatturaIgnorata: $ignorata);
        }

        return new EsitoCompetenza($natura, InsiemePeriodi::uno($esercizio), GradinoCompetenza::Esercizio, divergenzaTipoPiano: $divergenzaTipoPiano, competenzaFatturaIgnorata: $ignorata);
    }

    /**
     * Decisione 26 (1.11.0-beta.35): nel **piano da fatture** la competenza dichiarata sulla fattura (o sulla copertura)
     * prevale qualunque sia la natura della gestione — è la decisione 11 («il primo gradino è comune e prevale sempre»)
     * applicata anche all'ordinaria, dove l'unità di riparto è la fattura e non il conto. Senza competenza dichiarata
     * resta la base del piano (sull'ordinaria gestione ∩ esercizio). La straordinaria passa da {@see perStraordinaria()},
     * che mette già la dichiarata al primo gradino e sotto la delibera si ferma (decisione 12).
     *
     * @param DateTimeInterface|string|null $competenzaDal dichiarata sulla fattura
     * @param DateTimeInterface|string|null $competenzaAl  idem; conta solo se ci sono entrambe
     */
    public function perFattura(
        DateTimeInterface|string|null $competenzaDal,
        DateTimeInterface|string|null $competenzaAl,
        EsitoCompetenza $base,
    ): EsitoCompetenza {
        if ($dichiarata = $this->dichiarata($competenzaDal, $competenzaAl)) {
            return new EsitoCompetenza($base->natura, InsiemePeriodi::uno($dichiarata), GradinoCompetenza::Dichiarata, divergenzaTipoPiano: $base->divergenzaTipoPiano);
        }

        return $base;
    }

    /** Dichiarata solo se ci sono entrambi gli estremi: una metà non è una competenza. */
    private function dichiarata(DateTimeInterface|string|null $dal, DateTimeInterface|string|null $al): ?PeriodoCompetenza
    {
        if ($dal === null || $dal === '' || $al === null || $al === '') {
            return null;
        }

        return new PeriodoCompetenza($this->data($dal), $this->data($al));
    }

    private function data(DateTimeInterface|string $d): string
    {
        return $d instanceof DateTimeInterface ? $d->format('Y-m-d') : $d;
    }
}
