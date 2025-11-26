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
        Schema::create('piani_rate', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gestione_id')->constrained('gestioni')->onDelete('cascade');
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->string('nome');
            $table->text('descrizione')->nullable();
            $table->integer('numero_rate')->default(12);
            $table->integer('giorno_scadenza')->default(5);
            $table->enum('metodo_distribuzione', ['prima_rata', 'tutte_rate'])->default('prima_rata'); 
            $table->boolean('attivo')->default(true);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['gestione_id', 'nome']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('piani_rate');
    }
};
