<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.11.0-beta.43, decisione 55 in `docs/subentro_e_competenza_temporale.md`: ogni riga di riparto **sa da quale riga di
 * titolarità viene**.
 *
 * Fino alla .42 una riga di `righe_riparto` portava persona, unità e ruolo risolto, e il conguaglio del passaggio
 * riconosceva la quota dal ruolo. Nelle catene lo stesso ruolo può indicare due quote diverse della stessa persona — la
 * nuda proprietà avuta due volte, un ritorno, la metà ricomprata — e il conguaglio si fermava («le quote sono passate per
 * più strade»). Dai piani generati con la .43 il motore scrive qui la riga di `anagrafica_immobile` da cui la riga nasce.
 *
 * Nulla dove la riga non viene da **una** riga di titolarità: `netting` (il già versato è della persona o dell'unità, non
 * di un periodo), `quota_zero` (nessun soggetto), l'addebito diretto all'unità calcolato per persona quando quella persona
 * ha più righe sull'unità (le quote si sommano prima di dividere). Nulla anche sui piani generati prima della .43: la
 * migrazione **non riscrive** le righe esistenti; dove serve, il conguaglio deduce il legame al volo solo se è unico, e
 * dove non lo è si ferma come prima.
 *
 * `nullOnDelete`: una riga di titolarità si cancella da «Dissocia» (solo senza storia), dalla correzione della persona su
 * una riga senza storia, dall'annullamento di un passaggio (le righe che aveva aperto) e in cascata dall'unità o dalla
 * persona. Una riga di riparto non deve impedirlo (`restrict` farebbe fallire quelle porte con un errore SQL) né sparire
 * con lei (`cascade` romperebbe Σ righe = quota pura): perde il legame, e dove il legame non arriva il conguaglio si ferma.
 *
 * Rieseguibile: `hasColumn` per la colonna, `information_schema`/`PRAGMA` per la chiave esterna — su MySQL sono due
 * statement distinti e il DDL non è transazionale. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const FK = 'righe_riparto_anagrafica_immobile_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('righe_riparto') || ! Schema::hasTable('anagrafica_immobile')) {
            return;
        }

        if (! Schema::hasColumn('righe_riparto', 'anagrafica_immobile_id')) {
            Schema::table('righe_riparto', function (Blueprint $table) {
                $table->unsignedBigInteger('anagrafica_immobile_id')->nullable()->after('immobile_id')
                    ->comment('La riga di titolarità da cui viene questa riga di riparto. Nulla per netting, quota_zero, righe senza un legame unico e piani generati prima della 1.11.0-beta.43.');
            });
        }

        if (! $this->foreignKeyEsiste('righe_riparto', 'anagrafica_immobile_id')) {
            Schema::table('righe_riparto', function (Blueprint $table) {
                $table->foreign('anagrafica_immobile_id', self::FK)->references('id')->on('anagrafica_immobile')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('righe_riparto', 'anagrafica_immobile_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('righe_riparto', 'anagrafica_immobile_id')) {
            Schema::table('righe_riparto', fn (Blueprint $table) => $table->dropForeign(self::FK));
        }
        Schema::table('righe_riparto', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                $table->dropForeign(['anagrafica_immobile_id']);
            }
            $table->dropColumn('anagrafica_immobile_id');
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
