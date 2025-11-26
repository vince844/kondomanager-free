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
        Schema::create('esercizio_gestione', function (Blueprint $table) {
            $table->id();
            $table->foreignId('esercizio_id')->constrained('esercizi')->onDelete('cascade');
            $table->foreignId('gestione_id')->constrained('gestioni')->onDelete('cascade');
            $table->boolean('attiva')->default(true);
            $table->date('data_inizio')->nullable();
            $table->date('data_fine')->nullable();
            $table->timestamps();
            $table->unique(['esercizio_id', 'gestione_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esercizio_gestione');
    }
};
