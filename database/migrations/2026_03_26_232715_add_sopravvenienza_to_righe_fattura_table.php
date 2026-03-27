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
        Schema::table('righe_fattura', function (Blueprint $table) {
            // Rende conto_id nullable: le righe sopravvenienza non hanno voce di preventivo
            $table->foreignId('conto_id')->nullable()->change();
            // Flag per identificare la riga come spesa imprevista
            $table->boolean('is_sopravvenienza')->default(false)->after('conto_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('righe_fattura', function (Blueprint $table) {
            $table->dropColumn('is_sopravvenienza');
            // Ripristina conto_id come obbligatorio (attenzione: fallirà se ci sono record con conto_id a null)
            $table->foreignId('conto_id')->nullable(false)->change();
        });
    }
};