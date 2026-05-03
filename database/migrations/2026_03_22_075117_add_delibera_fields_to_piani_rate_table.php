<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'data_delibera_assemblea',
        'numero_verbale',
        'nota_approvazione',
        'approvato_da_user_id',   // colonna FK — richiede dropForeign prima di dropColumn
        'approvato_il',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('piani_rate', function (Blueprint $table) {
            // Campi legali per l'approvazione deliberata dell'assemblea
            $table->date('data_delibera_assemblea')->nullable()->after('stato');
            $table->string('numero_verbale', 50)->nullable()->after('data_delibera_assemblea');
            $table->text('nota_approvazione')->nullable()->after('numero_verbale');

            // Audit Trail: chi ha cliccato "Approva" e quando
            $table->foreignId('approvato_da_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->after('nota_approvazione');

            $table->timestamp('approvato_il')->nullable()->after('approvato_da_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('piani_rate', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('piani_rate', function (Blueprint $table) use ($toDrop) {
                // La FK va droppata esplicitamente prima della colonna
                if (in_array('approvato_da_user_id', $toDrop)) {
                    $table->dropForeign(['approvato_da_user_id']);
                }
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     * Necessario perché MySQL non supporta DDL transazionali: se la migration
     * viene interrotta a metà (es. timeout PHP), le colonne già aggiunte
     * restano nel DB senza che la migration venga registrata in `migrations`.
     *
     * NOTA: la colonna FK (approvato_da_user_id) richiede dropForeign prima di dropColumn.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('piani_rate', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [piani_rate], cleaning up before re-running', [
            'table'   => 'piani_rate',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('piani_rate', function (Blueprint $table) use ($orphans) {
            // Droppa il constraint FK prima della colonna, altrimenti MySQL restituisce
            // "Cannot drop index: needed in a foreign key constraint"
            if (in_array('approvato_da_user_id', $orphans)) {
                $table->dropForeign(['approvato_da_user_id']);
            }
            $table->dropColumn(array_values($orphans));
        });
    }
};