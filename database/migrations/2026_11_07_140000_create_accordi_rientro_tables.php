<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.4: l'accordo di rientro, come registro. La persona, la data dell'accordo, «fino al» (la data dell'ultima rata dell'accordo),
 * «autorizzato dall'assemblea» (sì o no, senza una risposta già scelta: la colonna non ha un valore di serie), con la data e il verbale se sì,
 * una nota, la scansione firmata (un `Documento` dell'accordo); poi la chiusura, con la data e la nota. Non si cancella: si chiude.
 *
 * Le rate coperte stanno in una tabella a parte: per ognuna (rata, unità) l'importo riconosciuto e il residuo della rata il giorno dell'accordo,
 * in centesimi (il segno dice «residuo invariato» solo se il residuo di oggi è quello). Nessun calendario, nessuna decadenza, nessun
 * interesse: l'accordo non sposta denaro.
 *
 * Rieseguibile: su MySQL ogni tabella, ogni indice e ogni chiave esterna sono statement distinti (la `create table` porta solo le colonne e la
 * chiave primaria; il DDL non è transazionale), quindi una guardia per ognuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'accordi_rientro'       => ['accordi_rientro_persona_index' => [['condominio_id', 'anagrafica_id'], false]],
        'accordo_rientro_rate'  => ['accordo_rientro_rata_unica' => [['accordo_rientro_id', 'rata_id', 'immobile_id'], true]],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'accordi_rientro' => [
            'condominio_id' => ['condomini', 'cascade'],
            'anagrafica_id' => ['anagrafiche', 'cascade'],
            'registrato_da' => ['users', 'set null'],
            'chiuso_da'     => ['users', 'set null'],
        ],
        'accordo_rientro_rate' => [
            'accordo_rientro_id' => ['accordi_rientro', 'cascade'],
            'rata_id'            => ['rate', 'cascade'],
            'immobile_id'        => ['immobili', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('accordi_rientro')) {
            Schema::create('accordi_rientro', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('anagrafica_id');
                $table->date('data_accordo');
                $table->date('fino_al')->comment('La data dell\'ultima rata dell\'accordo.');
                $table->boolean('autorizzato_assemblea')->comment('Sì o no: lo sceglie l\'amministratore, senza un valore di serie.');
                $table->date('data_autorizzazione')->nullable();
                $table->string('verbale_autorizzazione', 50)->nullable();
                $table->text('nota')->nullable();
                $table->date('chiuso_il')->nullable();
                $table->text('nota_chiusura')->nullable();
                $table->unsignedBigInteger('registrato_da')->nullable();
                $table->unsignedBigInteger('chiuso_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('accordo_rientro_rate')) {
            Schema::create('accordo_rientro_rate', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('accordo_rientro_id');
                $table->unsignedBigInteger('rata_id');
                $table->unsignedBigInteger('immobile_id')->nullable();
                $table->unsignedBigInteger('importo_riconosciuto')->comment('In centesimi: la parte della rata che l\'accordo copre.');
                $table->unsignedBigInteger('residuo_iniziale')->nullable()->comment('In centesimi: il residuo della rata il giorno dell\'accordo.');
                $table->timestamps();
            });
        }
        // Una tabella creata senza la colonna del residuo del giorno dell'accordo la riceve qui: un'altra guardia, un altro statement.
        if (! Schema::hasColumn('accordo_rientro_rate', 'residuo_iniziale')) {
            Schema::table('accordo_rientro_rate', fn (Blueprint $table) => $table->unsignedBigInteger('residuo_iniziale')->nullable()->after('importo_riconosciuto')
                ->comment('In centesimi: il residuo della rata il giorno dell\'accordo.'));
        }

        foreach (self::INDICI as $tabella => $indici) {
            foreach ($indici as $nome => [$colonne, $unico]) {
                if (! Schema::hasIndex($tabella, $nome)) {
                    Schema::table($tabella, fn (Blueprint $table) => $unico ? $table->unique($colonne, $nome) : $table->index($colonne, $nome));
                }
            }
        }

        foreach (self::CHIAVI as $tabella => $chiavi) {
            foreach ($chiavi as $colonna => [$arrivo, $regola]) {
                if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste($tabella, $colonna)) {
                    Schema::table($tabella, function (Blueprint $table) use ($tabella, $colonna, $arrivo, $regola) {
                        $chiave = $table->foreign($colonna, "{$tabella}_{$colonna}_foreign")->references('id')->on($arrivo);
                        $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accordo_rientro_rate');
        Schema::dropIfExists('accordi_rientro');
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
