<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'codice',
        'default_fornitore_id',   // colonna FK — richiede dropForeign prima di dropColumn
        'tipo_spesa',
    ];

    public function up(): void
    {
        $this->cleanupPartialMigration();

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
            $table->string('tipo_spesa', 30)->default('standard')->after('importo');
        });
    }

    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('conti', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('conti', function (Blueprint $table) use ($toDrop) {
                // La FK va droppata esplicitamente prima della colonna
                if (in_array('default_fornitore_id', $toDrop)) {
                    $table->dropForeign(['default_fornitore_id']);
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
     * NOTA: la colonna FK (default_fornitore_id) richiede dropForeign prima di dropColumn.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('conti', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [conti], cleaning up before re-running', [
            'table'   => 'conti',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('conti', function (Blueprint $table) use ($orphans) {
            // Droppa il constraint FK prima della colonna, altrimenti MySQL restituisce
            // "Cannot drop index: needed in a foreign key constraint"
            if (in_array('default_fornitore_id', $orphans)) {
                $table->dropForeign(['default_fornitore_id']);
            }
            $table->dropColumn(array_values($orphans));
        });
    }
};