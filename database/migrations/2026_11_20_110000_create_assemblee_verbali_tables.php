<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, il verbale): il registro dei verbali, il verbale di ogni riunione con il suo testo e le sue versioni, il registro di nomina e
 * revoca (art. 1130 n. 7 c.c.).
 *
 * - `assemblee_registri_verbali`: un registro dei verbali per perimetro (il condominio, e la scala o la palazzina per le loro assemblee: hanno la loro
 *   numerazione), con il punto di partenza la prima volta: continua il libro di carta (l'ultimo numero, il giorno facoltativo) o comincia dal n. 1. Si
 *   cambia finché il registro non ha un verbale registrato.
 * - `assemblee_verbali`: il verbale di una riunione (una seduta aperta); lo stato (`in_sala` mentre si scrive la discussione, `bozza`, `registrato`,
 *   `annullato`), il modello da cui è generato, l'impronta dei dati della sala letti alla generazione, la bozza stampata, il numero nel registro, il giorno
 *   della firma, la copia sottoscritta, l'annullamento con il perché, la registrazione annullata che sostituisce, il giorno in cui la pagina è incollata nel
 *   libro di carta, e le scelte dell'amministratore su chi lo riceve. Il numero è unico nel registro: un numero annullato non torna.
 * - `assemblee_verbali_parti`: il testo di adesso, per parte (`intero`, `punto:<id>` per la discussione di un punto): la struttura dell'editor in JSON, mai
 *   HTML; la revisione, il dispositivo che l'ha scritta e quelli delle ultime revisioni (per le due versioni), l'ora del gesto.
 * - `assemblee_verbali_versioni`: le versioni di una parte, con il perché, l'impronta, l'ora del gesto; quelle in attesa di una scelta (due versioni, o arrivata
 *   dopo la registrazione) con la scelta.
 * - `registro_nomine`: le date della nomina e della revoca di ogni amministratore e gli estremi del decreto (art. 1130 n. 7 c.c.); la riga proposta da una
 *   delibera finché non è confermata; una riga sbagliata si annulla con il perché, non si cancella.
 *
 * Le chiavi verso il condominio, l'assemblea, la seduta, il punto, la delibera e la copia sottoscritta sono `restrict`: un verbale registrato non se ne va.
 * Rieseguibile: su MySQL ogni tabella, indice e chiave esterna è uno statement a sé e il DDL non è transazionale, quindi una guardia per ognuno. Nel dataset
 * di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo le tabelle.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee_registri_verbali' => [
            'assemblee_registri_verbali_unico' => [['condominio_id', 'registro'], true],
        ],
        'assemblee_verbali' => [
            'assemblee_verbali_numero_unico' => [['condominio_id', 'registro', 'numero'], true],
            'assemblee_verbali_assemblea_index' => [['assemblea_id', 'stato'], false],
        ],
        'assemblee_verbali_parti' => [
            'assemblee_verbali_parti_unica' => [['verbale_id', 'parte'], true],
        ],
        'assemblee_verbali_versioni' => [
            'assemblee_verbali_versioni_parte_index' => [['verbale_id', 'parte', 'numero'], false],
        ],
        'registro_nomine' => [
            'registro_nomine_condominio_dal_index' => [['condominio_id', 'dal'], false],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee_registri_verbali' => [
            'condominio_id' => ['condomini', 'restrict'],
            'palazzina_id' => ['palazzine', 'restrict'],
            'scala_id' => ['scale', 'restrict'],
            'created_by' => ['users', 'set null'],
            'updated_by' => ['users', 'set null'],
        ],
        'assemblee_verbali' => [
            'assemblea_id' => ['assemblee', 'restrict'],
            'condominio_id' => ['condomini', 'restrict'],
            'seduta_id' => ['assemblee_sedute', 'restrict'],
            'modello_id' => ['modelli_testo', 'set null'],
            'documento_id' => ['documenti', 'restrict'],
            'registrato_da' => ['users', 'set null'],
            'annullato_da' => ['users', 'set null'],
            'sostituisce_id' => ['assemblee_verbali', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'assemblee_verbali_parti' => [
            'verbale_id' => ['assemblee_verbali', 'restrict'],
            'punto_id' => ['assemblee_punti', 'restrict'],
            'aggiornata_da' => ['users', 'set null'],
        ],
        'assemblee_verbali_versioni' => [
            'verbale_id' => ['assemblee_verbali', 'restrict'],
            'scelta_da' => ['users', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'registro_nomine' => [
            'condominio_id' => ['condomini', 'restrict'],
            'delibera_id' => ['delibere', 'restrict'],
            'verbale_id' => ['assemblee_verbali', 'set null'],
            'documento_id' => ['documenti', 'set null'],
            'confermata_da' => ['users', 'set null'],
            'annullata_da' => ['users', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee_registri_verbali')) {
            Schema::create('assemblee_registri_verbali', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->string('registro', 40)->comment('condominio, scala:<id> o palazzina:<id>: le assemblee della sola scala hanno il loro registro.');
                $table->unsignedBigInteger('palazzina_id')->nullable();
                $table->unsignedBigInteger('scala_id')->nullable();
                $table->string('partenza', 20)->comment('libro (continua il libro di carta) o da_uno (comincia dal n. 1).');
                $table->unsignedInteger('ultimo_del_libro')->nullable()->comment('L\'ultimo numero del libro dei verbali di carta.');
                $table->date('libro_del')->nullable()->comment('Il giorno dell\'ultimo verbale di carta, facoltativo: lo riconosce nella stampa.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_verbali')) {
            Schema::create('assemblee_verbali', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedBigInteger('condominio_id')->comment('Copiato dall\'assemblea, per il registro e il numero unico.');
                $table->string('registro', 40)->comment('Il registro del perimetro dell\'assemblea: condominio, scala:<id>, palazzina:<id>.');
                $table->unsignedBigInteger('seduta_id')->comment('La riunione che racconta: un verbale per riunione.');
                $table->string('stato', 20)->comment('in_sala, bozza, registrato, annullato.');
                $table->unsignedBigInteger('modello_id')->nullable();
                $table->string('modello_nome')->nullable()->comment('Il nome del modello, copiato.');
                $table->char('impronta_sala', 64)->nullable()->comment('L\'impronta dei dati della sala letti alla generazione.');
                $table->dateTime('generato_il')->nullable();
                $table->json('stampata')->nullable()->comment('La bozza da firmare: la versione, l\'impronta del PDF, quando, chi.');
                $table->unsignedInteger('numero')->nullable()->comment('Il numero nel registro.');
                $table->date('sottoscritto_il')->nullable();
                $table->unsignedBigInteger('documento_id')->nullable()->comment('La copia sottoscritta, documento dell\'assemblea.');
                $table->dateTime('registrato_il')->nullable();
                $table->unsignedBigInteger('registrato_da')->nullable();
                $table->dateTime('annullato_il')->nullable();
                $table->text('annullato_motivo')->nullable();
                $table->unsignedBigInteger('annullato_da')->nullable();
                $table->unsignedBigInteger('sostituisce_id')->nullable()->comment('La registrazione annullata che questa sostituisce.');
                $table->date('nel_libro_il')->nullable()->comment('Il giorno in cui la pagina è incollata nel libro di carta: un promemoria.');
                $table->json('trasmissione')->nullable()->comment('Chi l\'amministratore toglie o aggiunge alla lista proposta, con il perché.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_verbali_parti')) {
            Schema::create('assemblee_verbali_parti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('verbale_id');
                $table->string('parte', 30)->comment('intero, o punto:<id> per la discussione di un punto.');
                $table->unsignedBigInteger('punto_id')->nullable();
                $table->json('documento')->comment('La struttura dell\'editor, validata: mai HTML.');
                $table->unsignedInteger('revisione')->default(0);
                $table->string('dispositivo', 64)->nullable()->comment('Chi ha scritto l\'ultima revisione.');
                $table->json('scrittori')->nullable()->comment('Le ultime revisioni con il loro dispositivo: due versioni solo se dopo la base ha scritto un altro.');
                $table->dateTime('scritta_il')->nullable()->comment('L\'ora del gesto.');
                $table->unsignedBigInteger('aggiornata_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_verbali_versioni')) {
            Schema::create('assemblee_verbali_versioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('verbale_id');
                $table->string('parte', 30);
                $table->unsignedInteger('numero');
                $table->json('documento');
                $table->char('impronta', 64);
                $table->string('perche', 200);
                $table->string('dispositivo', 64)->nullable();
                $table->char('gesto', 36)->nullable();
                $table->dateTime('segnata_il')->comment('L\'ora del gesto che ha scritto questo testo.');
                $table->boolean('in_attesa')->default(false)->comment('Una versione che aspetta la scelta, o arrivata dopo la registrazione.');
                $table->string('attesa', 30)->nullable()->comment('due_versioni o dopo_la_registrazione.');
                $table->dateTime('scelta_il')->nullable();
                $table->unsignedBigInteger('scelta_da')->nullable();
                $table->string('scelta', 20)->nullable()->comment('questa, quella, uniscile, vista.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('registro_nomine')) {
            Schema::create('registro_nomine', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->string('evento', 20)->comment('nomina o revoca.');
                $table->string('amministratore');
                $table->date('dal');
                $table->string('da_dove', 20)->comment('carta (una delibera su carta), verbale (un verbale registrato qui), decreto.');
                $table->string('estremi')->nullable()->comment('«verbale n. 36 del 10/04/2025, nel libro di carta», «verbale n. 42, punto 6».');
                $table->unsignedBigInteger('delibera_id')->nullable();
                $table->unsignedBigInteger('verbale_id')->nullable();
                $table->json('decreto')->nullable()->comment('{autorita, numero, data}.');
                $table->unsignedBigInteger('documento_id')->nullable();
                $table->text('nota')->nullable();
                $table->string('stato', 20)->comment('proposta (dalla delibera, finché non la confermi), confermata, annullata.');
                $table->dateTime('confermata_il')->nullable();
                $table->unsignedBigInteger('confermata_da')->nullable();
                $table->dateTime('annullata_il')->nullable();
                $table->text('annullata_motivo')->nullable();
                $table->unsignedBigInteger('annullata_da')->nullable();
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
        Schema::dropIfExists('registro_nomine');
        Schema::dropIfExists('assemblee_verbali_versioni');
        Schema::dropIfExists('assemblee_verbali_parti');
        Schema::dropIfExists('assemblee_verbali');
        Schema::dropIfExists('assemblee_registri_verbali');
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
