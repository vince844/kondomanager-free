<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La nota sintetica esplicativa del rendiconto (art. 1130-bis c.c.) mentre l'amministratore la scrive: una riga per esercizio, la bozza. Il contenuto
 * è un JSON (il testo di ogni parte, lo stato dei fatti che il programma propone, gli elenchi dei contratti e degli altri debiti certi, la dichiarazione
 * sulle liti, le spiegazioni dei controlli); chi l'ha toccata per ultimo e quando. Non sta in `esercizi.note`, che non ha struttura e che si scrive da
 * un'altra pagina. Quando il rendiconto si ferma per l'assemblea la nota si copia nella versione, com'è quel giorno: dopo, la bozza cambia e la
 * versione no.
 *
 * La bozza è dell'esercizio: eliminato l'esercizio (o il condominio), se ne va con lui. Chi l'ha scritta può essere eliminato: resta la nota.
 *
 * Rieseguibile: la tabella, l'indice unico e ogni chiave esterna sono statement distinti su MySQL (il DDL non è transazionale), una guardia ciascuno.
 */
return new class extends Migration
{
    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'esercizio_id' => ['esercizi', 'cascade'],
        'aggiornata_da' => ['users', 'set null'],
    ];

    private const UNICO = 'note_sintetiche_esercizio_unica';

    public function up(): void
    {
        if (! Schema::hasTable('note_sintetiche')) {
            Schema::create('note_sintetiche', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->unsignedBigInteger('esercizio_id');
                $table->json('contenuto')->nullable()->comment('Le parti, lo stato dei fatti, gli elenchi, la dichiarazione sulle liti, le spiegazioni dei controlli.');
                $table->unsignedBigInteger('aggiornata_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('note_sintetiche', self::UNICO)) {
            Schema::table('note_sintetiche', fn (Blueprint $table) => $table->unique(['esercizio_id'], self::UNICO));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('note_sintetiche', $colonna)) {
                Schema::table('note_sintetiche', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "note_sintetiche_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('note_sintetiche');
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
