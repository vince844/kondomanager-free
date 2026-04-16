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
        Schema::table('casse', function (Blueprint $table) {
            // Classificazione strutturale dei fondi
            $table->enum('sottotipo_fondo', ['generico', 'vincolato_lavori', 'tfr', 'morosita'])
                  ->nullable()
                  ->after('tipo')
                  ->comment('Natura giuridica del fondo (solo se tipo = fondo)');
                  
            $table->string('vincolo_descrizione')
                  ->nullable()
                  ->after('sottotipo_fondo')
                  ->comment('Es: Sostituzione ascensore, Rifacimento facciata');
                  
            // Tracciamento Audit dello sblocco
            $table->boolean('is_override_assemblea')
                  ->default(false)
                  ->after('vincolo_descrizione')
                  ->comment('Sblocco forzato manuale da parte dell\'amministratore (solo per fondi vincolati)');
                  
            $table->text('motivazione_override')
                  ->nullable()
                  ->after('is_override_assemblea')
                  ->comment('Audit trail: estremi delibera di sblocco o motivazione');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('casse', function (Blueprint $table) {
            $table->dropColumn([
                'sottotipo_fondo',
                'vincolo_descrizione',
                'is_override_assemblea',
                'motivazione_override'
            ]);
        });
    }
};