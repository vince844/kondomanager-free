<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Due colonne sull'accordo di rientro.
 *
 * - `registro`: le scelte cambiate dopo la registrazione, una riga per cambio, con chi, quando, da che cosa a che cosa. La prima è la scelta sulla parte
 *   della rata fuori dall'accordo (decisione 450), che si cambia dalla scheda dell'accordo.
 * - `chiusura_per_successione`: l'accordo chiuso da una successione con l'arretrato agli eredi, e che cosa vale per gli interessi del defunto: `in_regola`
 *   (di serie: l'accordo resta in regola fino al decesso) o `decadenza` (vale come una decadenza). Vuota per ogni altro accordo.
 *
 * Rieseguibile: una guardia per colonna, all'andata e al ritorno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accordi_rientro')) {
            return;
        }
        if (! Schema::hasColumn('accordi_rientro', 'registro')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->json('registro')->nullable()->after('interessi_parte_fuori')
                ->comment('Le scelte cambiate dopo la registrazione: chi, quando, da che cosa a che cosa.'));
        }
        if (! Schema::hasColumn('accordi_rientro', 'chiusura_per_successione')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->string('chiusura_per_successione', 20)->nullable()->after('decadenza_registrata_il')
                ->comment('Chiuso da una successione: «in_regola» fino al decesso (di serie) o «decadenza». Vuota per gli altri accordi.'));
        }
    }

    public function down(): void
    {
        foreach (['chiusura_per_successione', 'registro'] as $colonna) {
            if (Schema::hasColumn('accordi_rientro', $colonna)) {
                Schema::table('accordi_rientro', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
    }
};
