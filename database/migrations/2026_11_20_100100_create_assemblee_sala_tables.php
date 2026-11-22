<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): le deleghe, le presenze, le votazioni e i voti, le dichiarazioni a verbale, il libro dei gesti della sala.
 *
 * - `assemblee_deleghe`: una delega per l'assemblea, che vale per tutte le sue date. `arrivata_come`: `in_sala`, `carta_prima` («Deleghe ricevute»), e
 *   `portale` quando le manderà il portale dei condòmini, senza migrazioni nuove (la richiesta e la firma in `dati`). `stato`: `valida`, `rifiutata`,
 *   `revocata`; `motivo` il rifiuto con l'articolo, o la scelta del presidente con il suo motivo. In `dati` anche il conto del quinto al momento.
 * - `assemblee_presenze`: un intervallo per riga (chi entra, come, quando esce); chi esce e rientra ha due righe; chi è presente per delega non ha righe
 *   sue. `chiave`: `anagrafica:<id>` per una persona dell'anagrafica, `delega:<id>` per un delegato fuori dall'anagrafica.
 * - `assemblee_votazioni`: un voto su un punto (due per il rendiconto: il rendiconto e il riparto), con gli intervenuti fermati al momento del voto, i
 *   conteggi, l'esito proposto e quello confermato, la delibera che ne nasce. Un solo voto per seduta, punto e numero.
 * - `assemblee_voti`: il voto di una testa, con i millesimi in centomillesimi; un voto non si registra mai due volte (unico su votazione e testa).
 * - `assemblee_dichiarazioni`: la breve dichiarazione a verbale (art. 1130 n. 7 c.c.).
 * - `assemblee_gesti`: ogni gesto della sala con il suo numero (unico: un gesto che arriva due volte conta una volta), il dispositivo, l'ora del gesto e
 *   quella del dispositivo, l'arrivo, l'esito e, per le due versioni, il conflitto e la scelta.
 *
 * La riunione e la seduta con la chiave `restrict`: un'assemblea tenuta non si elimina. Rieseguibile: su MySQL ogni tabella, indice e chiave esterna è
 * uno statement a sé e il DDL non è transazionale, quindi una guardia per ognuno. Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la
 * interrompe dopo le tabelle.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'assemblee_deleghe' => [
            'assemblee_deleghe_assemblea_stato_index' => [['assemblea_id', 'stato'], false],
            'assemblee_deleghe_delegante_index' => [['delegante_anagrafica_id'], false],
        ],
        'assemblee_presenze' => [
            'assemblee_presenze_seduta_chiave_index' => [['seduta_id', 'chiave'], false],
        ],
        'assemblee_votazioni' => [
            'assemblee_votazioni_unica' => [['seduta_id', 'punto_id', 'numero'], true],
        ],
        'assemblee_voti' => [
            'assemblee_voti_unico' => [['votazione_id', 'testa'], true],
        ],
        'assemblee_dichiarazioni' => [
            'assemblee_dichiarazioni_seduta_index' => [['seduta_id'], false],
        ],
        'assemblee_gesti' => [
            'assemblee_gesti_gesto_unico' => [['gesto'], true],
            'assemblee_gesti_assemblea_arrivo_index' => [['assemblea_id', 'arrivato_il'], false],
        ],
    ];

    /** Tabella => [colonna => [tabella di arrivo, regola alla cancellazione]]. */
    private const CHIAVI = [
        'assemblee_deleghe' => [
            'assemblea_id' => ['assemblee', 'restrict'],
            'delegante_anagrafica_id' => ['anagrafiche', 'set null'],
            'delegato_anagrafica_id' => ['anagrafiche', 'set null'],
            'documento_id' => ['documenti', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'assemblee_presenze' => [
            'seduta_id' => ['assemblee_sedute', 'restrict'],
            'anagrafica_id' => ['anagrafiche', 'set null'],
        ],
        'assemblee_votazioni' => [
            'seduta_id' => ['assemblee_sedute', 'restrict'],
            'punto_id' => ['assemblee_punti', 'restrict'],
            'confermata_da' => ['users', 'set null'],
            'delibera_id' => ['delibere', 'set null'],
        ],
        'assemblee_voti' => [
            'votazione_id' => ['assemblee_votazioni', 'cascade'],
            'anagrafica_id' => ['anagrafiche', 'set null'],
        ],
        'assemblee_dichiarazioni' => [
            'seduta_id' => ['assemblee_sedute', 'restrict'],
            'punto_id' => ['assemblee_punti', 'restrict'],
            'anagrafica_id' => ['anagrafiche', 'set null'],
            'created_by' => ['users', 'set null'],
        ],
        'assemblee_gesti' => [
            'assemblea_id' => ['assemblee', 'restrict'],
            'scelto_da' => ['users', 'set null'],
            'utente_id' => ['users', 'set null'],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('assemblee_deleghe')) {
            Schema::create('assemblee_deleghe', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->unsignedBigInteger('delegante_anagrafica_id')->nullable();
                $table->string('delegante_nome')->comment('Il nome di chi delega, copiato.');
                $table->json('unita')->comment('Le unità per cui delega, dalla fotografia o dai titolari del giorno.');
                $table->unsignedBigInteger('delegato_anagrafica_id')->nullable()->comment('Vuoto per una persona fuori dall\'anagrafica.');
                $table->string('delegato_nome');
                $table->json('punti')->nullable()->comment('I punti, per la delega del conduttore; vuoto vuol dire tutti.');
                $table->string('arrivata_come', 20)->comment('in_sala, carta_prima; portale quando la manderà il portale dei condòmini.');
                $table->date('ricevuta_il')->comment('Il giorno in cui lo studio l\'ha ricevuta.');
                $table->dateTime('segnata_il')->nullable()->comment('L\'ora del gesto in sala; vuota per quelle ricevute prima.');
                $table->unsignedBigInteger('documento_id')->nullable()->comment('La scansione, facoltativa.');
                $table->string('stato', 20)->comment('valida, rifiutata, revocata.');
                $table->text('motivo')->nullable()->comment('Il rifiuto con l\'articolo, o la scelta del presidente con il suo motivo.');
                $table->dateTime('revocata_il')->nullable();
                $table->json('dati')->nullable()->comment('Il conto del quinto al momento; ciò che porterà il portale (la richiesta, la firma).');
                $table->char('gesto', 36)->nullable()->comment('Il numero del gesto, se segnata in sala.');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_presenze')) {
            Schema::create('assemblee_presenze', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seduta_id');
                $table->string('chiave', 60)->comment('anagrafica:<id>, o delega:<id> per un delegato fuori dall\'anagrafica.');
                $table->unsignedBigInteger('anagrafica_id')->nullable();
                $table->string('nome')->comment('Il condòmino, il delegato, il conduttore: copiato.');
                $table->string('come', 20)->comment('in_sala o in_collegamento.');
                $table->dateTime('entrata_il');
                $table->dateTime('uscita_il')->nullable();
                $table->boolean('caduto')->default(false)->comment('L\'uscita è il collegamento caduto.');
                $table->json('rappresenta')->nullable()->comment('Le unità in comproprietà che rappresenta: [immobile_id, …].');
                $table->text('motivo_collegamento')->nullable()->comment('Perché è in collegamento, quando l\'avviso non lo prevede.');
                $table->char('gesto_entrata', 36)->nullable();
                $table->char('gesto_uscita', 36)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_votazioni')) {
            Schema::create('assemblee_votazioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seduta_id');
                $table->unsignedBigInteger('punto_id');
                $table->unsignedTinyInteger('numero')->default(1)->comment('2 per il riparto del rendiconto.');
                $table->string('oggetto', 20)->nullable()->comment('rendiconto o riparto, per le due votazioni del punto del rendiconto.');
                $table->string('maggioranza', 30)->nullable()->comment('Copiata dal punto al momento del voto.');
                $table->json('maggioranza_libera')->nullable();
                $table->dateTime('votata_il')->comment('Il momento di «Si vota»: chi entra dopo non conta.');
                $table->json('intervenuti')->comment('Fermati al momento del voto: teste, chi vota, millesimi in centomillesimi.');
                $table->json('scelte')->nullable()->comment('Le scelte del presidente sul punto: l\'usufruttuario che vota, con il perché.');
                $table->json('conteggi')->nullable();
                $table->string('esito_proposto', 30)->nullable();
                $table->string('esito', 30)->nullable()->comment('Confermato dal presidente.');
                $table->text('motivo')->nullable()->comment('Perché l\'esito confermato non è quello proposto.');
                $table->dateTime('confermata_il')->nullable();
                $table->unsignedBigInteger('confermata_da')->nullable();
                $table->boolean('conflitto_determinante')->default(false);
                $table->unsignedBigInteger('delibera_id')->nullable();
                $table->unsignedInteger('versione')->default(1);
                $table->char('gesto', 36)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_voti')) {
            Schema::create('assemblee_voti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('votazione_id');
                $table->string('testa', 60)->comment('La chiave della testa nella fotografia.');
                $table->unsignedBigInteger('anagrafica_id')->nullable();
                $table->string('nome')->comment('Chi vota, copiato.');
                $table->string('ruolo', 20)->comment('proprietario, usufruttuario, nudo_proprietario, conduttore, rappresentante, delegato.');
                $table->bigInteger('millesimi')->comment('Centomillesimi.');
                $table->string('voto', 12)->comment('favorevole, contrario, astenuto, discorde.');
                $table->string('decisione', 12)->nullable()->comment('Per il voto discorde, quando decide il presidente.');
                $table->boolean('in_conflitto')->default(false);
                $table->text('nota_conflitto')->nullable();
                $table->unsignedInteger('versione')->default(1);
                $table->char('gesto', 36)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_dichiarazioni')) {
            Schema::create('assemblee_dichiarazioni', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seduta_id');
                $table->unsignedBigInteger('punto_id')->nullable();
                $table->unsignedBigInteger('anagrafica_id')->nullable();
                $table->string('nome');
                $table->text('testo');
                $table->char('gesto', 36)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('assemblee_gesti')) {
            Schema::create('assemblee_gesti', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('assemblea_id');
                $table->char('gesto', 36)->comment('Il numero del gesto, scritto dal dispositivo.');
                $table->string('dispositivo', 64);
                $table->string('tipo', 40);
                $table->json('dati');
                $table->unsignedInteger('base')->nullable()->comment('La versione da cui il gesto è partito.');
                $table->dateTime('segnato_il')->comment('L\'ora del gesto, corretta con lo scarto dell\'orologio.');
                $table->dateTime('orologio')->nullable()->comment('L\'ora del dispositivo, com\'era.');
                $table->dateTime('arrivato_il');
                $table->string('esito', 20)->comment('applicato, in_conflitto, rifiutato.');
                $table->string('messaggio', 500)->nullable();
                $table->json('conflitto')->nullable()->comment('Le due versioni.');
                $table->dateTime('scelto_il')->nullable();
                $table->unsignedBigInteger('scelto_da')->nullable();
                $table->unsignedBigInteger('utente_id')->nullable();
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
        Schema::dropIfExists('assemblee_gesti');
        Schema::dropIfExists('assemblee_dichiarazioni');
        Schema::dropIfExists('assemblee_voti');
        Schema::dropIfExists('assemblee_votazioni');
        Schema::dropIfExists('assemblee_presenze');
        Schema::dropIfExists('assemblee_deleghe');
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
