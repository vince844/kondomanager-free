<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fornitori', function (Blueprint $table) {
            $table->id();
            // Identità aziendale
            $table->string('ragione_sociale');
            $table->string('partita_iva', 20)->nullable()->index();
            $table->string('codice_fiscale', 20)->nullable()->index();
            // Sede legale
            $table->string('indirizzo')->nullable();
            $table->string('cap', 10)->nullable();
            $table->string('comune')->nullable();
            $table->string('provincia', 5)->nullable();
            $table->string('nazione', 50)->default('Italia');
            // Dati societari
            $table->string('iscrizione_cciaa')->nullable();
            $table->date('data_iscrizione_cciaa')->nullable();
            $table->string('codice_ateco')->nullable();
            $table->string('numero_iscrizione_ordine')->nullable();
            $table->string('tipologia_ordine')->nullable();
            $table->foreignId('categoria_id')->nullable()->constrained('categorie_fornitore') ->nullOnDelete();
            $table->boolean('certificazione_iso')->default(false);
            $table->bigInteger('capitale_sociale')->nullable();
            // Contatti
            $table->string('telefono')->nullable();
            $table->string('cellulare')->nullable();
            $table->string('fax')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('pec')->nullable()->index();
            $table->string('sito_web')->nullable();
            // Stato operativo
            $table->enum('stato', ['attivo', 'sospeso', 'cessato'])->default('attivo');
            $table->text('note')->nullable();
            // Codici bancari SEPA / CBI
            $table->string('codice_sia')->nullable();
            $table->string('codice_cuc')->nullable();
            $table->string('codice_sepa')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fornitori');
    }
};
