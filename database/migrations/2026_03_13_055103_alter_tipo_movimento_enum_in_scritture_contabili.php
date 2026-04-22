<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Enum completo con TUTTI i valori utilizzati dal sistema e dalla roadmap
            DB::statement("ALTER TABLE scritture_contabili MODIFY COLUMN tipo_movimento ENUM(
                'fattura_acquisto',
                'nota_credito_fornitore',
                'pagamento_fornitore',
                'storno_fattura',
                'emissione_rata',
                'incasso_rata',
                'storno_credito',
                'stralcio_credito',
                'rimborso_condomino',
                'apertura',
                'chiusura',
                'giroconto',
                'rettifica',
                'pagamento_f24',
                'incasso_diverso',
                'rimborso_assicurativo'
            ) NOT NULL");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback alla situazione originale base
        // ATTENZIONE: Il rollback fallirà se nel DB ci sono già record con i nuovi valori (es. storno_credito).
        // È il comportamento standard di MySQL/MariaDB per la sicurezza dei dati.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE scritture_contabili MODIFY COLUMN tipo_movimento ENUM(
                'incasso_rata', 
                'pagamento_fornitore', 
                'giroconto', 
                'rettifica', 
                'apertura', 
                'chiusura', 
                'emissione_rata',
                'fattura_acquisto'
            ) NOT NULL");
        }
    }
};