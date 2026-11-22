<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Models\User;

/**
 * Chi può fare che cosa sui casi speciali del registro delle modifiche (§15 del progetto, P18, decisione 550): oscurare, cambiare la durata e sospendere la
 * cancellazione sono **dell'amministratore**; leggere la durata e la sospensione è di chi vede il registro. La regola è una sola, in `AccessoAlRegistro`:
 * i ruoli `utente` e `fornitore` mai, qualunque permesso abbiano.
 */
final class AccessoAiCasiSpeciali
{
    public static function amministratore(?User $utente): bool
    {
        return $utente !== null && AccessoAlRegistro::eAmministratore($utente);
    }

    public static function puoLeggere(?User $utente): bool
    {
        return $utente !== null && AccessoAlRegistro::vedeIlRegistro($utente);
    }
}
