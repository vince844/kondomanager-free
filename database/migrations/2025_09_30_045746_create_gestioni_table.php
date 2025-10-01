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
        Schema::create('gestioni', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->string('nome'); 
            $table->string('descrizione')->nullable();
            $table->enum('tipo', ['ordinaria', 'straordinaria'])->default('ordinaria');
            $table->boolean('attiva')->default(true);
            $table->date('data_inizio')->nullable();
            $table->date('data_fine')->nullable();
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
        Schema::dropIfExists('gestioni');
    }
};
