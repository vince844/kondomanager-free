<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coda 165 (1.11.0-beta.36): la nota di credito del fornitore **sa quale fattura rettifica**.
 *
 * Fino a qui la nota che il fornitore emette per correggere una fattura elettronica sbagliata non si collegava a
 * niente: la fattura restava «aperta» e nel suo piano rate, il piano continuava a chiederla, e la fattura nuova entrava
 * in un secondo piano — la stessa spesa chiesta due volte (sonda del 27/09/2026, verbale in
 * `docs/piano_esecutivo_beta35.md`). Decisione 26, punto 6, in `docs/subentro_e_competenza_temporale.md` §9.
 *
 * Una colonna sola sulla nota, facoltativa, verso una fattura dello stesso fornitore di **qualunque esercizio** (su un
 * file vero il legame attraversa l'esercizio). `nullOnDelete`: l'eliminazione di una fattura con note collegate la
 * rifiuta già il modello (`FatturaPassiva::motivoBloccoEliminazione`); la chiave esterna non deve lasciare un
 * riferimento rotto se una riga sparisce per un'altra strada.
 *
 * **Nessun travaso**: dedurre da importo, data e fornitore quale fattura rettifica una nota già registrata sarebbe
 * tirare a indovinare. Le note vecchie si collegano a mano, dall'elenco («Collega a una fattura»).
 *
 * Rieseguibile: `hasColumn` per la colonna, `information_schema`/`PRAGMA` per la chiave esterna — su MySQL sono due
 * statement distinti e il DDL non è transazionale. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fatture_passive')) {
            return;
        }

        if (! Schema::hasColumn('fatture_passive', 'fattura_rettificata_id')) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->unsignedBigInteger('fattura_rettificata_id')->nullable()->after('tipo_documento')
                    ->comment('Solo sulle note di credito: la fattura che la nota rettifica (Coda 165). Facoltativa.');
            });
        }

        if (! $this->foreignKeyEsiste('fatture_passive', 'fattura_rettificata_id')) {
            Schema::table('fatture_passive', function (Blueprint $table) {
                $table->foreign('fattura_rettificata_id', 'fatture_passive_fattura_rettificata_id_foreign')
                    ->references('id')->on('fatture_passive')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('fatture_passive', 'fattura_rettificata_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('fatture_passive', 'fattura_rettificata_id')) {
            Schema::table('fatture_passive', fn (Blueprint $table) => $table->dropForeign('fatture_passive_fattura_rettificata_id_foreign'));
        }
        Schema::table('fatture_passive', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                $table->dropForeign(['fattura_rettificata_id']);
            }
            $table->dropColumn('fattura_rettificata_id');
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
