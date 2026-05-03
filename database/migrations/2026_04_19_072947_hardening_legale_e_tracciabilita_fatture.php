<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * =============================================================================
 * KONDOMANAGER v1.9.23 — HARDENING LEGALE E TRACCIABILITÀ FATTURE
 * =============================================================================
 *
 * Questa migrazione introduce 4 modifiche strutturali coordinate che abilitano:
 *
 * 1. DNA LEGALE DELLE QUOTE (rate_quote)
 *    Distinzione tra quote condominiali (certe, ingiungibili) e ad personam
 *    (contestabili, richiedenti diffida). Preparazione per il modulo Recupero
 *    Crediti (v1.11) con macchina a stati sul piano legale.
 *
 * 2. TRACCIABILITÀ FATTURA → QUOTA (rate_quote.riga_fattura_id)
 *    FK diretta dalla quota alla singola riga fattura che l'ha generata.
 *    Puntiamo alla RIGA e non alla testata perché una fattura mista ha righe
 *    comuni e righe private — puntare alla riga garantisce tracciabilità
 *    millimetrica (es. Ft.73 con €73,20 comune + €61,00 privato).
 *
 * 3. CONTO TECNICO (conti.is_tecnico)
 *    Flag per distinguere i conti generati automaticamente dal sistema
 *    (sopravvenienze) da quelli deliberati in assemblea (preventivo).
 *    I conti tecnici sono INVISIBILI al wizard del piano ordinario.
 *    Rispetto Art. 1130-bis c.c. — il preventivo deliberato non viene alterato.
 *
 * 4. FLAG DASHBOARD (righe_fattura.is_rateizzata)
 *    Spegne l'allarme "fattura scoperta" quando la riga è stata inclusa in un
 *    piano rate attivo. Sostituisce le fragili query `whereNotExists` con un
 *    filtro indicizzato performante.
 *
 * 5. CLASSIFICAZIONE PIANI (piani_rate.contesto_creazione)
 *    Distingue i piani nati dal preventivo iniziale da quelli nati
 *    dall'integrazione Dashboard o dalla creazione manuale. Utile per il
 *    reporting e l'audit trail.
 *
 * RETROCOMPATIBILITÀ:
 * La migrazione include data migration per v1.8 (senza fatture) e v1.9
 * esistente. Tutte le colonne nuove hanno default sicuri — il codice
 * applicativo pre-esistente continua a funzionare senza modifiche.
 *
 * =============================================================================
 */
return new class extends Migration
{
    // ── Colonne per tabella (usate da cleanup e down) ─────────────────────────

    private array $contiColumns = [
        'is_tecnico',
    ];

    private array $rateQuoteColumns = [
        'origine_tipo',
        'stato_legale',
        'stato_legale_aggiornato_at',
        'riga_fattura_id',   // FK → richiede dropForeign prima di dropColumn
        'voce_id',           // FK → richiede dropForeign prima di dropColumn
    ];

    private array $righeFatturaColumns = [
        'is_rateizzata',
    ];

    private array $pianiRateColumns = [
        'contesto_creazione',
    ];

    public function up(): void
    {
        // ── Cleanup esecuzioni parziali precedenti ────────────────────────────
        $this->cleanupPartialMigration();

        // ---------------------------------------------------------------------
        // 1. CONTI — Il Conto Tecnico (Shadow Account)
        // ---------------------------------------------------------------------
        Schema::table('conti', function (Blueprint $table) {
            $table->boolean('is_tecnico')
                  ->default(false)
                  ->after('tipo_ripartizione')
                  ->comment('True per conti generati on-the-fly (sopravvenienze e relativo capitolo padre). Invisibili al preventivo ordinario.');
        });

        // ---------------------------------------------------------------------
        // 2. RATE_QUOTE — DNA Legale della Quota
        // ---------------------------------------------------------------------
        Schema::table('rate_quote', function (Blueprint $table) {
            $table->enum('origine_tipo', ['condominiale', 'ad_personam'])
                  ->default('condominiale')
                  ->after('tipo')
                  ->comment('condominiale = ripartita millesimalmente; ad_personam = 100% al proprietario (Art. 63 disp. att. c.c.)');

            $table->enum('stato_legale', ['certo', 'contestabile', 'diffidato', 'ingiungibile'])
                  ->default('certo')
                  ->after('origine_tipo')
                  ->comment('Evoluzione: certo (default) → contestabile (ad personam) → diffidato (post lettera) → ingiungibile (post decreto)');

            $table->timestamp('stato_legale_aggiornato_at')
                  ->nullable()
                  ->after('stato_legale')
                  ->comment('Timestamp dell\'ultimo cambio di stato_legale. Essenziale per il modulo Recupero Crediti.');

            $table->foreignId('riga_fattura_id')
                  ->nullable()
                  ->after('immobile_id')
                  ->constrained('righe_fattura')
                  ->nullOnDelete()
                  ->comment('FK alla riga fattura che ha generato questa quota. NULL per quote da preventivo ordinario.');

            $table->foreignId('voce_id')
                  ->nullable()
                  ->after('riga_fattura_id')
                  ->constrained('conti')
                  ->nullOnDelete()
                  ->comment('FK al conto (voce di spesa) da cui deriva la quota. Popolato in Fase 2 con refactor CalcoloQuoteService.');

            $table->index(['anagrafica_id', 'stato_legale'], 'idx_recupero_crediti');
        });

        // ---------------------------------------------------------------------
        // 3. RIGHE_FATTURA — Flag Semaforo Dashboard
        // ---------------------------------------------------------------------
        Schema::table('righe_fattura', function (Blueprint $table) {
            $table->boolean('is_rateizzata')
                  ->default(false)
                  ->after('is_sopravvenienza')
                  ->comment('True quando questa riga è inclusa in un piano rate attivo. Spegne l\'allarme Dashboard.');

            $table->index('is_rateizzata');
        });

        // ---------------------------------------------------------------------
        // 4. PIANI_RATE — Etichetta Organizzativa
        // ---------------------------------------------------------------------
        Schema::table('piani_rate', function (Blueprint $table) {
            $table->enum('contesto_creazione', [
                'preventivo_iniziale',
                'integrazione_dashboard',
                'libero_manuale',
            ])
            ->default('preventivo_iniziale')
            ->after('tipo')
            ->comment('Genesi del piano. Ortogonale al campo `tipo` (ordinario/straordinario).');
        });

        // =====================================================================
        // DATA MIGRATION — Retrocompatibilità v1.8 / v1.9.x
        // =====================================================================
        if (DB::getDriverName() === 'mysql') {
            // D1. Classifica i piani straordinari esistenti come "libero_manuale"
            DB::table('piani_rate')
                ->where('tipo', 'straordinario')
                ->update(['contesto_creazione' => 'libero_manuale']);

            // D2. Marca come rateizzate le righe fattura già incluse in piani attivi
            DB::statement("
                UPDATE righe_fattura rf
                JOIN fatture_passive fp ON rf.fattura_passiva_id = fp.id
                JOIN piano_rate_fatture prf ON prf.fattura_passiva_id = fp.id
                JOIN piani_rate pr ON prf.piano_rate_id = pr.id
                SET rf.is_rateizzata = 1
                WHERE pr.stato IN ('bozza', 'approvato')
            ");

            // D3. Marca come tecnici i conti figli con ruolo sopravvenienze_passive
            DB::statement("
                UPDATE conti c
                JOIN conti_contabili cc ON c.conto_contabile_id = cc.id
                SET c.is_tecnico = 1
                WHERE cc.ruolo = 'sopravvenienze_passive'
            ");

            // D4. Marca come tecnici i capitoli padre delle sopravvenienze
            DB::statement("
                UPDATE conti
                SET is_tecnico = 1
                WHERE nome = 'Integrazioni Straordinarie (Scudo Legale)'
                AND parent_id IS NULL
            ");

            // D5. Normalizzazione relitti storici (RELITTO CONTO 138)
            DB::statement("
                UPDATE conti
                SET conto_contabile_id = NULL
                WHERE nome = 'Integrazioni Straordinarie (Scudo Legale)'
                AND parent_id IS NULL
                AND conto_contabile_id IS NOT NULL
            ");
        }
    }

    public function down(): void
    {
        // Rollback ordinato: prima FK e indici, poi colonne
        // NOTA: il rollback NON ripristina i dati modificati da D1–D5.
        // Quelle sono operazioni di sola correzione senza senso da "annullare".

        if (Schema::hasTable('rate_quote')) {
            $rqToDrop = array_filter(
                $this->rateQuoteColumns,
                fn($col) => Schema::hasColumn('rate_quote', $col)
            );
            if (!empty($rqToDrop)) {
                Schema::table('rate_quote', function (Blueprint $table) use ($rqToDrop) {
                    if (in_array('riga_fattura_id', $rqToDrop)) {
                        $table->dropForeign(['riga_fattura_id']);
                    }
                    if (in_array('voce_id', $rqToDrop)) {
                        $table->dropForeign(['voce_id']);
                    }
                    // Droppa l'indice solo se esiste
                    if (DB::getDriverName() === 'mysql') {
                        $indexExists = DB::select("
                            SELECT COUNT(*) as cnt FROM information_schema.STATISTICS
                            WHERE table_schema = DATABASE()
                            AND table_name = 'rate_quote' AND index_name = 'idx_recupero_crediti'
                        ");
                        if ($indexExists[0]->cnt > 0) {
                            $table->dropIndex('idx_recupero_crediti');
                        }
                    }
                    $table->dropColumn(array_values($rqToDrop));
                });
            }
        }

        $rfToDrop = array_filter(
            $this->righeFatturaColumns,
            fn($col) => Schema::hasColumn('righe_fattura', $col)
        );
        if (!empty($rfToDrop)) {
            Schema::table('righe_fattura', function (Blueprint $table) use ($rfToDrop) {
                if (in_array('is_rateizzata', $rfToDrop)) {
                    $table->dropIndex(['is_rateizzata']);
                }
                $table->dropColumn(array_values($rfToDrop));
            });
        }

        $prToDrop = array_filter(
            $this->pianiRateColumns,
            fn($col) => Schema::hasColumn('piani_rate', $col)
        );
        if (!empty($prToDrop)) {
            Schema::table('piani_rate', function (Blueprint $table) use ($prToDrop) {
                $table->dropColumn(array_values($prToDrop));
            });
        }

        $cToDrop = array_filter(
            $this->contiColumns,
            fn($col) => Schema::hasColumn('conti', $col)
        );
        if (!empty($cToDrop)) {
            Schema::table('conti', function (Blueprint $table) use ($cToDrop) {
                $table->dropColumn(array_values($cToDrop));
            });
        }
    }

    /**
     * Rileva ed elimina colonne orfane lasciate da un'esecuzione parziale precedente.
     * Gestisce 4 tabelle indipendenti. Le FK vengono droppate prima delle colonne.
     */
    private function cleanupPartialMigration(): void
    {
        // ── conti ─────────────────────────────────────────────────────────────
        $contiOrphans = array_filter(
            $this->contiColumns,
            fn($col) => Schema::hasColumn('conti', $col)
        );
        if (!empty($contiOrphans)) {
            Log::warning('Partial migration detected on [conti] (hardening)', [
                'orphans' => array_values($contiOrphans),
            ]);
            Schema::table('conti', function (Blueprint $table) use ($contiOrphans) {
                $table->dropColumn(array_values($contiOrphans));
            });
        }

        // ── rate_quote ────────────────────────────────────────────────────────
        $rqOrphans = array_filter(
            $this->rateQuoteColumns,
            fn($col) => Schema::hasColumn('rate_quote', $col)
        );
        if (!empty($rqOrphans)) {
            Log::warning('Partial migration detected on [rate_quote] (hardening)', [
                'orphans' => array_values($rqOrphans),
            ]);
            Schema::table('rate_quote', function (Blueprint $table) use ($rqOrphans) {
                if (in_array('riga_fattura_id', $rqOrphans)) {
                    $table->dropForeign(['riga_fattura_id']);
                }
                if (in_array('voce_id', $rqOrphans)) {
                    $table->dropForeign(['voce_id']);
                }
                if (DB::getDriverName() === 'mysql') {
                    $indexExists = DB::select("
                        SELECT COUNT(*) as cnt FROM information_schema.STATISTICS
                        WHERE table_schema = DATABASE()
                        AND table_name = 'rate_quote' AND index_name = 'idx_recupero_crediti'
                    ");
                    if ($indexExists[0]->cnt > 0) {
                        $table->dropIndex('idx_recupero_crediti');
                    }
                }
                $table->dropColumn(array_values($rqOrphans));
            });
        }

        // ── righe_fattura ─────────────────────────────────────────────────────
        $rfOrphans = array_filter(
            $this->righeFatturaColumns,
            fn($col) => Schema::hasColumn('righe_fattura', $col)
        );
        if (!empty($rfOrphans)) {
            Log::warning('Partial migration detected on [righe_fattura] (hardening)', [
                'orphans' => array_values($rfOrphans),
            ]);
            Schema::table('righe_fattura', function (Blueprint $table) use ($rfOrphans) {
                if (in_array('is_rateizzata', $rfOrphans)) {
                    $table->dropIndex(['is_rateizzata']);
                }
                $table->dropColumn(array_values($rfOrphans));
            });
        }

        // ── piani_rate ────────────────────────────────────────────────────────
        $prOrphans = array_filter(
            $this->pianiRateColumns,
            fn($col) => Schema::hasColumn('piani_rate', $col)
        );
        if (!empty($prOrphans)) {
            Log::warning('Partial migration detected on [piani_rate] (hardening)', [
                'orphans' => array_values($prOrphans),
            ]);
            Schema::table('piani_rate', function (Blueprint $table) use ($prOrphans) {
                $table->dropColumn(array_values($prOrphans));
            });
        }
    }
};