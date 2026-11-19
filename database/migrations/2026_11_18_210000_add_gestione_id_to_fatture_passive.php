<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coda 687 (decisione 507): la fattura ricorda la sua gestione.
 *
 * Fino alla 1.12.0-beta.11 la gestione di una fattura stava solo sulla sua scrittura contabile, e la modifica della fattura la indovinava: la pagina partiva
 * dall'ordinaria, il servizio riscriveva la scrittura con quella (prova B4). La colonna `gestione_id` su `fatture_passive` la tiene sulla fattura, scritta
 * alla registrazione e tenuta dalla modifica; la riempie per le fatture che ci sono la migrazione che segue (`…210100_riempi_gestione_delle_fatture_passive`).
 *
 * Nullable: una fattura senza scrittura e senza voci (ce ne sono) resta senza gestione, e chi la legge ripiega (`FatturaPassiva::gestioneDellaFattura`).
 * La chiave esterna mette a vuoto la colonna se la gestione si cancella, come le altre colonne che puntano a una gestione facoltativa.
 *
 * Rieseguibile: due guardie separate, una per la colonna e una per la chiave (su MySQL sono due statement, e il DDL non è transazionale). Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const CHIAVE = 'fatture_passive_gestione_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('fatture_passive') || ! Schema::hasTable('gestioni')) {
            return;
        }

        if (! Schema::hasColumn('fatture_passive', 'gestione_id')) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->unsignedBigInteger('gestione_id')->nullable()->after('esercizio_id')
                    ->comment('Coda 687: la gestione della fattura, la stessa della sua scrittura di competenza. Vuota se la fattura non ha né scrittura né voci.');
            });
        }

        if (! $this->chiaveEsiste('fatture_passive', 'gestione_id')) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->foreign('gestione_id', self::CHIAVE)->references('id')->on('gestioni')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fatture_passive')) {
            return;
        }

        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->chiaveEsiste('fatture_passive', 'gestione_id')) {
            Schema::table('fatture_passive', fn (Blueprint $table) => $table->dropForeign(self::CHIAVE));
        }

        if (Schema::hasColumn('fatture_passive', 'gestione_id')) {
            $haChiave = ! $mysql && $this->chiaveEsiste('fatture_passive', 'gestione_id');
            Schema::table('fatture_passive', function (Blueprint $table) use ($haChiave) {
                if ($haChiave) {
                    $table->dropForeign(['gestione_id']);
                }
                $table->dropColumn('gestione_id');
            });
        }
    }

    private function chiaveEsiste(string $tabella, string $colonna): bool
    {
        if (! Schema::hasColumn($tabella, $colonna)) {
            return false;
        }

        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $chiave) {
                if (($chiave->from ?? null) === $colonna) {
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
