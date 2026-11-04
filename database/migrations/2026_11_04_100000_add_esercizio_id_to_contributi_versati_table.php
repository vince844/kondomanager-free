<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.1 (Coda 438, decisioni 183.1 e 187): il già versato sa **per quale esercizio** vale.
 *
 * Sulla gestione usata in più esercizi (così la crea l'importatore, `CondominioService::createDefaultGestione`) il già versato di una voce si toglieva
 * in tutti i piani della voce, di ogni esercizio: il piano dell'anno dopo sulla stessa voce si fermava sulla guardia, o lo toglieva una seconda volta.
 * `esercizio_id` lo dice: un esercizio vuol dire «solo nei piani di quell'esercizio»; vuoto vuol dire «in tutti i piani della voce», com'era prima (il
 * fondo lavori su più anni, l'acconto di un anno e il saldo dell'altro). Lo sceglie l'amministratore dalla pagina del già versato («Vale per»).
 *
 * La migrazione aggiunge la colonna e la chiave esterna e **non riempie le righe già salvate**: restano vuote, cioè valgono per tutti i piani della voce,
 * come prima, e nessuna cifra cambia. Dare l'esercizio per regola indovinerebbe: una gestione straordinaria con un esercizio solo che continua l'anno
 * dopo (un fondo lavori) toglierebbe il già versato al primo anno e non al saldo del secondo. Dove una gestione è portata nell'anno dopo la guardia
 * ferma il piano con un messaggio che dice dove indicare l'esercizio, e lo decide l'amministratore.
 *
 * La chiave esterna è `nullOnDelete`: un esercizio che si elimina non deve fermare l'eliminazione del condominio (che scende a cascata) né quella del
 * condominio dimostrativo; la guardia sull'eliminazione dell'esercizio con un già versato suo è dell'applicazione.
 *
 * Rieseguibile: colonna e chiave esterna sono **due statement** su MySQL (il DDL non è transazionale), quindi due guardie separate — `hasColumn` per la
 * colonna, `information_schema`/`PRAGMA` per il vincolo —. Nessun dato si riscrive. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contributi_versati') || ! Schema::hasTable('esercizi')) {
            return;
        }

        if (! Schema::hasColumn('contributi_versati', 'esercizio_id')) {
            Schema::table('contributi_versati', function (Blueprint $table) {
                $table->unsignedBigInteger('esercizio_id')->nullable()->after('anagrafica_id')
                    ->comment('L\'esercizio per cui vale il già versato: solo i piani di quell\'esercizio lo tolgono. Nullo: tutti i piani della voce.');
            });
        }

        if (! $this->foreignKeyEsiste('contributi_versati', 'esercizio_id')) {
            Schema::table('contributi_versati', function (Blueprint $table) {
                $table->foreign('esercizio_id', 'contributi_versati_esercizio_id_foreign')->references('id')->on('esercizi')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contributi_versati', 'esercizio_id')) {
            return;
        }
        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('contributi_versati', 'esercizio_id')) {
            Schema::table('contributi_versati', fn (Blueprint $table) => $table->dropForeign('contributi_versati_esercizio_id_foreign'));
        }
        Schema::table('contributi_versati', function (Blueprint $table) use ($mysql) {
            if (! $mysql) {
                // SQLite non sa togliere una FK per nome: la forma per colonne ricostruisce la tabella.
                $table->dropForeign(['esercizio_id']);
            }
            $table->dropColumn('esercizio_id');
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
