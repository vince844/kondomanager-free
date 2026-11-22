<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §0.4 e §1.2 del progetto della tappa 2): come si convoca una persona, con uno dei mezzi dell'art. 66 c. 3 disp. att. (PEC,
 * raccomandata, a mano, fax: l'email semplice non vale, Cass. 16399/2025), e il giorno in cui la persona ha comunicato la sua PEC. Nascono vuote: vuoto, il
 * programma **propone** il mezzo dai dati senza scriverlo. La storia sta nel registro delle modifiche (l'anagrafica ha il tratto).
 *
 * Rieseguibile: una guardia per colonna. Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anagrafiche')) {
            return;
        }
        if (! Schema::hasColumn('anagrafiche', 'mezzo_convocazione')) {
            Schema::table('anagrafiche', function (Blueprint $t) {
                $t->string('mezzo_convocazione', 20)->nullable()->comment('pec, raccomandata, a_mano o fax; vuoto vuol dire la proposta del programma.');
            });
        }
        if (! Schema::hasColumn('anagrafiche', 'pec_comunicata_il')) {
            Schema::table('anagrafiche', function (Blueprint $t) {
                $t->date('pec_comunicata_il')->nullable()->comment('Il giorno in cui la persona ha comunicato la sua PEC.');
            });
        }
    }

    public function down(): void
    {
        foreach (['pec_comunicata_il', 'mezzo_convocazione'] as $colonna) {
            if (Schema::hasTable('anagrafiche') && Schema::hasColumn('anagrafiche', $colonna)) {
                Schema::table('anagrafiche', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }
    }
};
