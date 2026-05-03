<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'sottotipo_fondo',
        'vincolo_descrizione',
        'is_override_assemblea',
        'motivazione_override',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

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
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('casse', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('casse', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     * Necessario perché MySQL non supporta DDL transazionali: se la migration
     * viene interrotta a metà (es. timeout PHP), le colonne già aggiunte
     * restano nel DB senza che la migration venga registrata in `migrations`.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('casse', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [casse], cleaning up before re-running', [
            'table'   => 'casse',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('casse', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};