<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.14 (Coda 734, il condominio parziale, art. 1123 c. 3 c.c.): `tabelle.di_un_gruppo`, il segno della tabella di un gruppo. Su un punto votato
 * con una tabella segnata votano solo i condòmini delle unità con una quota maggiore di zero, e teste, millesimi e costituzione si contano fra loro.
 *
 * Il segno lo mette l'amministratore, mai il programma: falso di serie, e nessuna tabella lo prende da questa migrazione (niente si deduce dagli zeri).
 *
 * Rieseguibile: una colonna, una guardia (su MySQL il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tabelle') && ! Schema::hasColumn('tabelle', 'di_un_gruppo')) {
            Schema::table('tabelle', fn (Blueprint $t) => $t->boolean('di_un_gruppo')->default(false)
                ->comment('La tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue unità (art. 1123 c. 3 c.c.). Lo segna l\'amministratore.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tabelle') && Schema::hasColumn('tabelle', 'di_un_gruppo')) {
            Schema::table('tabelle', fn (Blueprint $t) => $t->dropColumn('di_un_gruppo'));
        }
    }
};
