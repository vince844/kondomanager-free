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
        Schema::create('esercizi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')
                  ->constrained('condomini')
                  ->onDelete('cascade');
            $table->string('nome'); 
            $table->string('descrizione')->nullable();
            $table->date('data_inizio');
            $table->date('data_fine');
            $table->enum('stato', ['aperto','chiuso'])->default('aperto');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['condominio_id', 'nome']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('esercizi');
    }
};
