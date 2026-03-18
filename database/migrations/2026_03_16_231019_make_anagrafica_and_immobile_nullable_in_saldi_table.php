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
        Schema::table('saldi', function (Blueprint $table) {
            // Rendiamo le colonne nullable per permettere i debiti fornitori
            $table->foreignId('anagrafica_id')->nullable()->change();
            $table->foreignId('immobile_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saldi', function (Blueprint $table) {
            // In caso di rollback, tornano obbligatorie (attenzione ai dati esistenti!)
            $table->foreignId('anagrafica_id')->nullable(false)->change();
            $table->foreignId('immobile_id')->nullable(false)->change();
        });
    }
};