<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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
    public function up(): void
    {
        // ---------------------------------------------------------------------
        // 1. CONTI — Il Conto Tecnico (Shadow Account)
        // ---------------------------------------------------------------------
        // Distingue i conti deliberati in assemblea (preventivo) dai conti
        // generati on-the-fly dal sistema per gestire sopravvenienze passive.
        //
        // I conti tecnici sono:
        //   - Invisibili al wizard "Nuovo Piano Ordinario"
        //   - Separati nella UI del piano dei conti
        //   - Esclusi dal PDF del preventivo deliberato
        //   - Mostrati solo nel consuntivo (Art. 1130-bis c.c.)
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
        // Aggiunge 5 campi che classificano la natura legale di ogni quota:
        //
        //   - origine_tipo: condominiale (millesimale) o ad_personam (Art. 63)
        //   - stato_legale: macchina a stati evolutiva (certo → diffidato → ingiungibile)
        //   - stato_legale_aggiornato_at: timestamp dell'ultima transizione
        //   - riga_fattura_id: FK alla riga fattura specifica che ha generato la quota
        //   - voce_id: FK al conto di preventivo (popolato in Fase 2)
        //
        // I primi 3 campi vengono popolati dall'Action GenerateRateQuotesAction.
        // Gli ultimi 2 rimangono NULL in Fase 1 — popolati con il refactor del
        // CalcoloQuoteService (Fase 2, v1.9.25+).
        // ---------------------------------------------------------------------
        Schema::table('rate_quote', function (Blueprint $table) {
            // Classificazione legale indicizzabile
            $table->enum('origine_tipo', ['condominiale', 'ad_personam'])
                  ->default('condominiale')
                  ->after('tipo')
                  ->comment('condominiale = ripartita millesimalmente; ad_personam = 100% al proprietario (Art. 63 disp. att. c.c.)');

            // Macchina a stati per il modulo Recupero Crediti (v1.11)
            $table->enum('stato_legale', ['certo', 'contestabile', 'diffidato', 'ingiungibile'])
                  ->default('certo')
                  ->after('origine_tipo')
                  ->comment('Evoluzione: certo (default) → contestabile (ad personam) → diffidato (post lettera) → ingiungibile (post decreto)');

            // Audit trail delle transizioni legali
            $table->timestamp('stato_legale_aggiornato_at')
                  ->nullable()
                  ->after('stato_legale')
                  ->comment('Timestamp dell\'ultimo cambio di stato_legale. Essenziale per il modulo Recupero Crediti.');

            // FK alla RIGA (non alla testata) per tracciabilità millimetrica
            $table->foreignId('riga_fattura_id')
                  ->nullable()
                  ->after('immobile_id')
                  ->constrained('righe_fattura')
                  ->nullOnDelete()
                  ->comment('FK alla riga fattura che ha generato questa quota. NULL per quote da preventivo ordinario.');

            // FK al conto di preventivo (per quote condominiali, Fase 2)
            $table->foreignId('voce_id')
                  ->nullable()
                  ->after('riga_fattura_id')
                  ->constrained('conti')
                  ->nullOnDelete()
                  ->comment('FK al conto (voce di spesa) da cui deriva la quota. Popolato in Fase 2 con refactor CalcoloQuoteService.');

            // Indice dedicato per il modulo Recupero Crediti
            // Query tipo: "Tutte le quote diffidate/ingiungibili di Rossi"
            $table->index(['anagrafica_id', 'stato_legale'], 'idx_recupero_crediti');
        });

        // ---------------------------------------------------------------------
        // 3. RIGHE_FATTURA — Flag Semaforo Dashboard
        // ---------------------------------------------------------------------
        // Flag che spegne l'allarme "fattura scoperta" nella Dashboard quando
        // la riga è stata inclusa in un piano rate attivo.
        //
        // Sostituisce le fragili query `whereNotExists` con un filtro diretto
        // indicizzato. Viene popolato automaticamente quando:
        //   - La riga viene inclusa in un piano rate (stato bozza/approvato)
        //   - La riga viene rimossa dal piano (detach) → torna a false
        // ---------------------------------------------------------------------
        Schema::table('righe_fattura', function (Blueprint $table) {
            $table->boolean('is_rateizzata')
                  ->default(false)
                  ->after('is_sopravvenienza')
                  ->comment('True quando questa riga è inclusa in un piano rate attivo. Spegne l\'allarme Dashboard.');

            // Indice per la query della Dashboard (chiamata ad ogni caricamento)
            $table->index('is_rateizzata');
        });

        // ---------------------------------------------------------------------
        // 4. PIANI_RATE — Etichetta Organizzativa
        // ---------------------------------------------------------------------
        // Distingue la genesi del piano rate per reporting e audit:
        //
        //   - preventivo_iniziale:    Piano madre di inizio anno da bilancio
        //   - integrazione_dashboard: Nato dal deep-link del widget "Risolvi"
        //   - libero_manuale:         Creato manualmente dal menu Piani Rate
        //
        // NON sostituisce il campo `tipo` (ordinario/straordinario) — è un
        // campo ortogonale che traccia il percorso utente, non la natura
        // contabile del piano.
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

        // ---------------------------------------------------------------------
        // D1. Classifica i piani straordinari esistenti come "libero_manuale"
        // ---------------------------------------------------------------------
        // I piani straordinari pre-migrazione sono nati da un'azione manuale
        // dal menu Piani Rate (non esisteva ancora il deep-link Dashboard).
        // Quelli ordinari rimangono al default 'preventivo_iniziale'.
        // ---------------------------------------------------------------------
        DB::table('piani_rate')
            ->where('tipo', 'straordinario')
            ->update(['contesto_creazione' => 'libero_manuale']);

        // ---------------------------------------------------------------------
        // D2. Marca come rateizzate le righe fattura già incluse in piani attivi
        // ---------------------------------------------------------------------
        // Evita che il widget Dashboard accenda falsi allarmi su righe già
        // gestite da piani pre-migrazione. Solo piani in stato bozza/approvato
        // (non cancellati o archiviati) contribuiscono.
        // ---------------------------------------------------------------------
        if (DB::getDriverName() === 'mysql') {
            DB::statement("
                UPDATE righe_fattura rf
                JOIN fatture_passive fp ON rf.fattura_passiva_id = fp.id
                JOIN piano_rate_fatture prf ON prf.fattura_passiva_id = fp.id
                JOIN piani_rate pr ON prf.piano_rate_id = pr.id
                SET rf.is_rateizzata = 1
                WHERE pr.stato IN ('bozza', 'approvato')
            ");

            // ---------------------------------------------------------------------
            // D3. Marca come tecnici i conti FIGLI con ruolo sopravvenienze_passive
            // ---------------------------------------------------------------------
            // Tutti i conti esistenti collegati al mastro Sopravvenienze Passive
            // vanno esclusi dal wizard piano ordinario.
            // ---------------------------------------------------------------------
            DB::statement("
                UPDATE conti c
                JOIN conti_contabili cc ON c.conto_contabile_id = cc.id
                SET c.is_tecnico = 1
                WHERE cc.ruolo = 'sopravvenienze_passive'
            ");

            // ---------------------------------------------------------------------
            // D4. Marca come tecnici i CAPITOLI PADRE delle sopravvenienze
            // ---------------------------------------------------------------------
            // Il capitolo "Integrazioni Straordinarie (Scudo Legale)" è un
            // contenitore che raggruppa le sopravvenienze. Non ha un ruolo
            // contabile proprio (ereditato da FatturaPassivaService::creaContoDinamicoSopravvenienza)
            // ma deve essere anch'esso invisibile al preventivo ordinario.
            // ---------------------------------------------------------------------
            DB::statement("
                UPDATE conti
                SET is_tecnico = 1
                WHERE nome = 'Integrazioni Straordinarie (Scudo Legale)'
                AND parent_id IS NULL
            ");

            // ---------------------------------------------------------------------
            // D5. Normalizzazione relitti storici (RELITTO CONTO 138)
            // ---------------------------------------------------------------------
            // Prima versioni del FatturaPassivaService avevano un bug nel
            // firstOrCreate che assegnava erroneamente `conto_contabile_id` al
            // capitolo padre (es. conto 138 nel DB con ruolo 'costi_servizi').
            //
            // Il capitolo padre delle sopravvenienze è un puro raggruppatore
            // visivo — i costi reali sono sui conti figli. Qui ripuliamo il
            // capitolo padre impostando conto_contabile_id = NULL.
            //
            // Sicuro: nessuna scrittura contabile è mai stata generata sul
            // capitolo padre direttamente (solo sui figli).
            // ---------------------------------------------------------------------
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
        // ---------------------------------------------------------------------
        // Rollback ordinato: prima FK, poi indici, poi colonne
        //
        // NOTA: il rollback NON ripristina i relitti storici (D5) né
        // cancella i dati popolati da D1/D2/D3/D4. Quelle sono operazioni
        // di sola correzione che non hanno senso "annullare".
        // ---------------------------------------------------------------------

        Schema::table('rate_quote', function (Blueprint $table) {
            $table->dropForeign(['riga_fattura_id']);
            $table->dropForeign(['voce_id']);
            $table->dropIndex('idx_recupero_crediti');
            $table->dropColumn([
                'origine_tipo',
                'stato_legale',
                'stato_legale_aggiornato_at',
                'riga_fattura_id',
                'voce_id',
            ]);
        });

        Schema::table('righe_fattura', function (Blueprint $table) {
            $table->dropIndex(['is_rateizzata']);
            $table->dropColumn('is_rateizzata');
        });

        Schema::table('piani_rate', function (Blueprint $table) {
            $table->dropColumn('contesto_creazione');
        });

        Schema::table('conti', function (Blueprint $table) {
            $table->dropColumn('is_tecnico');
        });
    }
};