<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, §1.1 del progetto della tappa 2): l'assemblea, le sue sedute, i punti dell'ordine del giorno e di che cosa parla ogni punto.
 *
 * - `assemblee`: un'assemblea preparata nel programma o una riunione registrata a mano (decisione 492 (1)). `data` è **il giorno della riunione**, una
 *   regola sola (§13, rilievo 6), scritta solo dal servizio. `raccomandate_entro_il` è il giorno scelto dall'amministratore per spedire le raccomandate,
 *   vuoto vuol dire la proposta del programma (decisione 565). Il condominio, il perimetro e la tabella con la chiave `restrict`: un condominio con
 *   assemblee non si cancella in cascata (R15).
 * - `assemblee_sedute`: le date dell'avviso, prima e seconda convocazione e le prosecuzioni (art. 66 c. 5 disp. att.), con l'ora «come si legge» a Roma.
 * - `assemblee_punti`: il punto, con le due frasi (decisione 492 (5)), la materia, la maggioranza, l'amministrazione ordinaria o straordinaria (art. 67 c. 6
 *   e 7 disp. att.), il conduttore (art. 10 L. 392/1978), il perimetro facoltativo del punto (decisione 568) e la chiave del sospeso da cui nasce (§5).
 * - `assemblee_punti_oggetti`: le schede di cui parla un punto e quella da cui nasce, con il nome stabile del registro (§2, R6).
 *
 * Rieseguibile: su MySQL ogni tabella, ogni indice e ogni chiave esterna è uno statement a sé e il DDL non è transazionale, quindi una guardia per ognuno.
 * Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo le tabelle.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee' => [
            'assemblee_condominio_data_index' => [['condominio_id', 'data'], false],
            'assemblee_condominio_stato_index' => [['condominio_id', 'stato'], false],
        ],
        'assemblee_sedute' => [
            'assemblee_sedute_unica' => [['assemblea_id', 'convocazione', 'numero'], true],
        ],
        'assemblee_punti' => [
            'assemblee_punti_ordine_index' => [['assemblea_id', 'numero'], false],
            'assemblee_punti_chiave_fonte_index' => [['chiave_fonte'], false],
        ],
        'assemblee_punti_oggetti' => [
            'assemblee_punti_oggetti_oggetto_index' => [['oggetto_tipo', 'oggetto_id'], false],
            'assemblee_punti_oggetti_unico' => [['punto_id', 'oggetto_tipo', 'oggetto_id', 'ruolo'], true],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee' => [
            'condominio_id' => ['condomini', 'restrict'],
            'palazzina_id' => ['palazzine', 'restrict'],
            'scala_id' => ['scale', 'restrict'],
            'tabella_id' => ['tabelle', 'restrict'],
            'verbale_documento_id' => ['documenti', 'set null'],
            'comunicazione_id' => ['comunicazioni', 'set null'],
            'riconvocata_da_id' => ['assemblee', 'set null'],
            'annullata_da' => ['users', 'set null'],
            'created_by' => ['users', 'set null'],
            'updated_by' => ['users', 'set null'],
        ],
        'assemblee_sedute' => [
            'assemblea_id' => ['assemblee', 'cascade'],
        ],
        'assemblee_punti' => [
            'assemblea_id' => ['assemblee', 'cascade'],
            'palazzina_id' => ['palazzine', 'restrict'],
            'scala_id' => ['scale', 'restrict'],
            'tabella_id' => ['tabelle', 'restrict'],
            'esercizio_id' => ['esercizi', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'assemblee_punti_oggetti' => [
            'punto_id' => ['assemblee_punti', 'cascade'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee')) {
            Schema::create('assemblee', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id');
                $table->string('ambito', 20)->default('condominio')->comment('condominio, palazzina o scala (decisione 492 (14)).');
                $table->unsignedBigInteger('palazzina_id')->nullable();
                $table->unsignedBigInteger('scala_id')->nullable();
                $table->string('tipo', 20)->comment('ordinaria o straordinaria.');
                $table->string('origine', 20)->comment('programma, oppure a_mano per una riunione di carta registrata dopo.');
                $table->string('stato', 30)->comment('in_preparazione, convocata, annullata, registrata_a_mano; la sala e il verbale aggiungono i loro.');
                $table->date('data')->nullable()->comment('Il giorno della riunione (una regola sola): la scrive solo il servizio.');
                $table->string('luogo')->nullable();
                $table->string('modalita', 20)->default('presenza')->comment('presenza, videoconferenza o mista (art. 66 c. 6 disp. att.).');
                $table->string('piattaforma')->nullable();
                $table->date('consenso_videoconferenza_il')->nullable();
                $table->string('consenso_videoconferenza_modo', 500)->nullable();
                $table->unsignedBigInteger('tabella_id')->nullable()->comment('La tabella del valore.');
                $table->string('numero_verbale', 50)->nullable()->comment('Il numero del verbale di carta, per la riunione registrata a mano.');
                $table->unsignedBigInteger('verbale_documento_id')->nullable();
                $table->unsignedBigInteger('comunicazione_id')->nullable()->comment('L\'avviso in bacheca (decisione 492 (3)).');
                $table->date('affissione_dal')->nullable();
                $table->date('affissione_al')->nullable();
                $table->date('raccomandate_entro_il')->nullable()->comment('Scelto dall\'amministratore; vuoto vuol dire la proposta (decisione 565).');
                $table->unsignedBigInteger('riconvocata_da_id')->nullable()->comment('L\'assemblea annullata da cui questa nasce.');
                $table->dateTime('annullata_il')->nullable();
                $table->text('annullata_motivo')->nullable();
                $table->unsignedBigInteger('annullata_da')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_sedute')) {
            Schema::create('assemblee_sedute', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->string('convocazione', 10)->comment('prima o seconda.');
                $table->unsignedTinyInteger('numero')->default(1)->comment('1; 2, 3… per le date di prosecuzione della stessa convocazione.');
                $table->dateTime('fissata_il')->comment('L\'ora come si legge a Roma.');
                $table->string('luogo')->nullable()->comment('Solo se diverso da quello dell\'assemblea.');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_punti')) {
            Schema::create('assemblee_punti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedSmallInteger('numero');
                $table->text('testo')->comment('La frase dell\'avviso per persona.');
                $table->text('testo_pubblico')->nullable()->comment('La frase senza dati personali, per la bacheca.');
                $table->string('materia', 40);
                $table->string('maggioranza', 30)->nullable()->comment('Vuota finché l\'amministratore non sceglie, dove il programma non propone.');
                $table->json('maggioranza_libera')->nullable()->comment('La soglia di una legge speciale, con la norma.');
                $table->string('amministrazione', 20)->default('ordinaria')->comment('ordinaria o straordinaria (art. 67 c. 6 e 7 disp. att.).');
                $table->string('conduttore', 20)->nullable()->comment('vota o interviene (art. 10 L. 392/1978).');
                $table->string('ambito', 20)->nullable()->comment('Il perimetro facoltativo del punto (decisione 568).');
                $table->unsignedBigInteger('palazzina_id')->nullable();
                $table->unsignedBigInteger('scala_id')->nullable();
                $table->unsignedBigInteger('tabella_id')->nullable();
                $table->unsignedBigInteger('esercizio_id')->nullable();
                $table->bigInteger('importo')->nullable()->comment('Centesimi.');
                $table->json('dati')->nullable()->comment('I contenuti di un tipo di punto (la destinazione d\'uso: art. 1117-ter).');
                $table->string('fonte', 20)->default('a_mano')->comment('di_serie, memo, programma, nota_sintetica, a_mano.');
                $table->string('chiave_fonte', 100)->nullable()->comment('La chiave del sospeso: fattura:45, rendiconto:7, nota:7:Q5.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_punti_oggetti')) {
            Schema::create('assemblee_punti_oggetti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('punto_id');
                $table->string('oggetto_tipo', 40)->comment('Il nome stabile del registro, mai la classe.');
                $table->unsignedBigInteger('oggetto_id');
                $table->string('ruolo', 20)->comment('origine, parla_di o documento.');
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
        Schema::dropIfExists('assemblee_punti_oggetti');
        Schema::dropIfExists('assemblee_punti');
        Schema::dropIfExists('assemblee_sedute');
        Schema::dropIfExists('assemblee');
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
