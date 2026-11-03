<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 8 (1.11.0-beta.31, S5): ciò che la registrazione del passaggio sa e che `subentri`
 * non aveva dove scrivere. Quattro fatti e due chiavi, tutte nullable:
 *
 * - `documento_id` → `documenti`: il PDF del titolo allegato al passaggio (Checkpoint 1, 19/09: «potrebbe
 *   essere necessario allegare dei file?»). `nullOnDelete`: cancellato il documento, il passaggio resta.
 * - `data_fine_locazione`: la scadenza del contratto. **Non** va in `anagrafica_immobile.data_fine`: per il
 *   motore quella data chiude sempre (D7), e §6.6 del progetto dice che la scadenza «è una scadenza, non un
 *   automatismo — il programma non chiude la locazione da solo». Qui è un fatto del passaggio, da cui nasce
 *   il promemoria in agenda.
 * - `regime_contratto`: il regime dichiarato nel modulo (abitativo, uso diverso, atipica, comodato), che
 *   il modulo raccoglie dal S3 e che altrimenti si perderebbe.
 * - `nota_conguaglio`: se compilata, l'amministratore ha **rinunciato** alla coppia di conguaglio proposta
 *   («regolato fra le parti nel rogito del …», Cass. 11199/2021 «salvo diverso accordo»): nessuna riga in
 *   `saldi`, e la ragione congelata qui.
 * - `subentro_padre_id` → `subentri`: le pertinenze spuntate nel modulo fanno una riga `subentri` ciascuna
 *   (hanno un `immobile_id` proprio), legata al passaggio dell'unità principale. `nullOnDelete`.
 *
 * Perché una migrazione nuova e non la modifica di `create_subentri`: i due checkout condividono lo stesso
 * MySQL, già migrato in S2 (`hasTable` restituirebbe subito). Rieseguibile: `hasColumn` per ogni colonna,
 * `information_schema`/`PRAGMA` per ogni FK, come `add_subentro_id_to_saldi`. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }

        Schema::table('subentri', function (Blueprint $table) {
            if (! Schema::hasColumn('subentri', 'documento_id')) {
                $table->unsignedBigInteger('documento_id')->nullable()->after('copia_autentica_il')
                    ->comment('Il PDF del titolo allegato al passaggio (B2, S5); nullo se non allegato.');
            }
            if (! Schema::hasColumn('subentri', 'data_fine_locazione')) {
                $table->date('data_fine_locazione')->nullable()->after('decorrenza')
                    ->comment('Scadenza del contratto di locazione dichiarata nel modulo: un promemoria, non una chiusura (§6.6).');
            }
            if (! Schema::hasColumn('subentri', 'regime_contratto')) {
                $table->string('regime_contratto', 30)->nullable()->after('data_fine_locazione');
            }
            if (! Schema::hasColumn('subentri', 'nota_conguaglio')) {
                $table->text('nota_conguaglio')->nullable()->after('nota_cancello')
                    ->comment('Compilata = l\'amministratore ha rinunciato alla coppia di conguaglio proposta, e dice perché.');
            }
            if (! Schema::hasColumn('subentri', 'subentro_padre_id')) {
                $table->unsignedBigInteger('subentro_padre_id')->nullable()->after('immobile_id')
                    ->comment('Il passaggio dell\'unità principale, per la riga di una pertinenza spuntata nel modulo.');
            }
        });

        if (Schema::hasTable('documenti') && ! $this->foreignKeyEsiste('subentri', 'documento_id')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->foreign('documento_id', 'subentri_documento_id_foreign')->references('id')->on('documenti')->nullOnDelete();
            });
        }
        if (! $this->foreignKeyEsiste('subentri', 'subentro_padre_id')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->foreign('subentro_padre_id', 'subentri_subentro_padre_id_foreign')->references('id')->on('subentri')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        foreach (['documento_id' => 'subentri_documento_id_foreign', 'subentro_padre_id' => 'subentri_subentro_padre_id_foreign'] as $colonna => $fk) {
            if ($mysql && $this->foreignKeyEsiste('subentri', $colonna)) {
                Schema::table('subentri', fn (Blueprint $table) => $table->dropForeign($fk));
            }
        }
        Schema::table('subentri', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                // SQLite non sa togliere una FK per nome: la forma per colonne ricostruisce la tabella.
                foreach (['documento_id', 'subentro_padre_id'] as $colonna) {
                    if (Schema::hasColumn('subentri', $colonna)) {
                        $table->dropForeign([$colonna]);
                    }
                }
            }
            foreach (['documento_id', 'data_fine_locazione', 'regime_contratto', 'nota_conguaglio', 'subentro_padre_id'] as $colonna) {
                if (Schema::hasColumn('subentri', $colonna)) {
                    $table->dropColumn($colonna);
                }
            }
        });
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite la FK nasce con la colonna: si guarda la colonna. */
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
