<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'tipo_ripartizione',
        'origine_decisionale',
    ];

    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('conti', function (Blueprint $table) {
            // 1. Asse della Ripartizione (Chi paga?)
            $table->enum('tipo_ripartizione', ['millesimale', 'custom', 'ad_personam'])
                  ->default('millesimale')
                  ->after('tipo_spesa')
                  ->comment('Determina se la spesa segue le tabelle standard (millesimale), è privata (ad_personam) o manuale (custom)');

            // 2. Asse dell'Autorizzazione (Chi lo ha deciso?)
            $table->enum('origine_decisionale', ['gestione_corrente', 'delibera_assembleare'])
                  ->default('gestione_corrente')
                  ->after('tipo_ripartizione')
                  ->comment('Distinzione legale tra amministrazione ordinaria e lavori straordinari deliberati');
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
            $table->dropColumn(array_values($orphans));
        });
    }
};