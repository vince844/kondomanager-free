<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 9 (1.11.0-beta.31, verifica indipendente di S5, rilievo R8): il piano rate **sa in quale
 * esercizio è stato generato**.
 *
 * Fino a qui l'esercizio arrivava solo dall'indirizzo (`esercizi/{esercizio}/piani-rate`) e chi non aveva
 * l'indirizzo — il conguaglio del passaggio, l'anteprima — lo deduceva dalla data di creazione del piano. Il
 * prodotto riusa la stessa gestione ordinaria su più esercizi (`CondominioService::createDefaultGestione`,
 * importatore): un consuntivo 2026 creato a febbraio 2027 veniva ripartito sul 2026 alla generazione e
 * conguagliato sul 2027 al passaggio — due competenze per lo stesso piano, contro il progetto («decisa in un
 * posto solo»). Da qui `GeneratePianoRateAction` scrive `esercizio_id` a ogni generazione e
 * `CompetenzaDelPiano::esercizioDelPiano()` lo legge per primo; la deduzione resta solo per i piani vecchi
 * senza colonna valorizzata, e il pannello lo dichiara.
 *
 * Il travaso riempie la colonna dove non c'è ambiguità: gestione legata a **un solo** esercizio. Dove sono
 * più d'uno resta nullo (deduzione a runtime, dichiarata). Rieseguibile: `hasColumn`, FK con
 * `information_schema`/`PRAGMA`, travaso solo su nulli. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('piani_rate')) {
            return;
        }

        if (! Schema::hasColumn('piani_rate', 'esercizio_id')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->unsignedBigInteger('esercizio_id')->nullable()->after('gestione_id')
                    ->comment('L\'esercizio con cui il piano è stato generato (B2): la competenza del riparto e del conguaglio parte da qui.');
            });
        }

        if (Schema::hasTable('esercizi') && ! $this->foreignKeyEsiste('piani_rate', 'esercizio_id')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->foreign('esercizio_id', 'piani_rate_esercizio_id_foreign')->references('id')->on('esercizi')->nullOnDelete();
            });
        }

        // Travaso: solo dove la gestione vive su un esercizio solo.
        if (Schema::hasTable('esercizio_gestione')) {
            $unici = DB::table('esercizio_gestione')
                ->select('gestione_id', DB::raw('MIN(esercizio_id) as esercizio_id'), DB::raw('COUNT(*) as n'))
                ->groupBy('gestione_id')->having('n', '=', 1)->get();
            foreach ($unici as $u) {
                DB::table('piani_rate')->where('gestione_id', $u->gestione_id)->whereNull('esercizio_id')->update(['esercizio_id' => $u->esercizio_id]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('piani_rate', 'esercizio_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('piani_rate', 'esercizio_id')) {
            Schema::table('piani_rate', fn (Blueprint $table) => $table->dropForeign('piani_rate_esercizio_id_foreign'));
        }
        Schema::table('piani_rate', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                $table->dropForeign(['esercizio_id']);
            }
            $table->dropColumn('esercizio_id');
        });
    }

    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return Schema::hasColumn($tabella, $colonna) && $this->sqliteHaForeign($tabella, $colonna);
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

    private function sqliteHaForeign(string $tabella, string $colonna): bool
    {
        foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
            if (($fk->from ?? null) === $colonna) {
                return true;
            }
        }

        return false;
    }
};
