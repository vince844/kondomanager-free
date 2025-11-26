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
        Schema::create('piani_conti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('condominio_id')->constrained('condomini')->onDelete('cascade');
            $table->foreignId('gestione_id')->constrained('gestioni')->onDelete('cascade');
            $table->string('nome');
            $table->text('descrizione')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('piani_conti');
    }
};
