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
        Schema::create('rata_scrittura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rata_id')->constrained('rate')->cascadeOnDelete();
            $table->foreignId('scrittura_contabile_id')->constrained('scritture_contabili')->cascadeOnDelete();
            $table->bigInteger('importo_pagato'); 
            $table->date('data_pagamento');
            $table->timestamps();
            $table->index(['rata_id', 'created_at']);
            $table->index('scrittura_contabile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rata_scrittura');
    }
};
