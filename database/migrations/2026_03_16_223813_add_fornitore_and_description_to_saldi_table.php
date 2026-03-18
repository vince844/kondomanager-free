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
            // 1. Collegamento al Fornitore
            // Nullable perché i record dei condòmini continueranno a non avere fornitore_id
            $table->foreignId('fornitore_id')
                  ->nullable()
                  ->after('anagrafica_id')
                  ->constrained('fornitori')
                  ->nullOnDelete();

            // 2. Descrizione del debito (fondamentale per la UX del dropdown)
            $table->string('descrizione')
                  ->nullable()
                  ->after('saldo_iniziale')
                  ->comment('Descrizione del debito ereditato (es: Fattura Acqua 2025)');

            // 3. Indice per velocizzare il Widget di registrazione fattura
            $table->index(['condominio_id', 'fornitore_id'], 'idx_saldi_condominio_fornitore');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('saldi', function (Blueprint $table) {
            $table->dropForeign(['fornitore_id']);
            $table->dropIndex('idx_saldi_condominio_fornitore');
            $table->dropColumn(['fornitore_id', 'descrizione']);
        });
    }
};