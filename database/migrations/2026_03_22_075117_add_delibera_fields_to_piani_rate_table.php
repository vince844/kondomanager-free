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
        Schema::table('piani_rate', function (Blueprint $table) {
            // Aggiungiamo i campi legali subito dopo la colonna 'stato'
            $table->date('data_delibera_assemblea')->nullable()->after('stato');
            $table->string('numero_verbale', 50)->nullable()->after('data_delibera_assemblea');
            $table->text('nota_approvazione')->nullable()->after('numero_verbale');
            
            // Aggiungiamo i campi di Audit Trail Leggero (chi e quando ha cliccato Approva)
            $table->foreignId('approvato_da_user_id')->nullable()->constrained('users')->nullOnDelete()->after('nota_approvazione');
            $table->timestamp('approvato_il')->nullable()->after('approvato_da_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('piani_rate', function (Blueprint $table) {
            // Rimuoviamo prima la foreign key per evitare errori del database
            $table->dropForeign(['approvato_da_user_id']);
            
            // Poi rimuoviamo le colonne fisiche
            $table->dropColumn([
                'data_delibera_assemblea', 
                'numero_verbale', 
                'nota_approvazione',
                'approvato_da_user_id',
                'approvato_il'
            ]);
        });
    }
};