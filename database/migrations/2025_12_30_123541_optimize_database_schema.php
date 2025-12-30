<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Indici per performance su RATE_QUOTE
        Schema::table('rate_quote', function (Blueprint $table) {
            // Velocizza il recupero debiti per persona/immobile
            $table->index(['anagrafica_id', 'immobile_id', 'stato'], 'idx_quote_ana_imm_stato');
            // Velocizza query scadenze
            $table->index(['data_scadenza', 'stato'], 'idx_quote_scadenza');
        });

        // 2. Indici per performance su RIGHE_SCRITTURE
        Schema::table('righe_scritture', function (Blueprint $table) {
            // Velocizza calcolo saldi conti
            $table->index(['conto_contabile_id', 'tipo_riga'], 'idx_righe_conto_tipo');
            // Velocizza estratto conto condomino
            $table->index(['anagrafica_id', 'immobile_id'], 'idx_righe_ana_imm');
        });

        // 3. Indici per performance su SCRITTURE_CONTABILI
        Schema::table('scritture_contabili', function (Blueprint $table) {
            // Velocizza filtri per data
            $table->index(['condominio_id', 'data_registrazione'], 'idx_scritture_data');
        });
    }

    public function down(): void
    {
        Schema::table('rate_quote', function (Blueprint $table) {
            $table->dropIndex('idx_quote_ana_imm_stato');
            $table->dropIndex('idx_quote_scadenza');
        });
        Schema::table('righe_scritture', function (Blueprint $table) {
            $table->dropIndex('idx_righe_conto_tipo');
            $table->dropIndex('idx_righe_ana_imm');
        });
        Schema::table('scritture_contabili', function (Blueprint $table) {
            $table->dropIndex('idx_scritture_data');
        });
        
        // Drop constraint manuale (syntax specifica DB)
        // DB::statement("ALTER TABLE rate_quote DROP CONSTRAINT check_importo_positivo");
    }
};