<?php

namespace App\Traits;

use App\Models\Condominio;


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
        return $condominio->esercizi()
            ->where('stato', 'aperto')
            ->first();
    }
}