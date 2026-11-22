<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.13 (Coda 679, decisione 518): il registro delle modifiche. Chi ha cambiato che cosa e quando, con il valore di prima e quello di dopo, per
 * tutto il programma; nessuno lo modifica o lo cancella.
 *
 * Quattro tabelle, **nessuna chiave esterna**: cancellato un condominio, un utente o una persona, le righe restano con i nomi copiati (la lezione della
 * Coda ㉗, dove l'eliminazione era proprio l'atto da raccontare).
 *  - `registro_modifiche`, una riga per modifica o per gesto. `sequenza`, `impronta_precedente` e `impronta` nascono vuote e le scrive il sigillo dopo il
 *    commit ({@see \App\Services\RegistroModifiche\SigilloDelRegistro}); `impronta_valori` nasce con la riga, così l'oscuramento cambia i valori senza
 *    rompere la catena; `firma` nasce con la riga, un HMAC con una chiave derivata da `APP_KEY` ({@see \App\Services\RegistroModifiche\FirmaDelRegistro}):
 *    una riga aggiunta fuori dal programma non ce l'ha giusta, e il sigillo non la mette in catena.
 *  - `registro_modifiche_condomini`, i condomini della scheda nel momento della riga (una persona in due condomini, un fornitore della rubrica); vuota per
 *    ciò che è dello studio.
 *  - `registro_modifiche_soggetti`, le persone (anagrafica, fornitore, utente) di cui la riga contiene dati: serve all'oscuramento.
 *  - `registro_modifiche_catena`, una riga sola: l'ultima sequenza e l'ultima impronta, la serratura del sigillo, e l'esito dell'ultima verifica.
 *
 * Rieseguibile: ogni tabella, la colonna della firma, ogni indice e la riga della catena hanno la loro guardia (su MySQL sono statement distinti e il DDL non
 * è transazionale). Così la rieseguita aggiunge anche la firma e l'indice dell'azione a tabelle nate prima di loro. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Tabella => [nome dell'indice => [colonne, unico]]. */
    private const INDICI = [
        'registro_modifiche' => [
            'registro_modifiche_sequenza_unique' => [['sequenza'], true],
            'registro_modifiche_oggetto_index' => [['oggetto_tipo', 'oggetto_id'], false],
            'registro_modifiche_scheda_index' => [['scheda_tipo', 'scheda_id'], false],
            'registro_modifiche_autore_index' => [['autore_id'], false],
            'registro_modifiche_area_index' => [['area', 'avvenuta_il'], false],
            'registro_modifiche_avvenuta_index' => [['avvenuta_il'], false],
            // I ripristini di una copia, letti a ogni «Storia» e a ogni pagina del registro (LetturaDelRegistro::annullamento()).
            'registro_modifiche_azione_index' => [['azione', 'sequenza'], false],
        ],
        'registro_modifiche_condomini' => [
            'registro_modifiche_condomini_condominio_index' => [['condominio_id', 'riga_id'], false],
        ],
        'registro_modifiche_soggetti' => [
            'registro_modifiche_soggetti_soggetto_index' => [['soggetto_tipo', 'soggetto_id'], false],
        ],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('registro_modifiche')) {
            Schema::create('registro_modifiche', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sequenza')->nullable()->comment('Il posto nella catena, dato dal sigillo dopo il commit.');
                $table->dateTime('avvenuta_il')->comment('L\'ora del gesto (UTC).');
                $table->dateTime('registrata_il')->comment('L\'ora in cui la riga è arrivata (UTC): uguale, salvo la sala senza rete.');
                $table->string('autore_tipo', 20)->comment('persona o programma.');
                $table->unsignedBigInteger('autore_id')->nullable()->comment('L\'utente in sessione, copiato; nessuna chiave esterna.');
                $table->string('autore_nome')->comment('Il nome copiato, o «Il programma».');
                $table->string('autore_ruolo')->nullable()->comment('Il ruolo di serie dell\'utente in quel momento.');
                $table->string('origine', 30)->comment('pagina, comando, pianificato, importazione, ripristino.');
                $table->string('origine_dettaglio')->nullable()->comment('«comando lanciato da terminale: …», «Importazione da Danea avviata da …».');
                $table->string('area', 30);
                $table->string('oggetto_tipo', 60)->comment('Un nome stabile del tipo, mai la classe PHP.');
                $table->unsignedBigInteger('oggetto_id')->nullable();
                $table->string('oggetto_nome')->comment('Il nome copiato: «Anagrafica di Ida Romano».');
                $table->string('scheda_tipo', 60)->nullable()->comment('In quale «Storia» compare la riga, se non in quella dell\'oggetto.');
                $table->unsignedBigInteger('scheda_id')->nullable();
                $table->string('azione', 30);
                $table->string('riepilogo', 500)->nullable();
                $table->text('perche')->nullable();
                $table->json('campi')->nullable()->comment('[{chiave, etichetta, prima, dopo, valore_prima, valore_dopo, segreto}]');
                $table->json('rimando')->nullable()->comment('{tipo, id, etichetta}');
                $table->json('lotto')->nullable()->comment('Per i gesti: i conteggi per tipo e, fino a 500, gli id toccati.');
                $table->char('impronta_valori', 64)->comment('sha256 dei valori in JSON canonico.');
                $table->char('impronta_precedente', 64)->nullable();
                $table->char('impronta', 64)->nullable();
                $table->dateTime('oscurata_il')->nullable();
                $table->unsignedBigInteger('oscurata_da')->nullable()->comment('L\'id della riga «oscuramento».');
            });
        }

        // La firma con la sua guardia, anche sulla tabella appena creata: una sola strada per le installazioni nuove e per quelle con la tabella di prima.
        if (! Schema::hasColumn('registro_modifiche', 'firma')) {
            Schema::table('registro_modifiche', function (Blueprint $table) {
                $table->char('firma', 64)->nullable()->after('impronta_valori')
                    ->comment('HMAC-SHA256 della riga nata dal programma, con una chiave derivata da APP_KEY.');
            });
        }

        if (! Schema::hasTable('registro_modifiche_condomini')) {
            Schema::create('registro_modifiche_condomini', function (Blueprint $table) {
                $table->unsignedBigInteger('riga_id');
                $table->unsignedBigInteger('condominio_id');
                $table->string('condominio_nome');
                $table->primary(['riga_id', 'condominio_id']);
            });
        }

        if (! Schema::hasTable('registro_modifiche_soggetti')) {
            Schema::create('registro_modifiche_soggetti', function (Blueprint $table) {
                $table->unsignedBigInteger('riga_id');
                $table->string('soggetto_tipo', 20)->comment('anagrafica, fornitore o utente.');
                $table->unsignedBigInteger('soggetto_id');
                $table->primary(['riga_id', 'soggetto_tipo', 'soggetto_id']);
            });
        }

        if (! Schema::hasTable('registro_modifiche_catena')) {
            Schema::create('registro_modifiche_catena', function (Blueprint $table) {
                $table->unsignedBigInteger('id')->primary();
                $table->unsignedBigInteger('ultima_sequenza')->default(0);
                $table->char('ultima_impronta', 64)->nullable();
                $table->dateTime('verificata_il')->nullable()->comment('L\'ultima «Verifica l\'integrità».');
                $table->boolean('verifica_integro')->nullable();
                $table->unsignedBigInteger('verifica_righe')->nullable();
                $table->dateTime('verifica_dal')->nullable()->comment('L\'ora della prima riga verificata.');
                $table->unsignedBigInteger('verifica_riga_id')->nullable()->comment('La prima riga che non torna.');
                $table->unsignedInteger('verifica_righe_che_non_tornano')->nullable();
            });
        }

        foreach (self::INDICI as $tabella => $indici) {
            foreach ($indici as $nome => [$colonne, $unico]) {
                if (! Schema::hasIndex($tabella, $nome)) {
                    Schema::table($tabella, fn (Blueprint $table) => $unico ? $table->unique($colonne, $nome) : $table->index($colonne, $nome));
                }
            }
        }

        // La riga della catena: una sola, scritta solo se manca (rieseguita non tocca la catena già avanzata).
        DB::table('registro_modifiche_catena')->insertOrIgnore(['id' => 1, 'ultima_sequenza' => 0, 'ultima_impronta' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('registro_modifiche_catena');
        Schema::dropIfExists('registro_modifiche_soggetti');
        Schema::dropIfExists('registro_modifiche_condomini');
        Schema::dropIfExists('registro_modifiche');
    }
};
