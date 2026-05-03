<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    // Colonne aggiunte a piani_rate (usate da cleanupPartialMigration e down)
    private array $pianiRateColumns = [
        'tipo',
        'tipo_autorizzazione',
        'motivazione_autorizzazione',
    ];

    public function up(): void
    {
        // ── 1. Cleanup colonne orfane su piani_rate ───────────────────────────
        $this->cleanupPartialMigration();

        // ── 2. Aggiorna piani_rate ────────────────────────────────────────────
        Schema::table('piani_rate', function (Blueprint $table) {
            $table->enum('tipo', ['ordinario', 'straordinario'])
                  ->default('ordinario')
                  ->after('stato')
                  ->comment('ordinario = legge dal preventivo (capitoli), straordinario = legge da fatture');

            $table->enum('tipo_autorizzazione', ['delibera', 'urgenza'])
                  ->nullable()
                  ->after('tipo')
                  ->comment('Obbligatorio per piani straordinari. Scudo legale Art. 1135 c.c.');

            $table->text('motivazione_autorizzazione')
                  ->nullable()
                  ->after('tipo_autorizzazione')
                  ->comment('Audit trail: estremi delibera o motivazione urgenza');
        });

        // ── 3. Crea la pivot finanziaria (idempotente) ────────────────────────
        // Il drop preventivo garantisce che una creazione parziale precedente
        // (tabella creata ma migration non registrata) non causi errori.
        if (Schema::hasTable('piano_rate_fatture')) {
            Log::warning('Partial migration detected: [piano_rate_fatture] già esistente, dropping before re-creating', [
                'table' => 'piano_rate_fatture',
            ]);
            Schema::dropIfExists('piano_rate_fatture');
        }

        Schema::create('piano_rate_fatture', function (Blueprint $table) {
            $table->id();

            $table->foreignId('piano_rate_id')
                  ->constrained('piani_rate')
                  ->cascadeOnDelete();

            $table->foreignId('fattura_passiva_id')
                  ->constrained('fatture_passive')
                  ->cascadeOnDelete();

            // Il cuore finanziario: quanto di questa fattura sto chiedendo con questo piano
            $table->bigInteger('importo_collegato')
                  ->default(0)
                  ->comment('Quota della fattura finanziata da questo specifico piano (in centesimi)');

            // Override esplicito in fase di riscossione (utile per vecchie pendenze)
            $table->foreignId('tabella_millesimale_id')
                  ->nullable()
                  ->constrained('tabelle')
                  ->nullOnDelete()
                  ->comment('Override manuale della tabella millesimale in fase di riscossione');

            $table->integer('anno_competenza')
                  ->nullable()
                  ->comment('Anno di riferimento per il finanziamento di questa quota');

            $table->timestamps();

            // Indici per performance e integrità
            $table->unique(['piano_rate_id', 'fattura_passiva_id'], 'uq_piano_fattura');
            $table->index('piano_rate_id');
            $table->index('fattura_passiva_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('piano_rate_fatture');

        $toDrop = array_filter(
            $this->pianiRateColumns,
            fn($col) => Schema::hasColumn('piani_rate', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('piani_rate', function (Blueprint $table) use ($toDrop) {
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
            $this->pianiRateColumns,
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
            $table->dropColumn(array_values($orphans));
        });
    }
};