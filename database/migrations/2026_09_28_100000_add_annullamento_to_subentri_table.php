<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.11.0-beta.37: l'annullamento di un passaggio registrato (decisione 27 in `docs/subentro_e_competenza_temporale.md`).
 *
 * Quattro colonne su `subentri`:
 *
 * - `annullato_il`, `annullato_da`, `nota_annullamento`: un passaggio annullato **resta nello storico** (punto 4), con la
 *   data, chi l'ha annullato e perché — come il conguaglio annullato della beta.31 — e nessun conto lo legge più (il
 *   modello lo nasconde con uno scope globale).
 * - `registro`: da questa versione il passaggio scrive **ciò che ha fatto**, con i valori di prima e di dopo — righe di
 *   titolarità chiuse, aperte o modificate, quote delle bozze riassegnate — e l'annullamento lo rilegge al contrario
 *   (punto 8). Senza registro (i passaggi di prima) l'annullamento si rifiuta: ricostruire le righe dalle date sarebbe
 *   tirare a indovinare.
 *
 * **Nessun travaso**: il registro dei passaggi già registrati non si può ricostruire, per la stessa ragione.
 *
 * `annullato_da` → `users`, `nullOnDelete`: un utente che sparisce non deve cancellare la traccia dell'annullamento.
 *
 * Rieseguibile: `hasColumn` per ogni colonna, `information_schema`/`PRAGMA` per la chiave esterna — su MySQL sono
 * statement distinti e il DDL non è transazionale. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }

        if (! Schema::hasColumn('subentri', 'annullato_il')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->timestamp('annullato_il')->nullable()->after('nota_annullamento_conguaglio')
                    ->comment('Passaggio annullato (beta.37): resta nello storico, nessun conto lo legge più.');
            });
        }
        if (! Schema::hasColumn('subentri', 'annullato_da')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->unsignedBigInteger('annullato_da')->nullable()->after('annullato_il')->comment('Chi ha annullato il passaggio.');
            });
        }
        if (! Schema::hasColumn('subentri', 'nota_annullamento')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->text('nota_annullamento')->nullable()->after('annullato_da')->comment('Perché il passaggio è stato annullato.');
            });
        }
        if (! Schema::hasColumn('subentri', 'registro')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->json('registro')->nullable()->after('nota_annullamento')
                    ->comment('Ciò che il passaggio ha scritto, con i valori di prima e di dopo (beta.37): lo rilegge l\'annullamento.');
            });
        }

        if (! $this->foreignKeyEsiste('subentri', 'annullato_da')) {
            Schema::table('subentri', function (Blueprint $table) {
                $table->foreign('annullato_da', 'subentri_annullato_da_foreign')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('subentri', 'annullato_da')) {
            Schema::table('subentri', fn (Blueprint $table) => $table->dropForeign('subentri_annullato_da_foreign'));
        }
        foreach (['registro', 'nota_annullamento', 'annullato_da', 'annullato_il'] as $colonna) {
            if (! Schema::hasColumn('subentri', $colonna)) {
                continue;
            }
            Schema::table('subentri', function (Blueprint $table) use ($colonna, $mysql) {
                if (! $mysql && $colonna === 'annullato_da') {
                    $table->dropForeign(['annullato_da']);
                }
                $table->dropColumn($colonna);
            });
        }
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
