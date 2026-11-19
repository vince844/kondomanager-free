<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisione 483: sul passaggio, la scelta della successione per gli interessi del defunto dopo il decesso. `al_decesso` («si fermano al decesso»: nella
 * proposta degli interessi del defunto, sulle rate delle unità del passaggio, l'ultimo giorno contato è il giorno prima del decesso) o `continuano`
 * («continuano a suo nome», come prima). Sul passaggio dell'unità e su quelli delle sue pertinenze; vuota per gli altri passaggi, per le successioni
 * annullate e per quelle registrate prima che la migrazione dei dati dopo questa le riallinei.
 *
 * Rieseguibile: una guardia per la colonna, all'andata e al ritorno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }
        if (! Schema::hasColumn('subentri', 'interessi_del_defunto')) {
            Schema::table('subentri', fn (Blueprint $table) => $table->string('interessi_del_defunto', 20)->nullable()->after('registro')
                ->comment('Successione: gli interessi del defunto dopo il decesso, «al_decesso» o «continuano». Vuota per gli altri passaggi e per gli annullati.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('subentri') && Schema::hasColumn('subentri', 'interessi_del_defunto')) {
            Schema::table('subentri', fn (Blueprint $table) => $table->dropColumn('interessi_del_defunto'));
        }
    }
};
