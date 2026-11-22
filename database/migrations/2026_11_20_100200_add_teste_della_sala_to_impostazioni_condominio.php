<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): come contano le teste nel caso misto, una scelta del condominio. Rossi ha l'Int. 3 da solo e l'Int. 5 a metà con
 * Bianchi: `due_teste` (di serie) conta Rossi per l'Int. 3 e l'Int. 5 a parte, con il suo rappresentante, perché le comunioni nate da titoli diversi
 * restano distinte anche nell'assemblea; `una_testa` li conta una testa sola, con i millesimi delle due unità, per i condomìni con una regola loro.
 *
 * Rieseguibile: una guardia per la colonna, all'andata e al ritorno. Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('impostazioni_condominio') && ! Schema::hasColumn('impostazioni_condominio', 'assemblee_teste')) {
            Schema::table('impostazioni_condominio', fn (Blueprint $table) => $table->string('assemblee_teste', 20)->default('due_teste')
                ->comment('Le teste nel caso misto: due_teste (di serie) o una_testa.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('impostazioni_condominio') && Schema::hasColumn('impostazioni_condominio', 'assemblee_teste')) {
            Schema::table('impostazioni_condominio', fn (Blueprint $table) => $table->dropColumn('assemblee_teste'));
        }
    }
};
