<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Il modo per dire «questo movimento ne annulla un altro» — e il collegamento che manca da sempre.
 *
 * ## Perché serve
 *
 * «Sposta spesa» (beta.9.0-beta.3, `BudgetMovementService`) nasceva già pensato come reversibile —
 * la colonna `type` porta da allora i valori mai usati `'treasury_fix'`/`'fiscal_adjustment'`, e il
 * commento sullo snapshot pre-spostamento dice esplicitamente «Per Audit e Rollback sicuro». Ma
 * `BudgetMovementController` ha sempre avuto **una sola rotta, `store`**: nessun modo di annullare
 * un movimento, e il messaggio che blocca la rimozione di una voce coinvolta (*«devi annullare
 * questi movimenti (restituendo i fondi)»*) indicava un'azione che non esisteva da nessuna parte.
 *
 * ## Come funziona
 *
 * Uno storno **non modifica né cancella** la riga originale: crea un nuovo movimento uguale e
 * contrario (sorgente e destinazione scambiate, stesso importo), e questa colonna lo lega
 * all'originale. Stessa filosofia della contabilità del progetto — non si cancella, si rettifica.
 * `BudgetMovementService::reverseMovement()` verifica che nessun altro movimento rivendichi già
 * questo collegamento, prima di crearne uno nuovo: uno storno per volta, e la storia resta leggibile
 * seguendo la catena. Quella verifica applicativa da sola è una corsa critica (due richieste quasi
 * simultanee vedono entrambe "non ancora stornato"); l'indice unico sotto la chiude a livello di
 * database, dove nessuna corsa può vincere due volte.
 *
 * `nullOnDelete()` invece che «mai cancellato»: `budget_movements.piano_rate_id` ha già una cascata
 * verso `piani_rate` (un piano in bozza senza pagamenti si può eliminare), quindi un intero piano —
 * originale e storno insieme — può sparire con lui. Nessuna riga resta orfana perché vengono
 * cancellate nella stessa cascata; `nullOnDelete()` è solo la difesa per il caso, oggi non prodotto
 * dall'applicazione, in cui restasse solo uno dei due.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ Guardia sulla colonna: il DDL MySQL non è transazionale, questa migrazione deve poter
        // girare due volte senza rompersi. Presidiata da tests/Feature/System/UpgradeMigrationsRerunTest.php.
        if (! Schema::hasColumn('budget_movements', 'reverses_movement_id')) {
            Schema::table('budget_movements', function (Blueprint $table) {
                $table->unsignedBigInteger('reverses_movement_id')
                    ->nullable()
                    ->after('type')
                    ->comment('Se valorizzato, questo movimento ne storna un altro — vedi budget_movements.id');
            });
        }

        if (! $this->haLaChiaveEsterna()) {
            Schema::table('budget_movements', function (Blueprint $table) {
                $table->foreign('reverses_movement_id')
                    ->references('id')->on('budget_movements')
                    ->nullOnDelete();
            });
        }

        // ⚠️ NULL è distinto da sé stesso in un indice unico (MySQL e SQLite), quindi non limita i
        // movimenti normali (reverses_movement_id sempre NULL): impedisce solo che due righe
        // rivendichino lo stesso storno, chiudendo a livello di database la corsa critica che il
        // solo controllo applicativo in reverseMovement() non può vincere da solo.
        if (! Schema::hasIndex('budget_movements', ['reverses_movement_id'], 'unique')) {
            Schema::table('budget_movements', function (Blueprint $table) {
                $table->unique('reverses_movement_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('budget_movements', 'reverses_movement_id')) {
            if (Schema::hasIndex('budget_movements', ['reverses_movement_id'], 'unique')) {
                Schema::table('budget_movements', function (Blueprint $table) {
                    $table->dropUnique(['reverses_movement_id']);
                });
            }
            if ($this->haLaChiaveEsterna()) {
                Schema::table('budget_movements', function (Blueprint $table) {
                    $table->dropForeign(['reverses_movement_id']);
                });
            }
            Schema::table('budget_movements', function (Blueprint $table) {
                $table->dropColumn('reverses_movement_id');
            });
        }
    }

    /**
     * La chiave esterna su `reverses_movement_id` esiste già?
     *
     * Stessa difesa di `2026_08_22_090000_add_updated_by_to_comunicazioni_segnalazioni_documenti`:
     * su SQLite (la suite gira in memoria lì, il prodotto su MySQL) `information_schema` non esiste
     * e SQLite non sa comunque aggiungere una foreign key a una tabella già creata — la domanda va
     * saltata, non posta.
     */
    private function haLaChiaveEsterna(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'budget_movements')
            ->where('COLUMN_NAME', 'reverses_movement_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
