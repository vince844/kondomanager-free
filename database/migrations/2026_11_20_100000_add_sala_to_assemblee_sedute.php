<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): una seduta si apre, si chiude, ha un esito, un presidente e un segretario, il controllo dell'art. 1136 c. 6 fatto
 * all'apertura e la fotografia della seduta (teste, unità, millesimi in centomillesimi, totali e nomi fermati all'apertura) con la sua impronta.
 *
 * - `aperta_il`, `chiusa_il`: l'ora del gesto, come l'ha segnata la sala (anche dal foglio della sala, con l'ora scritta a mano).
 * - `esito`: `deserta`, `tenuta`, `sospesa` (prosegue in una data dell'avviso, art. 66 c. 5 disp. att.), `rinviata`.
 * - `presidente`, `segretario`: `{anagrafica_id?, nome}`; il segretario può essere «L'amministratore».
 * - `apertura`: il controllo dell'apertura (chi non risulta convocato, perché), la scelta del presidente e il suo motivo.
 * - `fotografia`, `impronta_fotografia`: il JSON canonico della seduta e il suo SHA-256.
 * - `dal_foglio`: la seduta registrata dopo, dal foglio della sala.
 *
 * Rieseguibile: una guardia per colonna (su MySQL ogni colonna è uno statement a sé, e il DDL non è transazionale). Nessuna chiave esterna. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assemblee_sedute')) {
            return;
        }
        $colonne = [
            'aperta_il' => fn (Blueprint $t) => $t->dateTime('aperta_il')->nullable()->comment('L\'ora dell\'apertura, come l\'ha segnata la sala.'),
            'chiusa_il' => fn (Blueprint $t) => $t->dateTime('chiusa_il')->nullable()->comment('L\'ora della chiusura o della sospensione.'),
            'esito' => fn (Blueprint $t) => $t->string('esito', 20)->nullable()->comment('deserta, tenuta, sospesa o rinviata.'),
            'presidente' => fn (Blueprint $t) => $t->json('presidente')->nullable()->comment('{anagrafica_id?, nome}.'),
            'segretario' => fn (Blueprint $t) => $t->json('segretario')->nullable()->comment('{anagrafica_id?, nome}; anche «L\'amministratore».'),
            'apertura' => fn (Blueprint $t) => $t->json('apertura')->nullable()->comment('Il controllo dell\'art. 1136 c. 6 e la scelta del presidente.'),
            'fotografia' => fn (Blueprint $t) => $t->json('fotografia')->nullable()->comment('Teste, unità, millesimi in centomillesimi e totali, fermati all\'apertura.'),
            'impronta_fotografia' => fn (Blueprint $t) => $t->char('impronta_fotografia', 64)->nullable()->comment('SHA-256 del JSON canonico della fotografia.'),
            'dal_foglio' => fn (Blueprint $t) => $t->boolean('dal_foglio')->default(false)->comment('Registrata dopo, dal foglio della sala.'),
        ];
        foreach ($colonne as $colonna => $aggiungi) {
            if (! Schema::hasColumn('assemblee_sedute', $colonna)) {
                Schema::table('assemblee_sedute', $aggiungi);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('assemblee_sedute')) {
            return;
        }
        foreach (['dal_foglio', 'impronta_fotografia', 'fotografia', 'apertura', 'segretario', 'presidente', 'esito', 'chiusa_il', 'aperta_il'] as $colonna) {
            if (Schema::hasColumn('assemblee_sedute', $colonna)) {
                Schema::table('assemblee_sedute', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }
    }
};
