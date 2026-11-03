<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 3 (1.11.0-beta.31): il passaggio di titolarità come **evento datato con un autore**.
 *
 * Progetto `docs/subentro_e_competenza_temporale.md` §4.4: oggi una riga di conguaglio in `saldi`
 * sarebbe indistinguibile da un saldo digitato a mano (`origine` è un ENUM a tre valori, nessun
 * `created_by`). L'operazione «Registra passaggio» scrive qui chi esce, chi entra, con che ruolo, da
 * che data, con quale titolo — e lega a sé, via `saldi.subentro_id` (migrazione 4), la coppia di righe a
 * somma zero che il piano successivo assorbe (D9). Le due righe della pivot che l'evento chiude e apre
 * sono referenziate (`riga_uscente_id`, `riga_entrante_id`): è ciò che permette alla guardia server della
 * decisione 13 di dire «questa riga ha storia, non si cancella».
 *
 * `anagrafica_uscente_id` è nullo per un inizio di locazione (nessuno esce), `anagrafica_entrante_id`
 * per una fine di locazione con unità sfitta (nessuno entra): sono i quattro tipi del §6.3 di
 * `pertinenze_vendita_locazione.md`. `nota_cancello` è la nota del cancello (1), congelata (decisione 14).
 *
 * Rieseguibile: `create` dentro `hasTable`, FK nella stessa `create`. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('subentri')) {
            return;
        }

        Schema::create('subentri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->cascadeOnDelete();
            $table->foreignId('immobile_id')->constrained('immobili')->cascadeOnDelete();
            $table->foreignId('anagrafica_uscente_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->foreignId('anagrafica_entrante_id')->nullable()->constrained('anagrafiche')->nullOnDelete();
            $table->foreignId('riga_uscente_id')->nullable()->constrained('anagrafica_immobile')->nullOnDelete()
                ->comment('La riga della pivot chiusa da questo passaggio.');
            $table->foreignId('riga_entrante_id')->nullable()->constrained('anagrafica_immobile')->nullOnDelete()
                ->comment('La riga della pivot aperta da questo passaggio.');
            $table->string('tipologia', 30)->comment('Il ruolo che passa: proprietario, inquilino, usufruttuario, nuda_proprietario.');
            $table->string('tipo_passaggio', 30)->comment('vendita · inizio_locazione · fine_locazione · usufrutto');
            $table->date('decorrenza')->comment('Il primo giorno di chi entra; chi esce è titolare fino al giorno prima compreso.');
            $table->text('estremi_titolo')->nullable()->comment('Titolo di provenienza, in chiaro: atto, rep., verbale omologato, decreto di trasferimento.');
            $table->date('copia_autentica_il')->nullable()->comment('Art. 63 co. 5 disp. att. c.c.: da questa data il cedente è liberato verso il condominio.');
            $table->text('nota')->nullable();
            $table->text('nota_cancello')->nullable()->comment('La nota del cancello (1), obbligatoria se il passaggio tocca rate emesse o cambia un destinatario.');
            $table->foreignId('utente_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['immobile_id', 'decorrenza'], 'idx_subentri_immobile_decorrenza');
            $table->index(['condominio_id', 'decorrenza'], 'idx_subentri_condominio_decorrenza');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subentri');
    }
};
