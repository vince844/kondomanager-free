<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Righe di dettaglio di ogni fattura passiva.
     *
     * immobile_id nullable: se valorizzato indica una "Spesa Personale",
     * addebitata direttamente a un singolo condòmino invece di essere
     * ripartita su tutti i millesimi.
     */
    public function up(): void
    {
        Schema::create('righe_fattura', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fattura_passiva_id')
                ->constrained('fatture_passive')
                ->cascadeOnDelete();

            $table->foreignId('conto_id')->constrained('conti');

            $table->foreignId('immobile_id')
                ->nullable()
                ->constrained('immobili')
                ->nullOnDelete();

            $table->string('descrizione');

            // Importi in centesimi, coerenti con la testata
            $table->bigInteger('importo_imponibile');
            $table->decimal('aliquota_iva', 5, 2);
            $table->bigInteger('importo_iva');

            $table->json('dati_extra')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('righe_fattura');
    }
};