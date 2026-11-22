<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Permission;
use App\Models\User;
use App\Services\RegistroModifiche\AccessoAlRegistro;

/**
 * Chi vede e chi gestisce le assemblee (Coda 679, §7 del progetto della tappa 2; decisione 562), in un posto solo per tutte le rotte: i controller chiedono
 * `Gate::allowIf(AccessoAlleAssemblee::vede($utente))`, come il registro.
 *
 * - **I ruoli `utente` e `fornitore` mai**, qualunque permesso abbiano (P18 della tappa 1): un permesso dato a mano a quei ruoli non apre niente.
 * - **«Vede le assemblee»**: l'archivio, la pagina dell'assemblea, «Scegli la delibera» nelle pagine, la card della dashboard del condominio.
 * - **«Gestisce le assemblee»**: preparare, l'ordine del giorno, i memo, registrare a mano una delibera o una riunione di carta, collegare le delibere.
 *   Chi gestisce vede.
 * - **«Registra il verbale sottoscritto»**: caricare il verbale firmato (decisione 492 (4)); di serie solo l'amministratore (decisione 562).
 *
 * Il collaboratore oggi vede tutti i condomini, quindi tutte le assemblee: quando verrà la limitazione per condominio, le assemblee la seguiranno da qui.
 */
final class AccessoAlleAssemblee
{
    public static function vede(?User $utente): bool
    {
        return $utente !== null && ! AccessoAlRegistro::eDelPortale($utente)
            && ($utente->checkPermissionTo(Permission::VIEW_ASSEMBLEE->value) || $utente->checkPermissionTo(Permission::MANAGE_ASSEMBLEE->value));
    }

    public static function gestisce(?User $utente): bool
    {
        return $utente !== null && ! AccessoAlRegistro::eDelPortale($utente) && $utente->checkPermissionTo(Permission::MANAGE_ASSEMBLEE->value);
    }

    public static function registraIlVerbale(?User $utente): bool
    {
        return $utente !== null && ! AccessoAlRegistro::eDelPortale($utente)
            && $utente->checkPermissionTo(Permission::REGISTER_VERBALE_SOTTOSCRITTO->value);
    }
}
