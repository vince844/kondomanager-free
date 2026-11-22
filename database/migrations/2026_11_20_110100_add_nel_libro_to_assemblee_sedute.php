<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, il verbale): il giorno in cui la pagina dell'annotazione di una seduta deserta («Mancata costituzione») è incollata nel libro
 * dei verbali di carta. La segna l'amministratore: è un promemoria, non ferma niente. Il verbale ha la sua colonna (`assemblee_verbali.nel_libro_il`).
 *
 * Rieseguibile: una colonna, una guardia. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la toglie e la rimette.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assemblee_sedute') && ! Schema::hasColumn('assemblee_sedute', 'nel_libro_il')) {
            Schema::table('assemblee_sedute', fn (Blueprint $t) => $t->date('nel_libro_il')->nullable()
                ->comment('L\'annotazione della seduta deserta incollata nel libro di carta: un promemoria.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assemblee_sedute') && Schema::hasColumn('assemblee_sedute', 'nel_libro_il')) {
            Schema::table('assemblee_sedute', fn (Blueprint $t) => $t->dropColumn('nel_libro_il'));
        }
    }
};
