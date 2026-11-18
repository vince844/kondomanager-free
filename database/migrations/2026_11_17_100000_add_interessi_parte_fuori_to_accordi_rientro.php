<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisione 450: con un accordo di rientro su una parte della rata, sulla parte fuori dall'accordo gli interessi continuano (di serie) o restano sospesi
 * finché l'accordo regge, a scelta dell'amministratore, accordo per accordo. La scelta sta sull'accordo (`interessi_parte_fuori`: `continuano` o
 * `sospesi`); gli accordi già registrati prendono il di serie, `continuano`, dal valore della colonna.
 *
 * Rieseguibile: una colonna sola, con la sua guardia. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accordi_rientro', 'interessi_parte_fuori')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->string('interessi_parte_fuori', 20)->default('continuano')->after('decadenza_giorni')
                ->comment('Sulla parte della rata fuori dall\'accordo: «continuano» (di serie) o «sospesi» finché l\'accordo regge (decisione 450).'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('accordi_rientro', 'interessi_parte_fuori')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->dropColumn('interessi_parte_fuori'));
        }
    }
};
