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
        Schema::create('quota_scrittura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rate_quota_id')->constrained('rate_quote')->cascadeOnDelete();
            $table->foreignId('scrittura_contabile_id')->constrained('scritture_contabili')->cascadeOnDelete();
            $table->bigInteger('importo_pagato');
            $table->date('data_pagamento');
            $table->timestamps();
            $table->index(['rate_quota_id', 'created_at']);
            $table->index('scrittura_contabile_id');
        });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quota_scrittura');
    }
};
