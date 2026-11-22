<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): `assemblee.foglio_stampato_il`, quando il foglio della sala è stato stampato l'ultima volta. Lo scrive la stampa del
 * foglio; il passo «In sala» dice «non ancora stampato», «stampato il …», o «ristampa il foglio» quando una delega cambia dopo la stampa.
 *
 * Rieseguibile: una colonna, una guardia (su MySQL il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assemblee') && ! Schema::hasColumn('assemblee', 'foglio_stampato_il')) {
            Schema::table('assemblee', fn (Blueprint $t) => $t->dateTime('foglio_stampato_il')->nullable()
                ->comment('Quando il foglio della sala è stato stampato l\'ultima volta.'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assemblee') && Schema::hasColumn('assemblee', 'foglio_stampato_il')) {
            Schema::table('assemblee', fn (Blueprint $t) => $t->dropColumn('foglio_stampato_il'));
        }
    }
};
