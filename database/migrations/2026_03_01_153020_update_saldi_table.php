<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Migration v1.9 — Saldi Iniziali (ADR-001) — DEFINITIVA
 * Compatibile MySQL 5.7+
 *
 * anagrafica_id nullable — scelta architetturale deliberata:
 *   NULL        = saldo solidale sull'immobile (Art. 63 disp. att. c.c.)
 *                 Il NULL non è un dato mancante, è uno stato contabile
 *                 esplicito: "debito solidale, non ancora intestato a nessuno,
 *                 distribuito pro-quota alla generazione rate"
 *   valorizzato = saldo personale su quel soggetto specifico
 *
 * FK nullable è il pattern standard SQL per relazioni 0..1 to N.
 * Nessuna sentinella, nessun trigger, nessuna tabella aggiuntiva.
 * L'unicità dei saldi solidali è garantita dalla StoreSaldoRequest.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── STEP 1: Sgancia FK e indice esistenti ─────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropForeign(['esercizio_id']);
            $table->dropForeign(['anagrafica_id']);
            $table->dropUnique('saldi_esercizio_id_anagrafica_id_immobile_id_unique');
        });

        // ── STEP 2: Modifiche strutturali ─────────────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            if (Schema::hasColumn('saldi', 'saldo_finale')) {
                $table->dropColumn('saldo_finale');
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

            $table->foreign('esercizio_id')
                  ->references('id')->on('esercizi')
                  ->onDelete('cascade');

            $table->foreign('anagrafica_id')
                  ->references('id')->on('anagrafiche')
                  ->onDelete('cascade');
        });

        // ── STEP 3: Travaso dati ───────────────────────────────────────────────
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

        // ── STEP 4: FK gestione ────────────────────────────────────────────────
        Schema::table('saldi', function (Blueprint $table) {
            $table->foreign('gestione_id')
                  ->references('id')->on('gestioni')
                  ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropForeign(['gestione_id']);
            $table->dropForeign(['esercizio_id']);
            $table->dropForeign(['anagrafica_id']);
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
};