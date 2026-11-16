<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il calendario dell'accordo di rientro e la sua regola di decadenza.
 *
 * - **Il calendario**: le rate dell'accordo, una riga per rata (il numero, la data, l'importo in centesimi), in una tabella figlia dell'accordo:
 *   eliminato l'accordo, se ne vanno con lui. La somma delle rate è il debito riconosciuto (lo controlla chi registra l'accordo).
 * - **La regola di decadenza**, scelta dall'amministratore, nelle due forme che si usano negli accordi: quante rate del calendario non versate,
 *   anche non consecutive (`decadenza_rate`), e quanti giorni dopo una rata non versata (`decadenza_giorni`); una, l'altra, tutte e due o nessuna.
 * - **I segnali** (`segnali`): la prima volta che una forma della regola si verifica, il giorno e le rate che la fanno, conservati così come erano
 *   quel giorno: un versamento registrato dopo con una data di prima non li cancella.
 * - **La decadenza registrata** (`decadenza_registrata_il`): il giorno che l'amministratore ha scritto quando l'ha registrata; l'accordo è chiuso
 *   lo stesso giorno. Il programma non la registra da sé.
 *
 * Nessun riempimento: gli accordi di oggi restano senza calendario e senza regola, che è il loro vero.
 *
 * Rieseguibile: su MySQL la tabella, il suo indice unico, la sua chiave esterna e ognuna delle quattro colonne sono statement distinti (il DDL non è
 * transazionale), quindi una guardia per ognuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const UNICO = 'accordo_rientro_scadenze_numero_unico';

    public function up(): void
    {
        if (! Schema::hasTable('accordo_rientro_scadenze')) {
            Schema::create('accordo_rientro_scadenze', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('accordo_rientro_id');
                $table->unsignedSmallInteger('numero')->comment('Il numero della rata nel calendario, da 1.');
                $table->date('data')->comment('Il giorno della rata dell\'accordo.');
                $table->unsignedBigInteger('importo')->comment('In centesimi.');
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('accordo_rientro_scadenze', self::UNICO)) {
            Schema::table('accordo_rientro_scadenze', fn (Blueprint $table) => $table->unique(['accordo_rientro_id', 'numero'], self::UNICO));
        }

        if (Schema::hasTable('accordi_rientro') && ! $this->foreignKeyEsiste('accordo_rientro_scadenze', 'accordo_rientro_id')) {
            Schema::table('accordo_rientro_scadenze', fn (Blueprint $table) => $table->foreign('accordo_rientro_id', 'accordo_rientro_scadenze_accordo_rientro_id_foreign')
                ->references('id')->on('accordi_rientro')->cascadeOnDelete());
        }

        if (! Schema::hasColumn('accordi_rientro', 'decadenza_rate')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->unsignedSmallInteger('decadenza_rate')->nullable()->after('nota')
                ->comment('La regola di decadenza: quante rate del calendario non versate, anche non consecutive. Vuota: nessuna regola di questa forma.'));
        }
        if (! Schema::hasColumn('accordi_rientro', 'decadenza_giorni')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->unsignedSmallInteger('decadenza_giorni')->nullable()->after('decadenza_rate')
                ->comment('La regola di decadenza: quanti giorni dopo una rata non versata. Vuota: nessuna regola di questa forma.'));
        }
        if (! Schema::hasColumn('accordi_rientro', 'segnali')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->json('segnali')->nullable()->after('decadenza_giorni')
                ->comment('Per ogni forma della regola, il primo giorno in cui si è verificata e le rate di quel giorno, conservati.'));
        }
        if (! Schema::hasColumn('accordi_rientro', 'decadenza_registrata_il')) {
            Schema::table('accordi_rientro', fn (Blueprint $table) => $table->date('decadenza_registrata_il')->nullable()->after('chiuso_il')
                ->comment('Il giorno della decadenza registrata dall\'amministratore; l\'accordo è chiuso lo stesso giorno.'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accordo_rientro_scadenze');
        foreach (['decadenza_registrata_il', 'segnali', 'decadenza_giorni', 'decadenza_rate'] as $colonna) {
            if (Schema::hasColumn('accordi_rientro', $colonna)) {
                Schema::table('accordi_rientro', fn (Blueprint $table) => $table->dropColumn($colonna));
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
