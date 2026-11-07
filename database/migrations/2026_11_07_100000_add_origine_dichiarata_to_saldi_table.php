<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.4: l'esercizio d'origine del pregresso, dichiarato dall'amministratore sul saldo, dove il pregresso nasce.
 *
 * - `origine_dichiarata`: che cosa ha dichiarato. `esercizio` (con `esercizio_origine_id`), `con_il_piano` («dovuto con questo piano»: il
 *   conguaglio chiesto adesso), `prima_del_primo` (più vecchio del primo esercizio che il programma conosce). Nulla = «origine non
 *   dichiarata»: il valore di tutti i saldi già scritti, che nessuna riga di questa migrazione riscrive.
 * - `esercizio_origine_id`: l'esercizio dichiarato. Una chiave verso `esercizi` che va a nullo se l'esercizio si cancella (l'origine torna
 *   «non dichiarata» per chi la legge, che controlla la coppia).
 * - `origine_dichiarata_da` e `origine_dichiarata_il`: chi e quando. L'origine cambia la fascia e le date dei sei mesi di rate magari già
 *   sollecitate: la scelta lascia traccia.
 *
 * Il nome `origine` è già preso (manuale, importato, automatico: com'è nato il saldo). L'origine non tocca l'importo: si dichiara anche su un
 * saldo fermo in un piano emesso e sulle righe di un passaggio.
 *
 * Rieseguibile: quattro colonne e due chiavi esterne sono sei statement su MySQL (il DDL non è transazionale), quindi una guardia per ogni
 * colonna e una per ogni chiave. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Colonna => tabella di arrivo della sua chiave esterna (a nullo alla cancellazione). */
    private const CHIAVI = [
        'esercizio_origine_id'  => 'esercizi',
        'origine_dichiarata_da' => 'users',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('saldi')) {
            return;
        }

        if (! Schema::hasColumn('saldi', 'esercizio_origine_id')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->unsignedBigInteger('esercizio_origine_id')->nullable()
                    ->comment('L\'esercizio d\'origine del pregresso, se l\'amministratore lo ha dichiarato.');
            });
        }

        if (! Schema::hasColumn('saldi', 'origine_dichiarata')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->string('origine_dichiarata', 20)->nullable()
                    ->comment('esercizio, con_il_piano, prima_del_primo; nullo = origine non dichiarata.');
            });
        }

        if (! Schema::hasColumn('saldi', 'origine_dichiarata_da')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->unsignedBigInteger('origine_dichiarata_da')->nullable();
            });
        }

        if (! Schema::hasColumn('saldi', 'origine_dichiarata_il')) {
            Schema::table('saldi', function (Blueprint $table) {
                $table->timestamp('origine_dichiarata_il')->nullable();
            });
        }

        foreach (self::CHIAVI as $colonna => $arrivo) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('saldi', $colonna)) {
                Schema::table('saldi', function (Blueprint $table) use ($colonna, $arrivo) {
                    $table->foreign($colonna, "saldi_{$colonna}_foreign")->references('id')->on($arrivo)->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('saldi')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        foreach (array_keys(self::CHIAVI) as $colonna) {
            if (Schema::hasColumn('saldi', $colonna) && $this->foreignKeyEsiste('saldi', $colonna)) {
                Schema::table('saldi', function (Blueprint $table) use ($colonna, $mysql) {
                    // SQLite non sa togliere una chiave per nome: la forma per colonne ricostruisce la tabella.
                    $mysql ? $table->dropForeign("saldi_{$colonna}_foreign") : $table->dropForeign([$colonna]);
                });
            }
        }
        foreach (['esercizio_origine_id', 'origine_dichiarata', 'origine_dichiarata_da', 'origine_dichiarata_il'] as $colonna) {
            if (Schema::hasColumn('saldi', $colonna)) {
                Schema::table('saldi', fn (Blueprint $table) => $table->dropColumn($colonna));
            }
        }
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
                if (($fk->from ?? null) === $colonna) {
                    return true;
                }
            }

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
