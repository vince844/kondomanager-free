<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (decisione 664 (6), rilievo C53): `assemblee_presenze.insieme`, gli altri contitolari della testa entrati in sala con chi la rappresenta
 * (art. 67 c. 2 disp. att.: l'unità in comproprietà ha un solo rappresentante, che vota; gli altri sono presenti senza voto). Una lista `{anagrafica_id,
 * nome}`; la scrive il gesto `entra` con `con`, e la riga si chiude con l'uscita di chi rappresenta.
 *
 * Rieseguibile: una colonna, una guardia (su MySQL il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assemblee_presenze') && ! Schema::hasColumn('assemblee_presenze', 'insieme')) {
            Schema::table('assemblee_presenze', fn (Blueprint $t) => $t->json('insieme')->nullable()
                ->comment('Gli altri contitolari della testa entrati con chi la rappresenta, presenti senza voto: [{anagrafica_id, nome}].'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('assemblee_presenze') && Schema::hasColumn('assemblee_presenze', 'insieme')) {
            Schema::table('assemblee_presenze', fn (Blueprint $t) => $t->dropColumn('insieme'));
        }
    }
};
