<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): le note del presidente su un voto, che vanno nel verbale.
 *
 * - `nota`: la nota sul voto del punto, per esempio quando si vota senza che l'assemblea risulti costituita («decide il presidente»).
 * - `nota_non_votato`: la nota su chi, fra gli intervenuti, non ha votato (le persone senza voto restano contate a parte, con i nomi).
 *
 * Rieseguibile: una guardia per colonna (su MySQL ogni colonna è uno statement a sé, e il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di
 * `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo la prima colonna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assemblee_votazioni')) {
            return;
        }
        $colonne = [
            'nota' => fn (Blueprint $t) => $t->text('nota')->nullable()->comment('La nota del presidente sul voto del punto: va nel verbale.'),
            'nota_non_votato' => fn (Blueprint $t) => $t->text('nota_non_votato')->nullable()->comment('La nota del presidente su chi non ha votato: va nel verbale.'),
        ];
        foreach ($colonne as $colonna => $aggiungi) {
            if (! Schema::hasColumn('assemblee_votazioni', $colonna)) {
                Schema::table('assemblee_votazioni', $aggiungi);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('assemblee_votazioni')) {
            return;
        }
        foreach (['nota_non_votato', 'nota'] as $colonna) {
            if (Schema::hasColumn('assemblee_votazioni', $colonna)) {
                Schema::table('assemblee_votazioni', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }
    }
};
