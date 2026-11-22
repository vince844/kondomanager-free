<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §1.1 e §4.1 del progetto della tappa 2): l'elenco dei convocati, fissato **all'invio** di ognuno («si convoca chi risulta
 * dall'anagrafe alla data dell'invio»): prima dell'invio l'elenco si calcola e non si scrive. Nome, indirizzo e PEC sono **copiati** all'invio, così la riga
 * resta vera se la persona cambia o sparisce. `invio_id` è l'ultimo invio con un mezzo dell'art. 66 disp. att., mai l'email semplice (§13, rilievo 15).
 * Una persona per assemblea: una riconvocazione è un'assemblea nuova, con un elenco nuovo.
 *
 * Rieseguibile: una guardia per la tabella, l'indice unico e ogni chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la
 * interrompe dopo la tabella.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee_convocati' => [
            'assemblee_convocati_unico' => [['assemblea_id', 'anagrafica_id'], true],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee_convocati' => [
            'assemblea_id' => ['assemblee', 'restrict'],
            'anagrafica_id' => ['anagrafiche', 'set null'],
            'invio_id' => ['registro_invii', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee_convocati')) {
            Schema::create('assemblee_convocati', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedBigInteger('anagrafica_id')->nullable();
                $table->string('nome')->comment('Copiato all\'invio.');
                $table->string('indirizzo')->nullable()->comment('Copiato all\'invio.');
                $table->string('pec')->nullable()->comment('Copiata all\'invio.');
                $table->json('unita')->comment('Le unità e il ruolo di allora: [{immobile_id, nome, ruolo, quota}].');
                $table->json('punti')->nullable()->comment('I punti per cui è convocato (il conduttore, decisione 566); vuoto vuol dire tutti.');
                $table->string('mezzo', 20)->comment('pec, raccomandata, a_mano o fax (art. 66 c. 3 disp. att.).');
                $table->dateTime('fissato_il');
                $table->unsignedBigInteger('invio_id')->nullable()->comment('L\'ultimo invio con un mezzo dell\'art. 66.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        foreach (self::INDICI as $tabella => $indici) {
            foreach ($indici as $nome => [$colonne, $unico]) {
                if (! Schema::hasIndex($tabella, $nome)) {
                    Schema::table($tabella, fn (Blueprint $t) => $unico ? $t->unique($colonne, $nome) : $t->index($colonne, $nome));
                }
            }
        }

        foreach (self::CHIAVI as $tabella => $chiavi) {
            foreach ($chiavi as $colonna => [$arrivo, $regola]) {
                if (Schema::hasTable($arrivo) && ! $this->chiaveEsiste($tabella, $colonna)) {
                    Schema::table($tabella, function (Blueprint $t) use ($tabella, $colonna, $arrivo, $regola) {
                        $chiave = $t->foreign($colonna, "{$tabella}_{$colonna}_foreign")->references('id')->on($arrivo);
                        match ($regola) {
                            'cascade' => $chiave->cascadeOnDelete(),
                            'set null' => $chiave->nullOnDelete(),
                            default => $chiave->restrictOnDelete(),
                        };
                    });
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assemblee_convocati');
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function chiaveEsiste(string $tabella, string $colonna): bool
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
