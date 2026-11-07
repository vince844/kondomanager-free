<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.4: la dispensa dell'assemblea dall'agire entro sei mesi (art. 1129 c. 9 c.c.), come la registra l'amministratore: la data della
 * delibera, il numero del verbale (il file è un `Documento` della dispensa), gli esercizi coperti, le persone (tutte, o alcune se la delibera
 * lo dice), una nota; poi, se c'è, la revoca con la sua data e la sua nota. Il programma la mostra accanto alle date dei sei mesi e non
 * ricalcola niente: le date non cambiano mai per una dispensa. Non è l'accordo di rientro.
 *
 * Tre tabelle: la dispensa, gli esercizi coperti, le persone (vuota quando la dispensa è per tutti, `tutte_le_persone`).
 *
 * Rieseguibile: su MySQL ogni tabella, ogni indice unico e ogni chiave esterna sono statement distinti (la `create table` porta solo le colonne
 * e la chiave primaria; il DDL non è transazionale), quindi una guardia per ognuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice unico => colonne]. */
    private const INDICI = [
        'dispensa_sei_mesi_esercizio'  => ['dispensa_esercizio_unica' => ['dispensa_id', 'esercizio_id']],
        'dispensa_sei_mesi_anagrafica' => ['dispensa_anagrafica_unica' => ['dispensa_id', 'anagrafica_id']],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'dispense_sei_mesi' => [
            'condominio_id' => ['condomini', 'cascade'],
            'registrata_da' => ['users', 'set null'],
            'revocata_da'   => ['users', 'set null'],
        ],
        'dispensa_sei_mesi_esercizio' => [
            'dispensa_id'  => ['dispense_sei_mesi', 'cascade'],
            'esercizio_id' => ['esercizi', 'cascade'],
        ],
        'dispensa_sei_mesi_anagrafica' => [
            'dispensa_id'   => ['dispense_sei_mesi', 'cascade'],
            'anagrafica_id' => ['anagrafiche', 'cascade'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('dispense_sei_mesi')) {
            Schema::create('dispense_sei_mesi', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->date('data_delibera')->comment('La data dell\'assemblea che ha deliberato la dispensa.');
                $table->string('numero_verbale', 50)->nullable();
                $table->text('nota')->nullable();
                $table->boolean('tutte_le_persone')->default(true)->comment('Falso quando la delibera dispensa solo per alcune persone.');
                $table->date('revocata_il')->nullable()->comment('La data della revoca, se l\'assemblea l\'ha revocata.');
                $table->text('nota_revoca')->nullable();
                $table->unsignedBigInteger('registrata_da')->nullable();
                $table->unsignedBigInteger('revocata_da')->nullable();
                $table->timestamps();
            });
        }

        foreach (['dispensa_sei_mesi_esercizio' => 'esercizio_id', 'dispensa_sei_mesi_anagrafica' => 'anagrafica_id'] as $tabella => $colonna) {
            if (! Schema::hasTable($tabella)) {
                Schema::create($tabella, function (Blueprint $table) use ($colonna) {
                    $table->id();
                    $table->unsignedBigInteger('dispensa_id');
                    $table->unsignedBigInteger($colonna);
                    $table->timestamps();
                });
            }
        }

        foreach (self::INDICI as $tabella => $indici) {
            foreach ($indici as $nome => $colonne) {
                if (! Schema::hasIndex($tabella, $nome)) {
                    Schema::table($tabella, fn (Blueprint $table) => $table->unique($colonne, $nome));
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
        Schema::dropIfExists('dispensa_sei_mesi_anagrafica');
        Schema::dropIfExists('dispensa_sei_mesi_esercizio');
        Schema::dropIfExists('dispense_sei_mesi');
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
