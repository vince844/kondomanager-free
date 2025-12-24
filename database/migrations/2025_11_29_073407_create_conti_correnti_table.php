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
        Schema::create('conti_correnti', function (Blueprint $table) {
            $table->id();
            // Morph verso: Fornitore, Condominio, Cassa, ecc.
            $table->morphs('contable'); 
            $table->string('intestatario')->nullable();
            $table->string('iban', 34)->nullable()->index();
            $table->string('swift')->nullable();
            $table->string('istituto')->nullable();
            $table->string('indirizzo')->nullable();
            $table->string('comune')->nullable();
            $table->string('provincia', 5)->nullable();
            $table->string('cap', 10)->nullable();
            $table->string('nazione', 50)->default('Italia');
            $table->boolean('predefinito')->default(false);
            $table->enum('tipo', ['ordinario','dedicato','estero','postale','contabilita_speciale','altro'])->default('ordinario');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['contable_id', 'contable_type', 'iban']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conti_correnti');
    }
};
