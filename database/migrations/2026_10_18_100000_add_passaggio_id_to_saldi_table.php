<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.11.0-beta.53 (Coda 244): una riga del Wallet scritta a mano sa per quale passaggio di titolarità è stata scritta.
 *
 * La parte di una fermata che il programma non sposta (la strada a mano) si passa dal Wallet con righe di segno opposto. Legate al
 * passaggio, quelle righe non occupano il posto di un riporto (un saldo per persona, gestione, unità ed esercizio) e si tolgono quando
 * il passaggio si annulla. `passaggio_id` e `subentro_id` si escludono: `subentro_id` è la riga che il passaggio scrive da sé (coppie,
 * arretrato), `passaggio_id` la riga che l'amministratore scrive dal Wallet per quel passaggio. `restrictOnDelete`, come `subentro_id`:
 * un passaggio non si cancella (resta, annullato), e un passaggio annullato non ha più righe legate.
 *
 * Nessun travaso: le righe di prima restano senza legame.
 *
 * Rieseguibile: colonna e chiave esterna sono **due statement** su MySQL (il DDL non è transazionale), quindi due guardie separate —
 * `hasColumn` per la colonna, `information_schema` per il vincolo — come `add_subentro_id_to_saldi`. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('saldi') || ! Schema::hasTable('subentri')) {
            return;
        }

        if (! Schema::hasColumn('saldi', 'passaggio_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $colonna = $table->unsignedBigInteger('passaggio_id')->nullable()
                    ->comment('Il passaggio di titolarità a cui l\'amministratore ha legato questa riga scritta a mano dal Wallet; nullo per i saldi ordinari e per le righe che un passaggio scrive (subentro_id).');
                if (Schema::hasColumn('saldi', 'subentro_id')) {
                    $colonna->after('subentro_id');
                }
            });
        }

        if (! $this->foreignKeyEsiste('saldi', 'passaggio_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->foreign('passaggio_id', 'saldi_passaggio_id_foreign')->references('id')->on('subentri')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('saldi', 'passaggio_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('saldi', 'passaggio_id')) {
            Schema::table('saldi', fn (Blueprint $table) => $table->dropForeign('saldi_passaggio_id_foreign'));
        }
        Schema::table('saldi', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                // SQLite non sa togliere una FK per nome: la forma per colonne ricostruisce la tabella.
                $table->dropForeign(['passaggio_id']);
            }
            $table->dropColumn('passaggio_id');
        });
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
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
