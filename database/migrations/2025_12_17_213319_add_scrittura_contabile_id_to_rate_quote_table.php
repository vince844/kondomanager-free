<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Eseguiamo la modifica SOLO se la tabella esiste E la colonna NON esiste
        if (Schema::hasTable('rate_quote') && !Schema::hasColumn('rate_quote', 'scrittura_contabile_id')) {
            Schema::table('rate_quote', function (Blueprint $table) {
                $table->foreignId('scrittura_contabile_id')
                    ->nullable()
                    ->after('rata_id')
                    ->constrained('scritture_contabili')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // Anche il rollback deve essere sicuro: rimuovi solo se esiste
        if (Schema::hasTable('rate_quote') && Schema::hasColumn('rate_quote', 'scrittura_contabile_id')) {
            Schema::table('rate_quote', function (Blueprint $table) {
                // È buona norma eliminare prima il vincolo foreign key, poi la colonna
                $table->dropForeign(['scrittura_contabile_id']);
                $table->dropColumn('scrittura_contabile_id');
            });
        }
    }
};