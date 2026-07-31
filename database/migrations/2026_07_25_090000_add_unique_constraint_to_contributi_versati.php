<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chiude due lacune trovate dalla revisione avversariale della beta.26, sulla
 * tabella `contributi_versati` appena introdotta:
 *
 *   1. Nessun vincolo impediva due righe per la stessa (target, immobile): se
 *      mai accadesse, il motore di riparto le SOMMA (`ContributoVersato::perImmobile`)
 *      mentre la pagina di modifica ne mostra una sola (`keyBy('immobile_id')`)
 *      — l'amministratore vedrebbe un numero diverso da quello davvero applicato.
 *   2. Le query più frequenti su questa tabella (il DELETE del salvataggio, e
 *      `perImmobile()` letta dal motore ad ogni riparto) filtrano su
 *      `target_type`+`target_id` SENZA `condominio_id`: l'indice esistente
 *      (`condominio_id`, `target_type`, `target_id`) non le copre, perché
 *      `condominio_id` non compare nel WHERE. Il vincolo unique qui sotto,
 *      cominciando proprio da quelle due colonne, fa anche da indice per loro.
 *
 * Migrazione additiva pura: nessuna colonna tocca dati esistenti. Il DEDUP
 * difensivo prima del vincolo è una cautela, non una correzione nota — la
 * tabella è appena stata introdotta e non risultano duplicati in produzione.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale: se l'esecuzione precedente è morta
        // dopo la creazione del vincolo ma prima di registrare la migrazione,
        // un secondo tentativo fallirebbe con "Duplicate key name" e bloccherebbe
        // per sempre un aggiornamento che la pagina di conferma dichiara
        // riprendibile. Il vincolo c'è già: non resta nulla da fare.
        if (Schema::hasIndex('contributi_versati', 'cv_target_immobile_unique')) {
            return;
        }

        // Difensivo: se per qualunque motivo esistono già righe duplicate,
        // tiene la più vecchia (id più basso) e rimuove le altre, sommandone
        // l'importo in quella superstite — non fa sparire denaro dal ledger.
        $duplicati = DB::table('contributi_versati')
            ->select('target_type', 'target_id', 'immobile_id')
            ->groupBy('target_type', 'target_id', 'immobile_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicati as $d) {
            $righe = DB::table('contributi_versati')
                ->where('target_type', $d->target_type)
                ->where('target_id', $d->target_id)
                ->where('immobile_id', $d->immobile_id)
                ->orderBy('id')
                ->get();

            $superstite = $righe->first();
            $totale = (int) $righe->sum('importo_cents');

            DB::table('contributi_versati')->where('id', $superstite->id)
                ->update(['importo_cents' => $totale]);

            DB::table('contributi_versati')
                ->whereIn('id', $righe->skip(1)->pluck('id'))
                ->delete();
        }

        Schema::table('contributi_versati', function (Blueprint $table) {
            $table->unique(['target_type', 'target_id', 'immobile_id'], 'cv_target_immobile_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contributi_versati', function (Blueprint $table) {
            $table->dropUnique('cv_target_immobile_unique');
        });
    }
};
