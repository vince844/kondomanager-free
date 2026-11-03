<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Via le due colonne di `rate_quote` che non sono mai state scritte né lette (1.11.0-beta.29).
 *
 * `riga_fattura_id` e `voce_id` nascono il 19/04/2026 con la migrazione «hardening legale e
 * tracciabilità fatture», con FK e il commento «popolato in Fase 2»: zero occorrenze in `app/`,
 * `resources/js/`, `tests/`, non nel `$fillable` di `RataQuote`, 0 righe valorizzate. Non
 * bastavano nemmeno come disegno — una quota ordinaria nasce dalla somma di più capitoli, e una
 * FK singola non la rappresenta: la forma giusta è la tabella `righe_riparto`, creata dalla
 * migrazione precedente. Si tolgono senza backfill perché non c'è niente da portare.
 *
 * ## Rieseguibile, e come
 *
 * Il modello è `add_pertinenza_di_to_immobili` (15/08/2026), scritto per lo stesso problema:
 * su MySQL il DDL non è transazionale e il vincolo è uno statement a sé — si toglie in un
 * `Schema::table` separato, **solo se esiste** (`information_schema`), altrimenti errore 1091 sullo
 * stato parziale; poi la colonna, con `hasColumn`. Su SQLite il `dropForeign` va **nello stesso
 * blueprint** del `dropColumn`: non emette uno statement, ma dice a Laravel di escludere il
 * vincolo quando ricostruisce la tabella, altrimenti «unknown column … in foreign key definition».
 *
 * ⚠️ Non copiare il `down()` della hardening: fa `dropForeign` senza guardia. Qui `down()` non
 * ricrea le colonne (precedente: la stessa `add_pertinenza_di`): erano vuote, tornare indietro
 * non restituirebbe niente. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const COLONNE = ['riga_fattura_id', 'voce_id'];

    public function up(): void
    {
        if (! Schema::hasTable('rate_quote')) {
            return;
        }

        $mysql = DB::getDriverName() === 'mysql';

        foreach (self::COLONNE as $colonna) {
            if ($mysql && $this->foreignKeyEsiste('rate_quote', $colonna)) {
                Schema::table('rate_quote', function (Blueprint $table) use ($colonna) {
                    $table->dropForeign([$colonna]);
                });
            }
        }

        Schema::table('rate_quote', function (Blueprint $table) use ($mysql) {
            foreach (self::COLONNE as $colonna) {
                if (! Schema::hasColumn('rate_quote', $colonna)) {
                    continue;
                }
                if (! $mysql) {
                    $table->dropForeign([$colonna]);
                }
                $table->dropColumn($colonna);
            }
        });
    }

    public function down(): void
    {
        // Le colonne erano vuote per costruzione: non c'è niente da ricreare.
    }

    /** Esiste una chiave esterna su questa colonna? Domanda solo per MySQL. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
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
};
