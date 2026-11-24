<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Traits;

use App\Models\Condominio;
use App\Models\Esercizio;


/**
 * Trait for retrieving current esercizio (fiscal period) for condominio
 *
 * Provides a standardized method to get the currently active and open
 * esercizio (fiscal period) for a given condominio. This is essential
 * for financial operations and period-based data management.
 *
 * @package App\Traits
 */
trait HasEsercizio
{
    /**
     * Get the current open esercizio for the condominio
     *
     * Retrieves the active fiscal period (esercizio) that is currently
     * in 'aperto' (open) state for the specified condominio. This is
     * typically used for financial transactions, reporting, and
     * period-based operations.
     *
     * Con due esercizi aperti è il **più recente** (Coda 646, decisione 707 (4)): vedi `esercizioApertoPiuRecente()`.
     *
     * @param \App\Models\Condominio $condominio The condominio to get the esercizio for
     * @return \App\Models\Esercizio|null The open esercizio or null if none found
     *
     * @example
     * // Usage in controller
     * $esercizio = $this->getEsercizioCorrente($condominio);
     *
     * if ($esercizio) {
     *     // Use the open esercizio for financial operations
     *     $transactions = $esercizio->transazioni()->get();
     * } else {
     *     // Handle case where no esercizio is open
     *     return back()->with($this->flashError('No open fiscal period'));
     * }
     *
     * @see \App\Models\Esercizio
     * @see \App\Models\Condominio::esercizi()
     */
    protected function getEsercizioCorrente(Condominio $condominio)
    {
        return self::esercizioApertoPiuRecente((int) $condominio->id);
    }

    /**
     * L'esercizio aperto del condominio: con più d'uno aperto, **il più recente** (la data di inizio più tarda; a pari data, l'ultimo
     * scritto). È la regola sola del prodotto per «l'esercizio aperto», dalla 1.12.0-beta.14 (Coda 646, decisione 707 (4)).
     *
     * Prima qui c'era `->first()` senza ordine, cioè il primo che il database restituiva: con il 2025 ancora aperto e il 2026
     * importato (l'esercizio importato nasce aperto) incassi, pagamenti, saldi ed emissioni andavano nel 2025, mentre l'importazione
     * (`ImportVerificaService::esercizioApertoDi()`) annunciava già il più recente. Le pagine non lasciano aprire un secondo esercizio
     * (`CreateEsercizioRequest`), l'importazione sì. Le rate di un piano non passano da qui: vanno nell'esercizio del piano
     * (`EmissioneRateController`). La regola «l'esercizio che contiene la data della registrazione» è del modulo di apertura e chiusura
     * dell'esercizio.
     *
     * Statico perché la usano anche i servizi che non hanno un condominio in mano (`FermataDopoScrivi`, l'importazione).
     */
    public static function esercizioApertoPiuRecente(int $condominioId): ?Esercizio
    {
        // La regola vive sul modello (`Esercizio::apertoPiuRecente()`), dove la trova anche chi non usa questo trait.
        return Esercizio::apertoPiuRecente($condominioId);
    }
}
