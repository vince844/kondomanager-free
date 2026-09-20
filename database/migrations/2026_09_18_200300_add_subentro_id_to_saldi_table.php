<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 4 (1.11.0-beta.31): la coppia di conguaglio in `saldi` sa da quale passaggio nasce.
 *
 * D9 del progetto: un subentro registrato dopo l'emissione non tocca le rate; scrive due righe in
 * `saldi` sulla stessa gestione e sullo stesso immobile — credito all'uscente, debito all'entrante —
 * che sommano esattamente zero e che il piano successivo assorbe. `subentro_id` lega le due righe al loro
 * evento, così `SaldoInizialeController::update()`/`destroy()` possono rifiutare di toccarne una sola
 * (invariante 19) e la stampa può dire da dove viene il conguaglio. `restrictOnDelete`: un passaggio con
 * il suo conguaglio in contabilità non si cancella — il vincolo protegge `Subentro::delete()`; la via
 * dell'unità (`ImmobileController::destroy`, cascata su `subentri`) è chiusa dal controller (S8-27).
 *
 * Rieseguibile: colonna e FK sono **due statement** su MySQL (il DDL non è transazionale), quindi due
 * guardie separate — `hasColumn` per la colonna, `information_schema` per il vincolo — come
 * `add_pertinenza_di_to_immobili`. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saldi') || ! Schema::hasTable('subentri')) {
            return;
        }

        if (! Schema::hasColumn('saldi', 'subentro_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->unsignedBigInteger('subentro_id')->nullable()->after('piano_rate_id')
                    ->comment('Il passaggio di titolarità che ha generato questa riga di conguaglio (B2); nullo per i saldi ordinari.');
            });
        }

        if (! $this->foreignKeyEsiste('saldi', 'subentro_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->foreign('subentro_id', 'saldi_subentro_id_foreign')->references('id')->on('subentri')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('saldi', 'subentro_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('saldi', 'subentro_id')) {
            Schema::table('saldi', fn (Blueprint $table) => $table->dropForeign('saldi_subentro_id_foreign'));
        }
        Schema::table('saldi', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                // SQLite non sa togliere una FK per nome: la forma per colonne ricostruisce la tabella.
                $table->dropForeign(['subentro_id']);
            }
            $table->dropColumn('subentro_id');
        });
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite la FK nasce con la colonna: si guarda la colonna. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            // SQLite: `Schema::table()->foreign()` ricostruisce la tabella; se la colonna c'è già la FK c'è già.
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
