<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §0.4 e §1.2 del progetto della tappa 2): la tabella di proprietà generale, una scelta dell'amministratore per il condominio, la
 * palazzina e la scala. Sul perimetro e non sulla tabella: la tabella di una palazzina non sempre porta la palazzina, e così è una sola per perimetro senza
 * indici unici con i valori nulli. **Nessun riempimento** dai dati di oggi: `tabelle.tipo` è `standard` di serie e non distingue niente. Tolta la tabella, la
 * scelta torna vuota (`set null`); la cascata del condominio che porta via le sue tabelle regge anche su MySQL (provato il 19/11/2026).
 *
 * Rieseguibile: per ogni tabella una guardia per la colonna e una per la chiave (su MySQL sono due statement, e il DDL non è transazionale). Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const TABELLE = ['condomini', 'palazzine', 'scale'];

    public function up(): void
    {
        if (! Schema::hasTable('tabelle')) {
            return;
        }

        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella)) {
                continue;
            }
            if (! Schema::hasColumn($tabella, 'tabella_proprieta_id')) {
                Schema::table($tabella, function (Blueprint $t) {
                    $t->unsignedBigInteger('tabella_proprieta_id')->nullable()
                        ->comment('La tabella di proprietà generale scelta dall\'amministratore per questo perimetro.');
                });
            }
            if (! $this->chiaveEsiste($tabella, 'tabella_proprieta_id')) {
                Schema::table($tabella, function (Blueprint $t) use ($tabella) {
                    $t->foreign('tabella_proprieta_id', "{$tabella}_tabella_proprieta_id_foreign")->references('id')->on('tabelle')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        $mysql = DB::getDriverName() === 'mysql';
        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella)) {
                continue;
            }
            if ($mysql && $this->chiaveEsiste($tabella, 'tabella_proprieta_id')) {
                Schema::table($tabella, fn (Blueprint $t) => $t->dropForeign("{$tabella}_tabella_proprieta_id_foreign"));
            }
            if (Schema::hasColumn($tabella, 'tabella_proprieta_id')) {
                $haChiave = ! $mysql && $this->chiaveEsiste($tabella, 'tabella_proprieta_id');
                Schema::table($tabella, function (Blueprint $t) use ($haChiave) {
                    if ($haChiave) {
                        $t->dropForeign(['tabella_proprieta_id']);
                    }
                    $t->dropColumn('tabella_proprieta_id');
                });
            }
        }
    }

    private function chiaveEsiste(string $tabella, string $colonna): bool
    {
        if (! Schema::hasColumn($tabella, $colonna)) {
            return false;
        }
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
