<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le versioni del rendiconto: un rendiconto fermato in un giorno per l'assemblea, con le sue cifre e il suo PDF. La bozza (il rendiconto vivo delle
 * schede) non si conserva. «Ferma per l'assemblea» crea una versione **presentata**, con un numero per esercizio (si può fermare più di una volta: una
 * convocazione rinviata); «Registra l'approvazione» la fa **approvata**, con la data della delibera, il numero del verbale, il verbale (un
 * `Documento`), una nota e se l'assemblea ha approvato anche lo stato di riparto, sì o no (vuoto finché non lo si dice). Una seconda approvazione
 * dello stesso esercizio fa la prima **sostituita**, e la tiene.
 *
 * Le colonne:
 * - `forma`: il numero della forma delle cifre (`RendicontoVersione::FORMA`); le cifre di una forma si leggono con le chiavi di quella forma;
 * - `cifre`: le cifre fermate, in JSON (`longText`: nel condominio grande sono circa 0,8 MB);
 * - `nota`: la nota sintetica com'era quel giorno, in JSON;
 * - `calcolato_il`: il giorno e l'ora del calcolo; il giorno è la data di emissione di ogni parte del PDF;
 * - `pdf`: i documenti conservati, in JSON, con le loro impronte SHA-256 e le parti;
 * - `verbale_documento_id`, `creata_da`, `approvata_da`: lasciano vuoto se il documento o l'utente si eliminano.
 *
 * Il documento di riferimento è il PDF conservato, legato alla versione come `Documento` (`documentable`): le cifre servono alla chiusura e al confronto.
 *
 * **Le chiavi verso l'esercizio e verso il condominio non cancellano in cascata** (`restrict`): un esercizio, o un condominio, con un rendiconto
 * conservato non si elimina. Il condominio dimostrativo toglie le sue versioni a mano, prima del condominio.
 *
 * Rieseguibile: la tabella, l'indice unico e ogni chiave esterna sono statement distinti su MySQL (il DDL non è transazionale), una guardia ciascuno.
 */
return new class extends Migration
{
    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'restrict'],
        'esercizio_id' => ['esercizi', 'restrict'],
        'verbale_documento_id' => ['documenti', 'set null'],
        'creata_da' => ['users', 'set null'],
        'approvata_da' => ['users', 'set null'],
    ];

    private const UNICO = 'rendiconto_versioni_numero_unico';

    public function up(): void
    {
        if (! Schema::hasTable('rendiconto_versioni')) {
            Schema::create('rendiconto_versioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('esercizio_id');
                $table->unsignedInteger('numero')->comment('Il numero della versione nell\'esercizio: 1, 2, …');
                $table->string('stato', 20)->comment('presentata, approvata, sostituita');
                $table->unsignedSmallInteger('forma')->comment('Il numero della forma delle cifre.');
                $table->longText('cifre')->comment('Le cifre fermate, in JSON.');
                $table->longText('nota')->nullable()->comment('La nota sintetica com\'era il giorno della versione, in JSON.');
                $table->dateTime('calcolato_il');
                $table->date('data_delibera')->nullable();
                $table->string('numero_verbale', 50)->nullable();
                $table->boolean('riparto_approvato')->nullable()->comment('Se l\'assemblea ha approvato anche lo stato di riparto; vuoto finché non lo si dice.');
                $table->text('nota_approvazione')->nullable();
                $table->json('pdf')->nullable()->comment('I documenti conservati, con le impronte e le parti.');
                $table->unsignedBigInteger('verbale_documento_id')->nullable();
                $table->unsignedBigInteger('creata_da')->nullable();
                $table->unsignedBigInteger('approvata_da')->nullable();
                $table->dateTime('approvata_il')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('rendiconto_versioni', self::UNICO)) {
            Schema::table('rendiconto_versioni', fn (Blueprint $table) => $table->unique(['esercizio_id', 'numero'], self::UNICO));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('rendiconto_versioni', $colonna)) {
                Schema::table('rendiconto_versioni', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "rendiconto_versioni_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'restrict' ? $chiave->restrictOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rendiconto_versioni');
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
