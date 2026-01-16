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
        Schema::create('anagrafica_fornitore', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fornitore_id')->constrained('fornitori')->onDelete('cascade');
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->enum('ruolo', ['titolare','amministrativo','commerciale','tecnico','referente','altro'])->default('referente');
            $table->boolean('referente_principale')->default(false);
            $table->timestamps();
            $table->unique(['fornitore_id', 'anagrafica_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('anagrafica_fornitore');
    }
};
