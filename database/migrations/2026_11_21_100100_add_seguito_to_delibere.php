<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, il seguito delle delibere): sulla delibera la conferma del riepilogo «Che cosa cambia nel gestionale» (quando e chi: niente
 * cambia nei moduli senza quel clic, decisione 500 (3)) e i suoi dati: la delega con il tetto, l'ambito e la scadenza (decisione 622 (3)) e «che cosa ha
 * cambiato l'assemblea» di una delibera approvata con modifiche (decisione 522 (4)). Una riga applicata non ha una colonna: si legge dal collegamento.
 *
 * Rieseguibile: una guardia per colonna e una per la chiave esterna (su MySQL sono due istruzioni, e il DDL non è transazionale). Nel dataset di
 * `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo la prima colonna.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delibere')) {
            return;
        }
        if (! Schema::hasColumn('delibere', 'seguito_confermato_il')) {
            Schema::table('delibere', fn (Blueprint $t) => $t->dateTime('seguito_confermato_il')->nullable()
                ->comment('La conferma del riepilogo «Che cosa cambia nel gestionale».'));
        }
        if (! Schema::hasColumn('delibere', 'seguito_confermato_da')) {
            Schema::table('delibere', fn (Blueprint $t) => $t->unsignedBigInteger('seguito_confermato_da')->nullable());
        }
        if (! Schema::hasColumn('delibere', 'dati')) {
            Schema::table('delibere', fn (Blueprint $t) => $t->json('dati')->nullable()
                ->comment('La delega con il tetto, l\'ambito e la scadenza; che cosa ha cambiato l\'assemblea.'));
        }
        if (Schema::hasTable('users') && ! $this->chiaveEsiste('delibere', 'seguito_confermato_da')) {
            Schema::table('delibere', fn (Blueprint $t) => $t->foreign('seguito_confermato_da', 'delibere_seguito_confermato_da_foreign')
                ->references('id')->on('users')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('delibere')) {
            return;
        }
        if ($this->chiaveEsiste('delibere', 'seguito_confermato_da')) {
            Schema::table('delibere', fn (Blueprint $t) => $t->dropForeign(['seguito_confermato_da']));
        }
        foreach (['dati', 'seguito_confermato_da', 'seguito_confermato_il'] as $colonna) {
            if (Schema::hasColumn('delibere', $colonna)) {
                Schema::table('delibere', fn (Blueprint $t) => $t->dropColumn($colonna));
            }
        }
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function chiaveEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
                if (($fk->from ?? null) === $colonna) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
              AND referenced_table_name IS NOT NULL
        ', [$tabella, $colonna])->n > 0;
    }
};
