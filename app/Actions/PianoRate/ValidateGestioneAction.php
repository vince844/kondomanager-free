<?php

namespace App\Actions\PianoRate;

use App\Models\Gestione;

class ValidateGestioneAction
{
    /**
     * Validate a Gestione record ensuring required relations exist.
     *
     * @throws \RuntimeException
     */
    public function execute(int $gestioneId): Gestione
    {
        $gestione = Gestione::with(['pianoConto.conti', 'esercizi'])
            ->findOrFail($gestioneId);

        if (!$gestione->pianoConto) {
            throw new \RuntimeException("La gestione non ha un piano conti associato.");
        }

        if (!$gestione->data_inizio) {
            throw new \RuntimeException("La gestione non ha una data di inizio definita.");
        }

        return $gestione;
    }
}
