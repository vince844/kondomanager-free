<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    private array $columns = [
        'soggetto_ritenuta',
        'perc_ritenuta',
        'perc_imponibile_ritenuta',
        'codice_tributo',
        'giorni_scadenza',
        'modalita_pagamento_default',
        'iban_principale',
    ];

    /**
     * Aggiunge i campi fiscali e di pagamento alla tabella fornitori.
     * Tutti nullable o con default per non rompere i record esistenti.
     */
    public function up(): void
    {
        $this->cleanupPartialMigration();

        Schema::table('fornitori', function (Blueprint $table) {
            $table->boolean('soggetto_ritenuta')->default(false)->after('id');
            $table->decimal('perc_ritenuta', 5, 2)->nullable()->after('soggetto_ritenuta');
            $table->decimal('perc_imponibile_ritenuta', 5, 2)->default(100)->after('perc_ritenuta');
            $table->string('codice_tributo', 10)->nullable()->after('perc_imponibile_ritenuta');
            $table->integer('giorni_scadenza')->default(30)->after('codice_tributo');
            $table->string('modalita_pagamento_default')->default('bonifico')->after('giorni_scadenza');
            $table->string('iban_principale')->nullable()->after('modalita_pagamento_default');
        });
    }

    public function down(): void
    {
        $toDrop = array_filter(
            $this->columns,
            fn($col) => Schema::hasColumn('fornitori', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('fornitori', function (Blueprint $table) use ($toDrop) {
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
            fn($col) => Schema::hasColumn('fornitori', $col)
        );

        if (empty($orphans)) {
            return;
        }

        Log::warning('Partial migration detected on [fornitori], cleaning up before re-running', [
            'table'   => 'fornitori',
            'orphans' => array_values($orphans),
        ]);

        Schema::table('fornitori', function (Blueprint $table) use ($orphans) {
            $table->dropColumn(array_values($orphans));
        });
    }
};