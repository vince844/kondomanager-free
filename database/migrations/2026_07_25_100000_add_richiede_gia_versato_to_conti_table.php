<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Marca esplicitamente una voce di spesa come "da esercizio precedente":
 * l'unico segnale che filtra l'elenco "Già versato" (prima mostrava TUTTE le
 * voci di spesa del condominio, confuso su un piano dei conti con decine di
 * voci — beta.27, richiesta diretta dell'amministratore).
 *
 * Fatto esplicito scelto dall'amministratore in creazione (modale "Nuova
 * voce"), mai indovinato da importo o altro — stesso principio già applicato
 * a `is_capitolo` (migrazione add_is_capitolo_to_conti_table, beta.22).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('conti', function (Blueprint $table) {
            $table->boolean('richiede_gia_versato')->default(false)->after('is_capitolo');
        });

        // Backfill: qualunque voce che ha GIÀ un contributo registrato (perché
        // l'amministratore l'ha usata prima che esistesse questo filtro) non
        // deve sparire dall'elenco solo perché non è stata spuntata in
        // creazione — i dati reali già inseriti restano sempre visibili.
        DB::table('conti')
            ->whereIn('id', function ($q) {
                $q->select('target_id')
                    ->from('contributi_versati')
                    ->where('target_type', \App\Models\Gestionale\Conto::class);
            })
            ->update(['richiede_gia_versato' => true]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('conti', 'richiede_gia_versato')) {
            Schema::table('conti', function (Blueprint $table) {
                $table->dropColumn('richiede_gia_versato');
            });
        }
    }

    /**
     * Rileva ed elimina la colonna orfana lasciata da un'esecuzione parziale
     * precedente (MySQL non supporta DDL transazionali).
     */
    private function cleanupPartialMigration(): void
    {
        if (! Schema::hasColumn('conti', 'richiede_gia_versato')) {
            return;
        }

        Log::warning('Partial migration detected on [conti], cleaning up before re-running', [
            'table' => 'conti',
            'orphans' => ['richiede_gia_versato'],
        ]);

        Schema::table('conti', function (Blueprint $table) {
            $table->dropColumn('richiede_gia_versato');
        });
    }
};
