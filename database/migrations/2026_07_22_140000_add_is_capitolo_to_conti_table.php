<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fix del bug "voce a zero perde la tabella millesimale": se un conto sia
     * un capitolo era INDOVINATO da parent_id nullo + importo=0, mai un fatto
     * persistito — anche se l'amministratore lo sceglie esplicitamente in
     * creazione (il toggle in ModalNuovoConto.vue). Una voce di spesa non
     * ancora budgettizzata quest'anno (importo=0 è il default di fabbrica)
     * con una tabella millesimale reale già collegata veniva scambiata per
     * un capitolo al primo resave della modale di modifica, e la sua
     * tabella/ripartizioni cancellate silenziosamente. Vedi commit e25eefa2,
     * che ha introdotto il ramo distruttivo senza validare come isCapitolo
     * viene determinato a monte.
     *
     * Backfill non a rischio: replica ESATTAMENTE il criterio che
     * FetchCapitoliContiController usa oggi per proporre i capitoli
     * (primo livello + importo 0) — nessun cambio di comportamento
     * osservabile — aggiungendo una sola protezione nuova: chi ha già una
     * tabella millesimale reale non è mai un capitolo, qualunque sia
     * importo o parent_id. Chi ha sottoconti è sempre capitolo, coerente con
     * la guardia strutturale già esistente in ContoController::update().
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('conti', function (Blueprint $table) {
            $table->boolean('is_capitolo')->default(false)->after('parent_id');
        });

        $this->backfill();
    }

    public function down(): void
    {
        if (Schema::hasColumn('conti', 'is_capitolo')) {
            Schema::table('conti', function (Blueprint $table) {
                $table->dropColumn('is_capitolo');
            });
        }
    }

    private function backfill(): void
    {
        // Passo 1: criterio odierno di FetchCapitoliContiController — primo
        // livello, importo a zero. Nessun cambio di comportamento osservabile.
        DB::table('conti')
            ->whereNull('parent_id')
            ->where('importo', 0)
            ->update(['is_capitolo' => true]);

        // Passo 2: protezione nuova — chi ha già una tabella millesimale reale
        // non è MAI un capitolo, qualunque fosse importo/parent_id. È il passo
        // che chiude il bug: questi conti restano per sempre fuori dal ramo
        // distruttivo di ContoController::update().
        DB::table('conti as c')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('conto_tabella_millesimale as ctm')
                    ->whereColumn('ctm.conto_id', 'c.id');
            })
            ->update(['is_capitolo' => false]);

        // Passo 3: chi ha sottoconti è sempre capitolo — anche nel raro caso
        // di un'inconsistenza pregressa in cui avesse anche una tabella
        // propria — coerente con la guardia strutturale già esistente
        // ("un capitolo con sottoconti non può diventare una voce normale").
        //
        // La sottoquery è avvolta in una tabella derivata (SELECT id, parent_id
        // FROM conti) perché MySQL vieta di selezionare dalla STESSA tabella che
        // si sta aggiornando in una sottoquery diretta ("You can't specify target
        // table 'c' for update in FROM clause") — SQLite non ha questa
        // restrizione, motivo per cui il difetto non emergeva sui test in
        // memoria. L'incapsulamento forza MySQL a materializzare il risultato
        // prima dell'UPDATE, aggirando il vincolo senza cambiare il criterio.
        DB::table('conti as c')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from(DB::raw('(select id, parent_id from conti) as figli'))
                    ->whereColumn('figli.parent_id', 'c.id');
            })
            ->update(['is_capitolo' => true]);
    }

    /**
     * Rileva ed elimina la colonna orfana lasciata da un'esecuzione parziale
     * precedente (MySQL non supporta DDL transazionali).
     */
    private function cleanupPartialMigration(): void
    {
        if (! Schema::hasColumn('conti', 'is_capitolo')) {
            return;
        }

        Log::warning('Partial migration detected on [conti], cleaning up before re-running', [
            'table' => 'conti',
            'orphans' => ['is_capitolo'],
        ]);

        Schema::table('conti', function (Blueprint $table) {
            $table->dropColumn('is_capitolo');
        });
    }
};
