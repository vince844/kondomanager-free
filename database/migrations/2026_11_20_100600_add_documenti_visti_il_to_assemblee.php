<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, i passi dell'assemblea): `assemblee.documenti_visti_il`, quando l'amministratore ha guardato i documenti e ha premuto «Avanti:
 * Convocazione». Il passo «Documenti» è fatto da quel momento, non più da solo con le date; un'assemblea già convocata senza il giorno resta com'era.
 *
 * Rieseguibile: una colonna, una guardia (su MySQL il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assemblee') && ! Schema::hasColumn('assemblee', 'documenti_visti_il')) {
            Schema::table('assemblee', fn (Blueprint $t) => $t->dateTime('documenti_visti_il')->nullable()
                ->comment('Quando i documenti sono stati guardati, con «Avanti: Convocazione».'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assemblee') && Schema::hasColumn('assemblee', 'documenti_visti_il')) {
            Schema::table('assemblee', fn (Blueprint $t) => $t->dropColumn('documenti_visti_il'));
        }
    }
};
