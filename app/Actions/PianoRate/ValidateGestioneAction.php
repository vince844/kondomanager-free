<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
