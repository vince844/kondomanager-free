<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration v1.9 — Saldi Iniziali (ADR-001)
 *
 * Compatibile con MySQL 5.7+ e MariaDB.
 *
 * Modifiche:
 * - DROP saldo_finale (calcolato al volo, mai persistito)
 * - ADD gestione_id   (separazione fondi Art. 1130-bis c.c.)
 * - ADD is_applicato  (blocco saldi inclusi in piani rate emessi)
 * - anagrafica_id -> nullable (saldi solidali sull'immobile, Art. 63 disp. att. c.c.)
 * - ADD anagrafica_id_key (sentinella per unique parziale, MySQL 5.7 compatibile)
 * - TRIGGER before_insert / before_update per mantenere la sentinella sincronizzata
 * - UNIQUE (esercizio_id, gestione_id, immobile_id, anagrafica_id_key)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── STEP 1: Sgancio FK e indici esistenti (Prevenzione Errori MySQL) ──
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropForeign(['esercizio_id']);
            $table->dropForeign(['anagrafica_id']);
            $table->dropUnique('saldi_esercizio_id_anagrafica_id_immobile_id_unique');
        });

        // ── STEP 2: Modifiche strutturali DDL ─────────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            // Rimuoviamo il rumore contabile
            if (Schema::hasColumn('saldi', 'saldo_finale')) {
                $table->dropColumn('saldo_finale');
            }

            // Aggiungiamo i riferimenti alla Gestione
            if (!Schema::hasColumn('saldi', 'gestione_id')) {
                $table->unsignedBigInteger('gestione_id')->nullable()->after('esercizio_id');
            }
            if (!Schema::hasColumn('saldi', 'is_applicato')) {
                $table->boolean('is_applicato')->default(false)->after('origine');
            }

            // Rendiamo nullable l'anagrafica (Il cuore del Modello Ibrido)
            $table->unsignedBigInteger('anagrafica_id')->nullable()->change();

            // Colonna Sentinella (Normale colonna, i Trigger faranno il resto)
            if (!Schema::hasColumn('saldi', 'anagrafica_id_key')) {
                $table->unsignedBigInteger('anagrafica_id_key')
                      ->default(0)
                      ->after('anagrafica_id')
                      ->comment('Sentinella per UNIQUE parziale. Gestita da trigger.');
            }

            // Ripristiniamo le FK di base
            $table->foreign('esercizio_id')->references('id')->on('esercizi')->onDelete('cascade');
            $table->foreign('anagrafica_id')->references('id')->on('anagrafiche')->onDelete('cascade');
        });

        // ── STEP 3: Travaso dati in Transaction Atomica (Zero Data Loss) ──────
        DB::transaction(function () {
            // 1. Bulk update per i condomini che hanno già una gestione ordinaria
            DB::statement("
                UPDATE saldi s
                INNER JOIN (
                    SELECT condominio_id, MIN(id) AS id
                    FROM gestioni
                    WHERE tipo = 'ordinaria'
                    GROUP BY condominio_id
                ) g ON g.condominio_id = s.condominio_id
                SET s.gestione_id  = g.id,
                    s.is_applicato = 0
                WHERE s.gestione_id IS NULL
            ");

            // 2. Fallback per i saldi rimasti senza gestione (es. vecchi condomini)
            $condominiSenzaGestione = DB::table('saldi')
                ->whereNull('gestione_id')
                ->distinct()
                ->pluck('condominio_id');

            foreach ($condominiSenzaGestione as $condominioId) {
                $gestioneId = DB::table('gestioni')->insertGetId([
                    'condominio_id' => $condominioId,
                    'nome'          => 'Gestione Ordinaria Base',
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
                    ->update([
                        'gestione_id'  => $gestioneId,
                        'is_applicato' => 0,
                    ]);
            }

            // 3. Popoliamo la sentinella per tutti i record storici
            DB::statement('
                UPDATE saldi
                SET anagrafica_id_key = COALESCE(anagrafica_id, 0)
            ');
        });

        // ── STEP 4: Trigger per la sincronizzazione della Sentinella ──────────
        DB::unprepared('DROP TRIGGER IF EXISTS saldi_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS saldi_before_update');

        DB::unprepared('
            CREATE TRIGGER saldi_before_insert
            BEFORE INSERT ON saldi
            FOR EACH ROW
            BEGIN
                SET NEW.anagrafica_id_key = COALESCE(NEW.anagrafica_id, 0);
            END
        ');

        DB::unprepared('
            CREATE TRIGGER saldi_before_update
            BEFORE UPDATE ON saldi
            FOR EACH ROW
            BEGIN
                SET NEW.anagrafica_id_key = COALESCE(NEW.anagrafica_id, 0);
            END
        ');

        // ── STEP 5: FK Gestione e Indice UNIQUE Finale ────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            $table->foreign('gestione_id')->references('id')->on('gestioni')->onDelete('cascade');

            $table->unique(
                ['esercizio_id', 'gestione_id', 'immobile_id', 'anagrafica_id_key'],
                'saldi_wallet_unique'
            );
        });
    }

    public function down(): void
    {
        // Rimuoviamo i trigger
        DB::unprepared('DROP TRIGGER IF EXISTS saldi_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS saldi_before_update');

        // Sganciamo FK e UNIQUE (Closure 1 per evitare bug DBAL)
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropUnique('saldi_wallet_unique');
            $table->dropForeign(['gestione_id']);
            $table->dropForeign(['esercizio_id']);
            $table->dropForeign(['anagrafica_id']);
        });

        // Rimuoviamo colonne aggiunte (Closure 2)
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropColumn(['gestione_id', 'is_applicato', 'anagrafica_id_key']);
        });

        // Ripristiniamo vecchie colonne e vecchi indici (Closure 3)
        Schema::table('saldi', function (Blueprint $table) {
            $table->bigInteger('saldo_finale')->default(0)->after('saldo_iniziale');
            
            // Attenzione: fallirà se ci sono record con anagrafica_id = NULL
            $table->unsignedBigInteger('anagrafica_id')->nullable(false)->change();

            $table->foreign('esercizio_id')->references('id')->on('esercizi')->onDelete('cascade');
            $table->foreign('anagrafica_id')->references('id')->on('anagrafiche')->onDelete('cascade');

            $table->unique(
                ['esercizio_id', 'anagrafica_id', 'immobile_id'],
                'saldi_esercizio_id_anagrafica_id_immobile_id_unique'
            );
        });
    }
};