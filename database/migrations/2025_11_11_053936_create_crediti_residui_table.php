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
        Schema::create('crediti_residui', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->foreignId('esercizio_id')->constrained('esercizi')->onDelete('cascade');
            $table->foreignId('gestione_id')->constrained('gestioni')->onDelete('cascade');
            $table->foreignId('piano_rate_id')->constrained('piani_rate')->onDelete('cascade');
            $table->bigInteger('importo')->default(0); // in centesimi
            $table->date('data_generazione');
            $table->enum('stato', ['da_compensare', 'compensato', 'annullato'])->default('da_compensare');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['anagrafica_id', 'esercizio_id', 'stato']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crediti_residui');
    }
};
