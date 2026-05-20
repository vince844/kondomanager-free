<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $columns = [
        'ultimo_ricalcolo_pagamento_at',
        'versione_allocazioni',
        'inconsistenza_pagamento',
        'ultimo_errore_ricalcolo',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('fatture_passive', function (Blueprint $table) {
            // Audit del read model: timestamp dell'ultimo ricalcolo stato_pagamento.
            // Popolato da ricalcolaStatoFattura() dopo ogni operazione sulla pivot.
            $table->timestamp('ultimo_ricalcolo_pagamento_at')
                  ->nullable()
                  ->after('stato_pagamento')
                  ->comment('Timestamp ultimo ricalcolo stato_pagamento da PagamentoFornitoreService (v1.9.1)');

            // Change counter — NON è un optimistic lock.
            // Serve come token di invalidazione cache/UI: il frontend può rilevare
            // che qualcosa è cambiato sulla fattura senza riscaricare tutto.
            // BIGINT UNSIGNED per coerenza con le specifiche della guida architetturale.
            $table->unsignedBigInteger('versione_allocazioni')
                  ->default(0)
                  ->after('ultimo_ricalcolo_pagamento_at')
                  ->comment('Contatore incrementale modifiche alla pivot fattura_scrittura — non è un optimistic lock');

            // Semaforo errore sistemico. Se true, il record ha un'anomalia contabile
            // rilevata da ricalcolaStatoFattura() (es. overpayment non bloccato,
            // somma pivot incoerente). Richiede riconciliazione manuale.
            // ricalcolaStatoFattura() NON lancia eccezioni: logga critical e marca questo flag.
            $table->boolean('inconsistenza_pagamento')
                  ->default(false)
                  ->after('versione_allocazioni')
                  ->comment('True se ricalcolaStatoFattura() ha rilevato un\'anomalia — richiede riconciliazione');

            // Messaggio dell'ultimo errore critico loggato da ricalcolaStatoFattura().
            // Permette di diagnosticare l'anomalia senza dover aprire i log del server.
            $table->text('ultimo_errore_ricalcolo')
                  ->nullable()
                  ->after('inconsistenza_pagamento')
                  ->comment('Ultimo messaggio di errore da ricalcolaStatoFattura() — NULL se nessuna anomalia');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('fatture_passive', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('fatture_passive', function (Blueprint $table) use ($toDrop) {
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
            fn($col) => Schema::hasColumn('fatture_passive', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [fatture_passive], cleaning up before re-running', [
            'table'   => 'fatture_passive',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('fatture_passive', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};