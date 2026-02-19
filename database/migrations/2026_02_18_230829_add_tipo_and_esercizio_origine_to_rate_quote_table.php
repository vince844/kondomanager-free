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
        Schema::table('rate_quote', function (Blueprint $table) {
            
            // 1. TIPO (Indice Veloce)
            // Ci serve per distinguere una "Rata 0" (saldo_iniziale) da una rata normale
            // senza dover aprire e leggere il JSON "regole_calcolo" di ogni riga.
            // Default 'ordinaria' protegge i dati della v1.8.
            if (!Schema::hasColumn('rate_quote', 'tipo')) {
                $table->string('tipo')
                      ->default('ordinaria')
                      ->after('stato')
                      ->index()
                      ->comment("ordinaria, saldo_iniziale, conguaglio, straordinaria");
            }

            // 2. ESERCIZIO ORIGINE (Tracciabilità Debito)
            // Se questa rata contiene arretrati, da quale esercizio provengono?
            // Fondamentale per dire al condomino: "Questi 50€ sono il saldo del 2024".
            if (!Schema::hasColumn('rate_quote', 'esercizio_origine_id')) {
                $table->foreignId('esercizio_origine_id')
                      ->nullable()
                      ->after('regole_calcolo')
                      ->constrained('esercizi')
                      ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('rate_quote', function (Blueprint $table) {
            
            if (Schema::hasColumn('rate_quote', 'esercizio_origine_id')) {
                // Prima rimuoviamo il vincolo (chiave esterna)
                $table->dropForeign(['esercizio_origine_id']);
                // Poi la colonna
                $table->dropColumn('esercizio_origine_id');
            }

            if (Schema::hasColumn('rate_quote', 'tipo')) {
                $table->dropColumn('tipo');
            }
        });
    }
};