<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration v1.9 — Saldi Iniziali (ADR-001) — DEFINITIVA
 * Compatibile MySQL 5.7+
 *
 * Gestisce due scenari di partenza:
 *   A) DB vergine o v1.8 originale → indice saldi_esercizio_id_anagrafica_id_immobile_id_unique
 *   B) DB che ha già girato la vecchia v1.9 con sentinella → indice saldi_wallet_unique
 *      (la sentinella era anagrafica_id_key — viene rimossa se presente)
 *
 * ORDINE CRITICO MySQL:
 *   1. Drop di TUTTE le FK (inclusa gestione_id se esiste)
 *   2. Drop degli indici UNIQUE (ora liberi da dipendenze FK)
 *   3. Modifiche strutturali
 *   4. Ricrea FK
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── STEP 1a: Drop di TUTTE le FK prima di toccare qualsiasi indice ────
        // MySQL non permette di droppare un indice UNIQUE usato come supporto
        // da una FK. Tutte le FK devono essere rimosse prima.
        Schema::table('saldi', function (Blueprint $table) {
            $this->tryDropForeign($table, 'saldi', 'gestione_id');
            $this->tryDropForeign($table, 'saldi', 'esercizio_id');
            $this->tryDropForeign($table, 'saldi', 'anagrafica_id');
        });

        // ── STEP 1b: Drop degli indici (ora sicuro, nessuna FK dipendente) ────
        $this->tryDropIndex('saldi', 'saldi_wallet_unique');
        $this->tryDropIndex('saldi', 'saldi_esercizio_id_anagrafica_id_immobile_id_unique');

        // ── STEP 2: Modifiche strutturali ─────────────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            if (Schema::hasColumn('saldi', 'saldo_finale')) {
                $table->dropColumn('saldo_finale');
            }

            // Rimuove la sentinella se presente dalla vecchia v1.9
            if (Schema::hasColumn('saldi', 'anagrafica_id_key')) {
                $table->dropColumn('anagrafica_id_key');
            }

            if (!Schema::hasColumn('saldi', 'gestione_id')) {
                $table->unsignedBigInteger('gestione_id')
                      ->nullable()
                      ->after('esercizio_id');
            }

            if (!Schema::hasColumn('saldi', 'is_applicato')) {
                $table->boolean('is_applicato')
                      ->default(false)
                      ->after('origine');
            }

            $table->unsignedBigInteger('anagrafica_id')->nullable()->change();
        });

        // ── STEP 3: Travaso dati ───────────────────────────────────────────────
        if (DB::getDriverName() === 'mysql') {
            DB::transaction(function () {
                DB::statement("
                    UPDATE saldi s
                    INNER JOIN (
                        SELECT condominio_id, MIN(id) AS id
                        FROM gestioni WHERE tipo = 'ordinaria'
                        GROUP BY condominio_id
                    ) g ON g.condominio_id = s.condominio_id
                    SET s.gestione_id = g.id, s.is_applicato = 0
                    WHERE s.gestione_id IS NULL
                ");

                DB::table('saldi')
                    ->whereNull('gestione_id')
                    ->distinct()
                    ->pluck('condominio_id')
                    ->each(function ($condominioId) {
                        $gId = DB::table('gestioni')->insertGetId([
                            'condominio_id' => $condominioId,
                            'nome'          => 'Gestione Ordinaria',
                            'tipo'          => 'ordinaria',
                            'attiva'        => 1,
                            'data_inizio'   => now()->startOfYear(),
                            'data_fine'     => now()->endOfYear(),
                            'created_at'    => now(),
                            'updated_at'    => now(),
                        ]);
                        DB::table('saldi')
                            ->where('condominio_id', $condominioId)
                            ->whereNull('gestione_id')
                            ->update(['gestione_id' => $gId, 'is_applicato' => 0]);
                    });
            });
        }

        // ── STEP 4: Ricrea tutte le FK ─────────────────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            $table->foreign('esercizio_id')
                  ->references('id')->on('esercizi')
                  ->onDelete('cascade');

            $table->foreign('anagrafica_id')
                  ->references('id')->on('anagrafiche')
                  ->onDelete('cascade');

            $table->foreign('gestione_id')
                  ->references('id')->on('gestioni')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('saldi', function (Blueprint $table) {
            $this->tryDropForeign($table, 'saldi', 'gestione_id');
            $this->tryDropForeign($table, 'saldi', 'esercizio_id');
            $this->tryDropForeign($table, 'saldi', 'anagrafica_id');
        });

        Schema::table('saldi', function (Blueprint $table) {
            $table->dropColumn(['gestione_id', 'is_applicato']);
        });

        Schema::table('saldi', function (Blueprint $table) {
            $table->bigInteger('saldo_finale')->default(0)->after('saldo_iniziale');
            // ATTENZIONE: fallisce se esistono saldi con anagrafica_id = NULL
            $table->unsignedBigInteger('anagrafica_id')->nullable(false)->change();

            $table->foreign('esercizio_id')->references('id')->on('esercizi')->onDelete('cascade');
            $table->foreign('anagrafica_id')->references('id')->on('anagrafiche')->onDelete('cascade');

            $table->unique(
                ['esercizio_id', 'anagrafica_id', 'immobile_id'],
                'saldi_esercizio_id_anagrafica_id_immobile_id_unique'
            );
        });
    }

    // ── Helpers difensivi ──────────────────────────────────────────────────────

    private function tryDropForeign(Blueprint $table, string $tableName, string $column): void
    {
        if ($this->foreignExists($tableName, $column)) {
            $table->dropForeign([$column]);
        }
    }

    /**
     * Rimuove un indice se c'è, su MySQL e su SQLite.
     *
     * Il ramo SQLite mancava, con la motivazione «SQLite non supporta
     * information_schema» — vera per *verificare* l'esistenza, non per il drop:
     * SQLite ha `DROP INDEX IF EXISTS`, che non ha bisogno di alcuna verifica
     * preliminare. L'uscita anticipata faceva quindi sopravvivere su SQLite
     * l'indice `UNIQUE (esercizio_id, anagrafica_id, immobile_id)` creato dalla
     * migrazione del 04/10/2025.
     *
     * Kondomanager gira su MySQL/MariaDB — l'installer non configura altro — e
     * lì l'indice è stato rimosso a suo tempo: **su produzione questo metodo si
     * comporta esattamente come prima.** A cambiare è solo il database dei test,
     * che è SQLite: lì lo schema era più severo di quello reale, quindi il caso
     * legittimo «stessa persona, stessa unità, saldi su due gestioni diverse»
     * era impossibile da coprire con un test. Uno schema di prova più severo di
     * quello vero non protegge: nasconde.
     */
    private function tryDropIndex(string $tableName, string $indexName): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS {$indexName}");

            return;
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $exists = DB::select("
            SELECT COUNT(*) as cnt
            FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
            AND table_name   = ?
            AND index_name   = ?
        ", [$tableName, $indexName]);

        if ($exists[0]->cnt > 0) {
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropIndex($indexName);
            });
        }
    }

    private function foreignExists(string $tableName, string $column): bool
    {
        if (DB::getDriverName() === 'mysql') {
            $result = DB::select("
                SELECT COUNT(*) as cnt
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE table_schema          = DATABASE()
                AND table_name            = ?
                AND column_name           = ?
                AND referenced_table_name IS NOT NULL
            ", [$tableName, $column]);

            return $result[0]->cnt > 0;
        }
        // SQLite non supporta information_schema — assumiamo che la FK non esista
        return false;
    }
};