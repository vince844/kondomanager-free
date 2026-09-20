<?php

/**
 * B2, S1 — `PeriodoCompetenza::daEsercizio()` e `daGestione()`.
 *
 * Sta fra i Feature e non fra gli Unit perché costruisce due modelli Eloquent (i cast `date` hanno
 * bisogno del container). Nessuna riga scritta a database.
 */

use App\Support\PeriodoCompetenza;

it('PeriodoCompetenza::daEsercizio() e daGestione() leggono le date del modello e rispondono null se una manca', function () {
    $esercizio = new \App\Models\Esercizio(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $gestioneChiusa = new \App\Models\Gestione(['data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $gestioneAperta = new \App\Models\Gestione(['data_inizio' => '2026-01-01', 'data_fine' => null]);

    expect(PeriodoCompetenza::daEsercizio($esercizio)?->giorni())->toBe(365)
        ->and(PeriodoCompetenza::daGestione($gestioneChiusa)?->toArray())->toBe(['dal' => '2026-01-01', 'al' => '2026-12-31'])
        // Una gestione senza data di fine attraversa più esercizi: non è un periodo, e la cascata
        // scende all'esercizio (§6.3 del progetto: «gestioni … attraversa più esercizi»).
        ->and(PeriodoCompetenza::daGestione($gestioneAperta))->toBeNull();
});
