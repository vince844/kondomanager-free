<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

/**
 * Chi legge il registro delle modifiche (§15 e P18 del progetto della tappa 1; decisione 518 (3)), in un posto solo per tutte le rotte del registro: le
 * pagine, la «Storia», «Ripristina» e i casi speciali chiedono la stessa cosa.
 *
 * - **I ruoli `utente` e `fornitore` mai**, qualunque permesso abbiano: un permesso dato a mano a quei ruoli non apre niente (P18). Lo si controlla prima
 *   del permesso, così un condòmino a cui qualcuno ha dato per errore «Vede il registro delle modifiche» riceve lo stesso 403.
 * - **«Vede il registro delle modifiche»**: la pagina del condominio, quella dello studio, la stampa, la verifica dell'integrità (di serie l'amministratore).
 * - **«Vede la storia delle schede»**: il cassetto «Storia» (di serie l'amministratore e il collaboratore).
 * - **Le schede dello studio** (utenti, ruoli, impostazioni dello studio) e le righe degli oggetti eliminati: solo il ruolo amministratore (P18).
 *
 * Oggi un collaboratore vede tutti i condomini (non c'è una limitazione per condominio, §1 del progetto), quindi vede la storia delle schede di tutti:
 * quando arriverà la limitazione per condominio, la storia la seguirà da qui.
 */
final class AccessoAlRegistro
{
    public static function eDelPortale(User $utente): bool
    {
        return $utente->hasAnyRole([Role::UTENTE->value, Role::FORNITORE->value]);
    }

    public static function eAmministratore(User $utente): bool
    {
        return ! self::eDelPortale($utente) && $utente->hasRole(Role::AMMINISTRATORE->value);
    }

    public static function vedeLaStoria(User $utente): bool
    {
        return ! self::eDelPortale($utente) && $utente->checkPermissionTo(Permission::VIEW_STORIA_SCHEDE->value);
    }

    public static function vedeIlRegistro(User $utente): bool
    {
        return ! self::eDelPortale($utente) && $utente->checkPermissionTo(Permission::VIEW_REGISTRO_MODIFICHE->value);
    }
}
