<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'fornitore_id',   // FK → richiede dropForeign prima di dropColumn
        'descrizione',
    ];

    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('saldi', function (Blueprint $table) {
            $table->foreignId('fornitore_id')
                  ->nullable()
                  ->after('anagrafica_id')
                  ->constrained('fornitori')
                  ->nullOnDelete();

            $table->string('descrizione')
                  ->nullable()
                  ->after('saldo_iniziale')
                  ->comment('Descrizione del debito ereditato (es: Fattura Acqua 2025)');

            $table->index(['condominio_id', 'fornitore_id'], 'idx_saldi_condominio_fornitore');
        });
    }

    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('saldi', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('saldi', function (Blueprint $table) use ($toDrop) {
                if (in_array('fornitore_id', $toDrop)) {
                    $table->dropForeign(['fornitore_id']);

                    $indexExists = DB::select("
                        SELECT COUNT(*) as cnt
                        FROM information_schema.STATISTICS
                        WHERE table_schema = DATABASE()
                        AND table_name = 'saldi'
                        AND index_name = 'idx_saldi_condominio_fornitore'
                    ");
                    if ($indexExists[0]->cnt > 0) {
                        $table->dropIndex('idx_saldi_condominio_fornitore');
                    }
                }
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     * NOTA: fornitore_id è FK — richiede dropForeign + dropIndex prima di dropColumn.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('saldi', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [saldi], cleaning up before re-running', [
            'table'   => 'saldi',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('saldi', function (Blueprint $table) use ($orphans) {
            if (in_array('fornitore_id', $orphans)) {
                $table->dropForeign(['fornitore_id']);

                // L'indice potrebbe non esistere se il timeout è avvenuto
                // dopo ADD COLUMN ma prima di ADD INDEX
                $indexExists = DB::select("
                    SELECT COUNT(*) as cnt
                    FROM information_schema.STATISTICS
                    WHERE table_schema = DATABASE()
                    AND table_name = 'saldi'
                    AND index_name = 'idx_saldi_condominio_fornitore'
                ");
                if ($indexExists[0]->cnt > 0) {
                    $table->dropIndex('idx_saldi_condominio_fornitore');
                }
            }
            $table->dropColumn(array_values($orphans));
        });
    }
};