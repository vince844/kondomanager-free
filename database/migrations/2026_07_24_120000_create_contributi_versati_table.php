<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger del "già versato": quanto ciascuna unità ha contribuito a una spesa
 * o a un fondo, PRIMA che la spesa venga ripartita.
 *
 * Risolve il "buco B" (docs/fondo_accantonato_e_quadratura_sp.md §1): oggi il
 * motore di riparto distribuisce l'importo lordo di una voce sui millesimi senza
 * sottrarre nulla, perché non esiste alcun dato che dica quanto un condòmino ha
 * già messo. Chi ha versato €500 nel 2025 per dei lavori se li vede richiedere
 * daccapo quando arriva la fattura.
 *
 * CHIAVE DI NETTING = `immobile_id` (obbligatorio), non l'anagrafica.
 * Il contributo è della UNITÀ: se l'unità viene venduta, il nuovo proprietario
 * eredita la copertura e i due si regolano privatamente in sede di rogito
 * (art. 63 disp. att. c.c.). `anagrafica_id` resta come traccia di CHI ha
 * versato — serve al rendiconto e alla trasparenza, non al calcolo.
 *
 * TARGET FLESSIBILE (polimorfico):
 *   - `Conto`    → contributo verso una voce di spesa specifica (fondo lavori
 *                  ex art. 1135: il vincolo è per l'opera)
 *   - `Gestione` → contributo verso una gestione nel suo insieme (fondo di
 *                  riserva generico, che non è legato a una singola opera)
 *
 * NATURA (decisione D2): distingue ciò che ha un vincolo di destinazione da ciò
 * che è semplice avanzo conguagliabile. L'art. 1130-bis c.c. richiede che il
 * rendiconto esponga separatamente "i fondi disponibili e le eventuali riserve".
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale: se l'esecuzione precedente è morta
        // dopo la CREATE ma prima di registrare la migrazione (timeout PHP o
        // del web server su hosting condiviso), la tabella esiste già e un
        // secondo tentativo fallirebbe con "Table already exists", bloccando
        // per sempre un aggiornamento che la pagina di conferma dichiara
        // riprendibile. Stessa guardia di create_backups_table.
        if (Schema::hasTable('contributi_versati')) {
            return;
        }

        Schema::create('contributi_versati', function (Blueprint $table) {
            $table->id();

            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();

            // Target polimorfico: Conto (voce di spesa) oppure Gestione.
            $table->string('target_type');
            $table->unsignedBigInteger('target_id');

            // Chiave di netting: sempre valorizzata.
            $table->foreignId('immobile_id')->constrained('immobili')->cascadeOnDelete();

            // Chi ha versato: traccia informativa, può mancare in migrazione
            // quando il vecchio gestionale riporta solo l'unità.
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->nullOnDelete();

            $table->bigInteger('importo_cents')->comment('Già versato, in centesimi');

            $table->string('natura', 30)->default('avanzo')
                ->comment('fondo_vincolato | avanzo');

            $table->string('origine', 30)->default('migrazione')
                ->comment('migrazione | incasso');

            $table->string('descrizione')->nullable();

            $table->timestamps();

            $table->index(['condominio_id', 'target_type', 'target_id'], 'cv_target_idx');
            $table->index(['immobile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contributi_versati');
    }
};
