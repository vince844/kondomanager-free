<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (§7 del progetto del registro delle modifiche, P23): un promemoria che crea il programma da solo non ha una persona che lo crea. Fino
 * alla beta.12 `SyncF24WithPagamento` metteva l'autore `User::first()` e altri tre punti l'utente 1: **un falso utente**, che nell'agenda firmava come
 * amministratore ciò che nessuno aveva fatto. `eventi.created_by` diventa facoltativa; vuota vuol dire «Il programma». La chiave esterna resta.
 *
 * Rieseguibile: cambia la colonna solo se è ancora obbligatoria. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eventi') || ! Schema::hasColumn('eventi', 'created_by') || $this->facoltativa()) {
            return;
        }

        Schema::table('eventi', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable()->change());
    }

    public function down(): void
    {
        // Non si torna indietro: le righe del programma hanno l'autore vuoto, e una colonna obbligatoria le rifiuterebbe.
    }

    private function facoltativa(): bool
    {
        foreach (Schema::getColumns('eventi') as $colonna) {
            if ($colonna['name'] === 'created_by') {
                return (bool) $colonna['nullable'];
            }
        }

        return false;
    }
};
