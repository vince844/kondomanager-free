<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colonne DDL aggiunte da questa migration.
     * La conversione ENUM→VARCHAR su tipo_movimento è gestita separatamente
     * perché non è un'aggiunta ma una modifica di tipo — non rientra
     * nel pattern cleanupPartialMigration (non può esserci una colonna "orfana"
     * su tipo_movimento, esiste già).
     */
    private array $columns = [
        'idempotency_key',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();
        $this->convertTipoMovimentoToVarchar();

        Schema::table('scritture_contabili', function (Blueprint $table) {
            // Chiave idempotenza per prevenire doppi inserimenti in scenari
            // concorrenti (es. doppio click, retry su timeout di rete).
            // Unicità garantita a DB level, gestita via try/catch su QueryException.
            $table->uuid('idempotency_key')
                  ->nullable()
                  ->unique()
                  ->after('id')
                  ->comment('UUID idempotenza per prevenire scritture duplicate (v1.9.1)');
        });
    }

    /**
     * Reverse the migrations.
     *
     * NOTA: il tipo VARCHAR su tipo_movimento NON viene ripristinato a ENUM in down().
     * Motivo: durante v1.9.1 potrebbero essere stati inseriti valori nuovi
     * (es. storno_pagamento_fornitore) che non erano presenti nel ENUM originale.
     * Ricreare il ENUM senza includerli causerebbe un errore MySQL.
     * Il rollback rimuove solo le colonne aggiunte, lasciando VARCHAR in place.
     */
    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('scritture_contabili', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('scritture_contabili', function (Blueprint $table) use ($toDrop) {
                $table->dropColumn(array_values($toDrop));
            });
        }
    }

    /**
     * Converte tipo_movimento da ENUM a VARCHAR(50).
     *
     * Eseguita solo su MySQL e solo se la colonna è ancora di tipo ENUM.
     * La conversione preserva tutti i dati esistenti: ogni valore ENUM
     * è già una stringa valida in VARCHAR(50).
     *
     * Su SQLite (test environment) viene saltata: SQLite non ha un tipo ENUM
     * nativo e tratta già il valore come testo — nessuna modifica necessaria.
     *
     * Motivazione architetturale: VARCHAR(50) permette di aggiungere nuovi
     * valori (es. storno_pagamento_fornitore) modificando solo il Backed Enum
     * PHP, senza migration DDL ad ogni release.
     */
    private function convertTipoMovimentoToVarchar(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Log::info('Migration [scritture_contabili.tipo_movimento]: ambiente non-MySQL, skip conversione ENUM→VARCHAR.');
            return;
        }

        $columnInfo = DB::selectOne("
            SELECT DATA_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'scritture_contabili'
              AND COLUMN_NAME  = 'tipo_movimento'
        ");

        if (!$columnInfo || strtolower($columnInfo->DATA_TYPE) !== 'enum') {
            Log::info('Migration [scritture_contabili.tipo_movimento]: già VARCHAR(50), conversione saltata.');
            return;
        }

        Log::info('Migration [scritture_contabili.tipo_movimento]: avvio conversione ENUM→VARCHAR(50)...');
        DB::statement('ALTER TABLE scritture_contabili MODIFY COLUMN tipo_movimento VARCHAR(50) NOT NULL');
        Log::info('Migration [scritture_contabili.tipo_movimento]: conversione completata.');
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
            fn($col) => Schema::hasColumn('scritture_contabili', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [scritture_contabili], cleaning up before re-running', [
            'table'   => 'scritture_contabili',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('scritture_contabili', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};