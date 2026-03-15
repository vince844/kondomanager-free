<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabella Pivot per collegare le Fatture Passive alle Scritture Contabili.
     * Gestisce sia la registrazione del debito (competenza) sia i pagamenti successivi.
     */
    public function up(): void
    {
        Schema::create('fattura_scrittura', function (Blueprint $table) {
            $table->id();
            
            // Chiavi esterne
            $table->foreignId('fattura_passiva_id')
                  ->constrained('fatture_passive')
                  ->cascadeOnDelete();
                  
            $table->foreignId('scrittura_contabile_id')
                  ->constrained('scritture_contabili')
                  ->cascadeOnDelete();

            // Importo allocato in centesimi (coerente con il resto del DB)
            $table->bigInteger('importo_allocato');

            // Tipo di collegamento: 
            // - 'competenza' (quando registri la fattura)
            // - 'pagamento' (quando registri il bonifico in uscita)
            // - 'storno' (se annulli l'operazione)
            $table->string('tipo')->default('competenza');

            $table->timestamps();
            
            // Indici per velocizzare le query e i calcoli dei saldi
            $table->index(['fattura_passiva_id', 'tipo']);
            $table->index('scrittura_contabile_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fattura_scrittura');
    }
};