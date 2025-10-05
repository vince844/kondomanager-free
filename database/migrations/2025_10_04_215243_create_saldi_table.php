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
        Schema::create('saldi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('esercizio_id')->constrained('esercizi')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->foreignId('anagrafica_id')->constrained('anagrafiche')->onDelete('cascade');
            $table->foreignId('immobile_id')->constrained('immobili')->onDelete('cascade');
            $table->bigInteger('saldo_iniziale')->default(0);
            $table->bigInteger('saldo_finale')->default(0);
            $table->enum('origine', ['manuale', 'importato', 'automatico'])->default('manuale');
            $table->timestamps();
            $table->unique(['esercizio_id', 'anagrafica_id', 'immobile_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldi');
    }
};
