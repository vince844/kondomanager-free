<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabella documentale 1:1 con scritture_contabili per i pagamenti ai fornitori.
     * La logica contabile vive in scritture_contabili + fattura_scrittura (pivot).
     * Questa tabella cattura i metadati documentali: IBAN, causale bonifico,
     * conto corrente addebitato, snapshot fornitore, dettagli antiriciclaggio, ecc.
     *
     * Relazioni chiave:
     *  - scrittura_contabile_id (1:1 UNIQUE) → voce del giornale contabile
     *  - conto_corrente_id → il conto bancario/cassa addebitato
     *  - fattura/e pagate → via pivot fattura_scrittura (non FK diretta qui)
     *  - pagamento_padre_id (self-ref) → per storni: il record di storno punta al padre
     *
     * Pattern storno (Decisione v1.9.1):
     *  1. Crea record storno #N con pagamento_padre_id = id_originale
     *  2. Ogni record ha il proprio scrittura_contabile_id (scrittura inversa per lo storno)
     *  3. Il record originale viene aggiornato: stato = 'stornato', motivo_storno = '...'
     *  Vantaggio: nessuna dipendenza circolare, navigazione pulita con hasOne/belongsTo.
     *
     * Storno cross-esercizio (Variante B1):
     *  Se l'esercizio del pagamento originale è chiuso, lo storno è registrato
     *  nell'esercizio corrente aperto → flag storno_cross_esercizio + esercizio_storno_id.
     */
    public function up(): void
    {
        Schema::create('pagamenti_fornitori', function (Blueprint $table) {
            $table->id();

            // Identificatore pubblico non sequenziale (sicuro in URL, export, email)
            $table->uuid('uuid')
                  ->unique()
                  ->comment('Identificatore pubblico del pagamento — non sequenziale, sicuro in URL');

            // ── Relazioni core ──────────────────────────────────────────────────
            $table->foreignId('scrittura_contabile_id')
                  ->unique()  // 1:1 con la scrittura contabile
                  ->constrained('scritture_contabili')
                  ->restrictOnDelete()
                  ->comment('Scrittura contabile corrispondente — 1:1, immutabile dopo creazione');

            $table->foreignId('condominio_id')
                  ->constrained('condomini')
                  ->restrictOnDelete()
                  ->comment('Denormalizzato per scoping query senza join su scritture_contabili');

            $table->foreignId('fornitore_id')
                  ->constrained('fornitori')
                  ->restrictOnDelete()
                  ->comment('Fornitore beneficiario del pagamento');

            // Conto corrente (o cassa) da cui esce la liquidità.
            // Tipicamente ruolo = 'conto_bancario' o 'cassa' in conti_contabili.
            // Obbligatorio: ogni pagamento ha una fonte di liquidità identificata.
            $table->foreignId('conto_corrente_id')
                  ->constrained('conti_contabili')
                  ->restrictOnDelete()
                  ->comment('Conto bancario o cassa addebitato — FK su conti_contabili (ruolo: conto_bancario|cassa)');

            // ── Importi in centesimi (no floating point) ────────────────────────
            // importo_lordo = imponibile + IVA non detraibile (quanto dovuto al fornitore)
            // importo_ritenuta = trattenuta d'acconto (versata allo Stato via F24)
            // importo_netto = importo_lordo - importo_ritenuta (uscita effettiva dalla banca)
            // INVARIANTE: importo_netto = importo_lordo - importo_ritenuta
            $table->bigInteger('importo_lordo')
                  ->comment('Importo totale lordo in centesimi (imponibile + IVA non detraibile)');

            $table->bigInteger('importo_ritenuta')
                  ->default(0)
                  ->comment('Ritenuta d\'acconto trattenuta in centesimi (versata tramite F24)');

            $table->bigInteger('importo_netto')
                  ->comment('Importo bonificato: importo_lordo - importo_ritenuta (uscita effettiva dalla banca)');

            $table->bigInteger('importo_commissione')
                  ->default(0)
                  ->comment('Commissioni bancarie in centesimi (contabilizzate su conto spese_bancarie)');

            // ── Metodo di pagamento ─────────────────────────────────────────────
            $table->string('metodo_pagamento', 50)
                  ->comment('Backed Enum MetodoPagamento: bonifico|contante|assegno|rid|sepa_sdd');

            // ── Date ────────────────────────────────────────────────────────────
            $table->date('data_pagamento')
                  ->comment('Data della disposizione di pagamento (data ordine bonifico)');

            // data_valuta: differisce da data_pagamento per weekend, festivi, SEPA.
            // Valorizzata dopo ricevuta bancaria. Rimandato a v1.9.2 per la UI,
            // ma la colonna è presente fin da ora per non fare ALTER TABLE.
            $table->date('data_valuta')
                  ->nullable()
                  ->comment('Data valuta effettiva banca — può differire da data_pagamento (valorizzata in v1.9.2)');

            // ── Dati bancari e causale ──────────────────────────────────────────
            $table->string('iban_beneficiario', 34)
                  ->nullable()
                  ->comment('IBAN del fornitore al momento del pagamento (snapshot per audit antifrode)');

            // CRO (Codice di Riferimento Operazione) per bonifici SEPA italiani,
            // o TRN (Transaction Reference Number) per SEPA internazionale.
            // Valorizzato dopo conferma banca. Essenziale per riconciliazione estratto conto.
            $table->string('riferimento_bancario', 50)
                  ->nullable()
                  ->comment('CRO/TRN del bonifico — valorizzato dopo conferma banca, necessario per riconciliazione');

            $table->text('causale_bonifico')
                  ->nullable()
                  ->comment('Causale completa del bonifico — include Bonifico Parlante per detrazioni fiscali (v1.9.1+)');

            // Flag Bonifico Parlante fiscale (art. 16-bis TUIR, D.L. 201/2011).
            // Se true, la causale deve contenere: CF committente, CF fornitore,
            // P.IVA fornitore, riferimento normativo, importo. La banca applica
            // ritenuta 8% A.d.E. sull'importo lordo.
            $table->boolean('bonifico_parlante')
                  ->default(false)
                  ->comment('True se il bonifico richiede tracciabilità fiscale (art. 16-bis TUIR) — ritenuta 8% banca');

            // Tipo detrazione fiscale associata (per Bonifico Parlante).
            // Backed Enum TipoDetrazione: ristrutturazione|risparmio_energetico|sismabonus|ecc.
            // NULL se bonifico_parlante = false.
            $table->string('tipo_detrazione', 50)
                  ->nullable()
                  ->comment('Backed Enum TipoDetrazione — NULL se non è un Bonifico Parlante (v1.9.1+)');

            // ── Stato e storni ───────────────────────────────────────────────────
            $table->string('stato', 50)
                  ->default('confermato')
                  ->comment('Backed Enum StatoPagamentoFornitore: confermato|stornato');

            // Self-referential per storni (Decisione v1.9.1 — vedi docblock classe).
            // Il record di storno punta al pagamento originale.
            // NULL su tutti i record non-storno.
            $table->foreignId('pagamento_padre_id')
                  ->nullable()
                  ->constrained('pagamenti_fornitori')
                  ->nullOnDelete()
                  ->comment('FK al pagamento originale — valorizzato solo sul record di storno');

            $table->text('motivo_storno')
                  ->nullable()
                  ->comment('Motivazione obbligatoria per lo storno — audit trail immutabile');

            // Storno cross-esercizio Variante B1: l'esercizio del pagamento originale
            // è chiuso → lo storno viene registrato nell'esercizio corrente aperto.
            $table->boolean('storno_cross_esercizio')
                  ->default(false)
                  ->comment('True se il pagamento originale è in esercizio chiuso rispetto allo storno (Variante B1)');

            $table->foreignId('esercizio_storno_id')
                  ->nullable()
                  ->constrained('esercizi')
                  ->nullOnDelete()
                  ->comment('Esercizio corrente in cui è registrato lo storno cross-esercizio');

            // ── Idempotenza ──────────────────────────────────────────────────────
            // Previene doppi inserimenti su retry/concorrenza.
            // Il service imposta questo UUID prima del DB::transaction() e lo passa
            // nell'insert. Su doppio invio: QueryException su UNIQUE → rilancia
            // IdempotencyKeyDuplicateException (non un errore, ma un replay sicuro).
            $table->uuid('idempotency_key')
                  ->nullable()
                  ->unique()
                  ->comment('Previene doppi inserimenti su retry — gestito via try/catch QueryException');

            // ── Snapshot e audit ─────────────────────────────────────────────────
            // Snapshot del fornitore al momento del pagamento.
            // Preserva ragione_sociale, CF, PIVA, IBAN anche se l'anagrafica viene
            // modificata in seguito (immutabilità documentale, necessario per CU/770).
            // Schema: { schema_version: 1, ragione_sociale, cf, piva, iban, ... }
            $table->json('fornitore_snapshot')
                  ->nullable()
                  ->comment('Snapshot anagrafica fornitore al pagamento — immutabile (necessario per CU/770)');

            // Condòmini con diritto a detrazione fiscale collegata a questo pagamento.
            // Struttura: [{ immobile_id, anagrafica_id, cf, quota_percentuale, tipo_detrazione }]
            // Popolato in v1.9.1. Il motore di calcolo detrazioni è attivato in v1.12.
            $table->json('beneficiari_detrazione')
                  ->nullable()
                  ->comment('Condòmini beneficiari di detrazione fiscale su questo pagamento (motore attivo in v1.12)');

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete()
                  ->comment('Utente che ha confermato il pagamento');

            $table->timestamps();

            // ── Indici performance ──────────────────────────────────────────────
            // Indice composito per query operative: "tutti i pagamenti di fornitore X
            // ordinati per data" — usato in lista pagamenti, estratti conto fornitore.
            $table->index(['fornitore_id', 'data_pagamento'], 'pf_fornitore_data_idx');

            // Indice su stato per filtrare pagamenti confermati vs stornati.
            // Usato da Payment Sentinel UI e da riconciliazione bancaria.
            $table->index('stato', 'pf_stato_idx');

            // Indice su condominio_id per scoping rapido (quasi tutte le query
            // filtrano per condominio — evita full scan su tabella cross-condominio).
            $table->index('condominio_id', 'pf_condominio_idx');
        });

        Log::info('Migration [pagamenti_fornitori]: tabella creata con indici performance.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagamenti_fornitori');
    }
};