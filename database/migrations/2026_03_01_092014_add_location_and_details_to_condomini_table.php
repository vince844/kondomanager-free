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
        Schema::table('condomini', function (Blueprint $table) {
            // Ubicazione (inseriti subito dopo l'indirizzo esistente)
            $table->string('comune')->nullable()->after('indirizzo');
            $table->string('provincia', 2)->nullable()->after('comune');
            $table->string('cap', 5)->nullable()->after('provincia');
            
            // Dati Strutturali e Gestionali
            $table->unsignedSmallInteger('anno_costruzione')->nullable()->after('cap');
            $table->unsignedSmallInteger('anno_acquisizione')->nullable()->after('anno_costruzione');
            $table->unsignedTinyInteger('numero_piani')->nullable()->after('anno_acquisizione');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('condomini', function (Blueprint $table) {
            $table->dropColumn([
                'comune',
                'provincia',
                'cap',
                'anno_costruzione',
                'anno_acquisizione',
                'numero_piani'
            ]);
        });
    }
};
