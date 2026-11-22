<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §1.1 del progetto della tappa 2): la delibera come cerniera.
 *
 * - `delibere`: una delibera sta in un punto di una riunione, anche registrata a mano (decisione 492 (1)); `deliberata_il` è **il giorno della riunione**,
 *   il dato che la cerniera porta nelle schede. Revoca, annullamento e impugnazione sono segni con la data: nessun conto li legge (principi 4 e 5).
 * - `delibere_collegamenti`: una scheda di oggi legata a una delibera, con il ruolo (§1.4): **la delibera di una scheda sta solo qui** (§13, rilievo 22). Una
 *   delibera per ruolo per scheda: «Cambia» toglie la riga e ne scrive una nuova. La scrive solo `CollegamentiDelleDelibere`.
 *
 * La riunione, il punto e la delibera di revoca con la chiave `restrict`: una riunione con delibere non si elimina (R15, R17).
 *
 * Rieseguibile: una guardia per ogni tabella, indice e chiave esterna. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo le
 * tabelle.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'delibere' => [
            'delibere_condominio_data_index' => [['condominio_id', 'deliberata_il'], false],
            'delibere_assemblea_index' => [['assemblea_id'], false],
            'delibere_punto_index' => [['punto_id'], false],
        ],
        'delibere_collegamenti' => [
            'delibere_collegamenti_unico' => [['oggetto_tipo', 'oggetto_id', 'ruolo'], true],
            'delibere_collegamenti_delibera_index' => [['delibera_id'], false],
            'delibere_collegamenti_condominio_index' => [['condominio_id'], false],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'delibere' => [
            'condominio_id' => ['condomini', 'restrict'],
            'assemblea_id' => ['assemblee', 'restrict'],
            'punto_id' => ['assemblee_punti', 'restrict'],
            'revocata_da_delibera_id' => ['delibere', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'delibere_collegamenti' => [
            'delibera_id' => ['delibere', 'restrict'],
            'collegata_da' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('delibere')) {
            Schema::create('delibere', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id')->comment('Copiato dalla riunione, per le letture.');
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedBigInteger('punto_id');
                $table->unsignedTinyInteger('votazione')->default(1)->comment('Il rendiconto ne ha due: il rendiconto e il riparto.');
                $table->string('oggetto', 20)->nullable()->comment('rendiconto o riparto, per le due votazioni del punto del rendiconto.');
                $table->text('testo')->comment('Che cosa ha deciso.');
                $table->string('esito', 30)->comment('approvata, approvata_con_modifiche, respinta, rinviata, senza_voto (decisione 523).');
                $table->date('deliberata_il')->comment('Il giorno della riunione.');
                $table->bigInteger('importo')->nullable()->comment('Centesimi.');
                $table->date('revocata_il')->nullable();
                $table->unsignedBigInteger('revocata_da_delibera_id')->nullable();
                $table->date('annullata_il')->nullable()->comment('L\'annullamento di un giudice (principio 4), mai un errore.');
                $table->text('annullata_nota')->nullable();
                $table->date('impugnata_il')->nullable()->comment('Un segno che non ferma niente (art. 1137 c.c., principio 5).');
                $table->text('impugnata_nota')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('delibere_collegamenti')) {
            Schema::create('delibere_collegamenti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('delibera_id');
                $table->unsignedBigInteger('condominio_id')->comment('Copiato dalla delibera.');
                $table->string('oggetto_tipo', 40)->comment('La scheda, con il nome stabile del registro: mai la classe.');
                $table->unsignedBigInteger('oggetto_id');
                $table->string('ruolo', 30)->comment('delibera, riferimento, ratifica, competenza, origine_della_spesa, dispensa…');
                $table->text('nota')->nullable();
                $table->unsignedBigInteger('collegata_da')->nullable();
                $table->dateTime('collegata_il')->nullable();
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
        Schema::dropIfExists('delibere_collegamenti');
        Schema::dropIfExists('delibere');
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
