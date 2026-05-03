<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * Migration v1.9 — Debiti Pregressi: Double Lock & Coperture
 *
 * Estende fatture_passive con:
 * - data_competenza_originaria  Motore Sentinella Prescrizione (5 anni)
 * - saldo_patrimoniale_id       FK al saldo in saldi[] che questa fattura estingue
 *
 * Crea fattura_coperture:
 * - Una riga per ogni fonte di copertura (supporta split misto)
 * - stato pianificata/confermata per il doppio semaforo del widget
 */
return new class extends Migration
{
    // Colonne aggiunte a fatture_passive (usate da cleanupPartialMigration e down)
    private array $fatturePassiveColumns = [
        'data_competenza_originaria',
        'saldo_patrimoniale_id',    // colonna FK — richiede dropForeign prima di dropColumn
    ];

    public function up(): void
    {
        // ── 1. Cleanup colonne orfane su fatture_passive ──────────────────────
        $this->cleanupPartialMigration();

        // ── 2. Estende fatture_passive ────────────────────────────────────────
        Schema::table('fatture_passive', function (Blueprint $table) {
            $table->date('data_competenza_originaria')
                  ->nullable()
                  ->after('is_pregresso')
                  ->index()
                  ->comment('Popolato solo se is_pregresso=true. Motore Sentinella Prescrizione 5 anni.');

            $table->foreignId('saldo_patrimoniale_id')
                  ->nullable()
                  ->after('data_competenza_originaria')
                  ->constrained('saldi')
                  ->restrictOnDelete()
                  ->comment('FK verso saldi: il debito patrimoniale che questa fattura estingue.');
        });

        // ── 3. Crea fattura_coperture (idempotente) ───────────────────────────
        if (Schema::hasTable('fattura_coperture')) {
            Log::warning('Partial migration detected: [fattura_coperture] già esistente, dropping before re-creating', [
                'table' => 'fattura_coperture',
            ]);
            Schema::dropIfExists('fattura_coperture');
        }

        Schema::create('fattura_coperture', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fattura_passiva_id')
                  ->constrained('fatture_passive')
                  ->cascadeOnDelete();

            $table->enum('tipo_copertura', [
                'rata_0',
                'sopravvenienza',
                'fondo_riserva',
            ])->index();

            $table->bigInteger('importo');

            $table->enum('stato', ['pianificata', 'confermata'])
                  ->default('pianificata')
                  ->comment('pianificata=Rata0 deliberata non incassata. confermata=liquidità reale disponibile.');

            $table->foreignId('saldo_id')
                  ->nullable()
                  ->constrained('saldi')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=rata_0. FK verso saldi del condòmino.');

            $table->foreignId('conto_id')
                  ->nullable()
                  ->constrained('conti')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=sopravvenienza. Voce preventivo corrente.');

            $table->foreignId('fondo_id')
                  ->nullable()
                  ->constrained('conti_contabili')
                  ->nullOnDelete()
                  ->comment('Valorizzato se tipo_copertura=fondo_riserva. FK verso conti_contabili fondi.');

            $table->text('nota_amministratore')
                  ->nullable()
                  ->comment('Motivazione della scelta di copertura. Alimenta Nota Sintetica rendiconto.');

            $table->timestamps();

            $table->index(['fattura_passiva_id', 'tipo_copertura'], 'idx_coperture_fattura_tipo');
            $table->index(['tipo_copertura', 'stato'], 'idx_coperture_tipo_stato');
        });
    }

    public function down(): void
    {
        // Ordine critico: prima la tabella dipendente, poi la FK sulla tabella padre
        Schema::dropIfExists('fattura_coperture');

        $toDrop = array_filter(
            $this->fatturePassiveColumns,
            fn($col) => Schema::hasColumn('fatture_passive', $col)
        );

        if (!empty($toDrop)) {
            Schema::table('fatture_passive', function (Blueprint $table) use ($toDrop) {
                // La FK va droppata esplicitamente prima della colonna
                if (in_array('saldo_patrimoniale_id', $toDrop)) {
                    $table->dropForeign(['saldo_patrimoniale_id']);
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
     * NOTA: la colonna FK (saldo_patrimoniale_id) richiede dropForeign prima di dropColumn.
     */
    private function cleanupPartialMigration(): void
    {
        $orphans = array_filter(
            $this->fatturePassiveColumns,
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
            // Droppa il constraint FK prima della colonna, altrimenti MySQL restituisce
            // "Cannot drop index: needed in a foreign key constraint"
            if (in_array('saldo_patrimoniale_id', $orphans)) {
                $table->dropForeign(['saldo_patrimoniale_id']);
            }
            $table->dropColumn(array_values($orphans));
        });
    }
};