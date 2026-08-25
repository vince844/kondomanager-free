<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rende facoltativi tre campi che il programma pretendeva senza averne bisogno.
 *
 * ## Origine
 *
 * Segnalazioni di un amministratore sul forum, 18/08/2026: la descrizione di un documento è
 * dichiarata «(Opzionale)» a video e pretesa dalla validazione; la descrizione di un'unità è
 * obbligatoria senza ragione; l'interno è obbligatorio anche per un posto auto esterno, dove
 * quel dato non esiste.
 *
 * ## Perché serve una migrazione, contro l'apparenza
 *
 * Sul database MySQL condiviso dallo sviluppo `immobili.descrizione` risulta **nullable**, e la
 * correzione sembrava quindi una riga di validazione. È una **deriva**: nessuna migrazione lo
 * dichiara, e su un'installazione nuova — cioè su ogni cliente — la colonna nasce `NOT NULL`
 * (`2025_08_06_124021_create_immobili_table.php:21-22`, `2025_06_04_195729_create_documenti_table.php:17`).
 * Togliere `required` senza toccare lo schema avrebbe fatto fallire l'inserimento, e il controller
 * inghiotte l'eccezione: l'utente avrebbe visto un errore generico e nessuna unità creata. Lo ha
 * scoperto la suite, che gira su SQLite costruito dalle migrazioni e non dal database di sviluppo.
 *
 * ## Cosa NON fa
 *
 * Non tocca **nessun dato esistente**: chi ha una descrizione la tiene, chi ha un interno lo tiene.
 * Allenta il vincolo e basta. `interno` continua a ricevere stringa vuota invece di `null` quando il
 * modulo non lo valorizza, perché le request lo convertono con `(string)`: la colonna nullable serve
 * a chi scrive dal codice, l'interfaccia resta coerente con sé stessa.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotente per colonna: se una delle tre è già nullable il `change()` la riscrive
        // identica, quindi rieseguire la migrazione non rompe niente (dataset di
        // `tests/Feature/System/UpgradeMigrationsRerunTest.php`).
        if (Schema::hasColumn('immobili', 'descrizione')) {
            Schema::table('immobili', function (Blueprint $table) {
                $table->string('descrizione')->nullable()->change();
            });
        }

        if (Schema::hasColumn('immobili', 'interno')) {
            Schema::table('immobili', function (Blueprint $table) {
                $table->string('interno')->nullable()->change();
            });
        }

        if (Schema::hasColumn('documenti', 'description')) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->text('description')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Il ritorno indietro è volutamente parziale: rimettere `NOT NULL` su colonne che nel
        // frattempo possono contenere `null` farebbe fallire la migrazione inversa su un database
        // vero. Si riporta il vincolo solo dove non ci sono valori nulli.
        if (Schema::hasColumn('immobili', 'descrizione')
            && ! \Illuminate\Support\Facades\DB::table('immobili')->whereNull('descrizione')->exists()) {
            Schema::table('immobili', function (Blueprint $table) {
                $table->string('descrizione')->nullable(false)->change();
            });
        }

        if (Schema::hasColumn('immobili', 'interno')
            && ! \Illuminate\Support\Facades\DB::table('immobili')->whereNull('interno')->exists()) {
            Schema::table('immobili', function (Blueprint $table) {
                $table->string('interno')->nullable(false)->change();
            });
        }

        if (Schema::hasColumn('documenti', 'description')
            && ! \Illuminate\Support\Facades\DB::table('documenti')->whereNull('description')->exists()) {
            Schema::table('documenti', function (Blueprint $table) {
                $table->text('description')->nullable(false)->change();
            });
        }
    }
};
