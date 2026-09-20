<?php

namespace App\Services\Riparto;

use App\Enums\NaturaGestione;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Support\PeriodoCompetenza;

/**
 * La competenza **di base** con cui un piano rate va ripartito: il punto d'ingresso della cascata D3
 * (decisioni 11, 12, 19 e 20 del progetto `docs/subentro_e_competenza_temporale.md`), scritto una
 * volta sola e usato da chi genera (`GeneratePianoRateAction`) e da chi stampa in anteprima
 * (`DettaglioRiparto`): due copie della stessa aritmetica sono il difetto che il progetto vieta.
 *
 * - **La natura la decide la gestione** (`gestioni.tipo`, decisione 11), non `piani_rate.tipo`, che
 *   dice solo da dove arrivano i numeri (capitoli o fatture). Se i due non concordano si va avanti con
 *   la natura e lo si dichiara (`divergenzaTipoPiano`).
 * - **Ordinaria**: periodo della gestione se chiusa, altrimenti dell'esercizio. I tratti per capitolo
 *   (`competenze_capitolo`) li applica il motore conto per conto: qui c'è la base su cui ripiega.
 *   Il periodo della gestione conta **solo per la parte che cade nell'esercizio del piano**: il prodotto
 *   riusa la stessa gestione ordinaria su più esercizi senza spostarne le date (`CondominioService::
 *   createDefaultGestione`, importatore), e un piano 2026 su una gestione datata 2025 non deve ereditare
 *   il 2025 — escluderebbe l'acquirente di marzo (verifica indipendente S4, critico). Intersezione vuota
 *   o date mancanti → periodo dell'esercizio.
 * - **Straordinaria**: la data della delibera come periodo di un giorno (D2). Senza, l'esito dice
 *   `richiedeDelibera` e chi genera **si ferma** (decisione 12); la competenza dichiarata sulla singola
 *   fattura la applica il motore, fattura per fattura.
 *
 * L'esercizio di riferimento è quello passato dal chiamante (i controller lo hanno dall'indirizzo);
 * senza, fra quelli della gestione, quello il cui periodo contiene la data di creazione del piano — un
 * piano nasce dentro il suo esercizio — poi il più recente fra gli attivi, poi il più recente. Una
 * gestione senza esercizio dà `null`: il calcolo resta atemporale, come nella beta.30, e nessuna riga
 * porta un periodo.
 */
class CompetenzaDelPiano
{
    public function __construct(private readonly RisolutoreCompetenza $risolutore = new RisolutoreCompetenza())
    {
    }

    public function perPiano(PianoRate $pianoRate, ?Esercizio $esercizio = null): ?EsitoCompetenza
    {
        $gestione = $pianoRate->gestione;
        if ($gestione === null) {
            return null;
        }

        $natura = NaturaGestione::daStringa($gestione->tipo);
        $divergenza = ($natura === NaturaGestione::Straordinaria) !== ($pianoRate->tipo === 'straordinario');

        if ($natura === NaturaGestione::Straordinaria) {
            return $this->risolutore->perStraordinaria(null, null, $pianoRate->data_delibera_assemblea, $divergenza);
        }

        $esercizio ??= $this->esercizioDelPiano($pianoRate);
        $periodoEsercizio = $esercizio ? PeriodoCompetenza::daEsercizio($esercizio) : null;
        if ($periodoEsercizio === null) {
            return null;
        }
        $periodoGestione = PeriodoCompetenza::daGestione($gestione)?->intersezione($periodoEsercizio);

        return $this->risolutore->perOrdinaria(null, $periodoGestione, $periodoEsercizio, null, $divergenza);
    }

    /**
     * L'esercizio del piano, senza l'indirizzo: **quello con cui è stato generato** (`piani_rate.esercizio_id`,
     * migrazione 9, scritto da `GeneratePianoRateAction`); per i piani vecchi senza colonna, la deduzione —
     * quello della gestione che contiene la data di creazione del piano, altrimenti il più recente fra gli
     * attivi (pivot `esercizio_gestione.attiva`), altrimenti il più recente. «Il primo» era sbagliato per
     * le gestioni riusate su più esercizi. `esercizioDedotto()` dice se si è dovuto dedurre.
     */
    public function esercizioDelPiano(PianoRate $pianoRate): ?Esercizio
    {
        if ($pianoRate->esercizio_id !== null) {
            $persistito = Esercizio::find($pianoRate->esercizio_id);
            if ($persistito !== null) {
                return $persistito;
            }
        }
        $gestione = $pianoRate->gestione;
        if ($gestione === null) {
            return null;
        }
        $esercizi = $gestione->esercizi()->orderByDesc('esercizi.data_inizio')->get();
        if ($esercizi->isEmpty()) {
            return null;
        }
        $creato = $pianoRate->created_at?->toDateString();
        if ($creato !== null) {
            $contiene = $esercizi->first(fn (Esercizio $e) => $e->data_inizio && $e->data_fine
                && $creato >= substr((string) $e->data_inizio, 0, 10) && $creato <= substr((string) $e->data_fine, 0, 10));
            if ($contiene) {
                return $contiene;
            }
        }

        return $esercizi->first(fn (Esercizio $e) => (bool) $e->pivot->attiva) ?? $esercizi->first();
    }

    /** Vero se il piano non ricorda il suo esercizio e la competenza è stata dedotta (piani generati prima della migrazione 9). */
    public function esercizioDedotto(PianoRate $pianoRate): bool
    {
        return $pianoRate->esercizio_id === null || Esercizio::find($pianoRate->esercizio_id) === null;
    }
}
