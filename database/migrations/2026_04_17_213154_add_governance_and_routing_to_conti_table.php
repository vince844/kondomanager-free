<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            // 1. Asse della Ripartizione (Chi paga?)
            $table->enum('tipo_ripartizione', ['millesimale', 'custom', 'ad_personam'])
                  ->default('millesimale')
                  ->after('tipo_spesa')
                  ->comment('Determina se la spesa segue le tabelle standard (millesimale), è privata (ad_personam) o manuale (custom)');

            // 2. Asse dell'Autorizzazione (Chi lo ha deciso?)
            $table->enum('origine_decisionale', ['gestione_corrente', 'delibera_assembleare'])
                  ->default('gestione_corrente')
                  ->after('tipo_ripartizione')
                  ->comment('Distinzione legale tra amministrazione ordinaria e lavori straordinari deliberati');
        });
    }

    public function down(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->dropColumn(['tipo_ripartizione', 'origine_decisionale']);
        });
    }
};