<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.4: i modelli dei testi che l'amministratore scrive e riusa. Oggi servono alle lettere ai condòmini (promemoria, sollecito,
 * diffida, messa in mora): `ambito` è `lettera` e `tipo` è il tipo della lettera. La tabella non è delle lettere sole perché un giorno ci
 * staranno anche altri testi (un altro `ambito`). Un modello ha un nome, il testo con i campi da riempire, e vale per tutti i condomini
 * (`condominio_id` nullo) o per uno solo. I testi del programma non stanno qui: sono nel codice e non si cambiano.
 *
 * Il nome è unico per ambito, tipo e condominio (o per «tutti»): lo controlla il programma, perché su MySQL un indice unico non vede due nulli
 * come uguali. Una lettera copia il testo del modello: cancellare o cambiare un modello non tocca le lettere già scritte.
 *
 * Rieseguibile: su MySQL la tabella, l'indice e ogni chiave esterna sono statement distinti (la `create table` porta solo le colonne e la chiave
 * primaria; il DDL non è transazionale), quindi una guardia per ognuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    private const INDICE = 'modelli_testo_ambito_tipo_condominio_index';

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'created_by'    => ['users', 'set null'],
        'updated_by'    => ['users', 'set null'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('modelli_testo')) {
            Schema::create('modelli_testo', function (Blueprint $table) {
                $table->id();
                $table->string('ambito', 30)->comment('A che cosa serve il modello: oggi solo «lettera».');
                $table->string('tipo', 30)->comment('Per le lettere: promemoria, sollecito, diffida, messa_in_mora.');
                $table->string('nome', 120);
                $table->text('testo');
                $table->unsignedBigInteger('condominio_id')->nullable()->comment('Nullo: il modello vale per tutti i condomini.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('modelli_testo', self::INDICE)) {
            Schema::table('modelli_testo', fn (Blueprint $table) => $table->index(['ambito', 'tipo', 'condominio_id'], self::INDICE));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('modelli_testo', $colonna)) {
                Schema::table('modelli_testo', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "modelli_testo_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('modelli_testo');
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
