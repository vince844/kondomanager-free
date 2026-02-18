<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            // Ordine e Codifica
            $table->string('codice', 20)->nullable()->after('nome')->index(); // Es. "A.1", "1020"

            // Intelligenza Fornitori (Suggeritore)
            $table->foreignId('default_fornitore_id')
                  ->nullable()
                  ->after('piano_conto_id')
                  ->constrained('fornitori')
                  ->nullOnDelete();

            // Intelligenza Fiscale (Predisposizione v1.9)
            // Valori previsti: 'standard', 'professionista', 'lavori', 'utenza'
            $table->string('tipo_spesa', 30)->default('standard')->after('importo'); 
        });
    }

    public function down(): void
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->dropForeign(['default_fornitore_id']);
            $table->dropColumn(['codice', 'default_fornitore_id', 'tipo_spesa']);
        });
    }
};