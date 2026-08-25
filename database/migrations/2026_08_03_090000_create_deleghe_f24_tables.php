<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il modulo F24: la delega, le sue righe, e il legame con i pagamenti che copre.
 *
 * Sono **tre tabelle nuove e nessun `ALTER`** su tabelle vive. È la forma più prudente che
 * una migrazione possa avere sul salto 1.9.1 → 1.10, che è l'unico senza backup automatico:
 * una `CREATE TABLE` interrotta non lascia dati a metà, al contrario di un `ALTER`.
 *
 * **Rieseguibile**, come tutte quelle del salto: su hosting condiviso una migrazione può
 * superare il tempo massimo del web server, lasciando le modifiche applicate ma la
 * migrazione non registrata. Ogni `create` è quindi protetto da `hasTable`, e le foreign key
 * nascono dentro la stessa `create` — su una tabella nuova non c'è il problema MySQL delle
 * guardie separate per colonna e per FK, che riguarda gli `ALTER`.
 *
 * ## Perché la pivot, e cosa sostituisce
 *
 * Il design (`f24_ritenute_design.md` §2.4) prevede `ritenute_operate`, il registro fiscale
 * analitico, e vi aggancia le righe F24 con `ritenute_operate.riga_f24_id`. Quel registro
 * nasce con la Fase 2, che sposta il fatto generatore della ritenuta dalla registrazione
 * della fattura al pagamento — la fase che il design stesso marca «★ FASE CRITICA ★».
 *
 * Finché non c'è, il legame lo tiene `riga_f24_pagamento`: quali pagamenti una riga di
 * delega sta versando. Non è un ripiego che andrà buttato — è l'informazione che serve
 * comunque, e che rende ricostruibile la scomposizione per percipiente (delega → pagamenti
 * → fornitore) anche senza il registro analitico. Quando `ritenute_operate` arriverà,
 * aggiungerà la sua colonna verso `righe_f24`, che qui nasce già indipendente: il design lo
 * consente perché `righe_f24` non ha alcun riferimento in uscita verso il registro.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── La delega: il documento che si conserva dieci anni ────────────────
        if (! Schema::hasTable('deleghe_f24')) {
            Schema::create('deleghe_f24', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();

                $table->foreignId('condominio_id')->constrained('condomini')->restrictOnDelete();
                $table->foreignId('esercizio_id')->constrained('esercizi')->restrictOnDelete();

                // Nasce NULL: la scrittura esiste solo dalla conferma del versamento.
                // Unique perché una scrittura F24 appartiene a una delega sola.
                $table->foreignId('scrittura_contabile_id')->nullable()->unique()
                    ->constrained('scritture_contabili')->restrictOnDelete();

                // Storni e split: una delega può discendere da un'altra.
                $table->foreignId('delega_padre_id')->nullable()
                    ->constrained('deleghe_f24')->restrictOnDelete();

                $table->string('stato', 20)->default('bozza');
                $table->string('plafond', 30);

                // PERCHÉ un codice e non la frase già pronta: la frase è testo di sistema,
                // e salvarla significa congelarla. Correggendo una parola — è successo
                // subito, con il simbolo dell'euro nel posto sbagliato — tutte le deleghe
                // già calcolate resterebbero con la versione vecchia, e `note` smetterebbe
                // di essere il campo delle note dell'amministratore. Il codice si traduce
                // in italiano nell'interfaccia, dove il testo si cambia senza migrazioni.
                $table->string('motivo_codice', 40)->nullable();
                $table->string('protocollo', 40)->nullable()->unique();

                $table->date('data_scadenza');
                $table->date('data_versamento')->nullable();

                $table->foreignId('conto_corrente_id')->nullable()
                    ->constrained('conti_contabili')->restrictOnDelete();
                $table->foreignId('cassa_id')->nullable()
                    ->constrained('casse')->restrictOnDelete();

                // Snapshot anagrafico: il codice fiscale del CONDOMINIO al momento della
                // delega. È una guardia, non una comodità — una delega versata con il CF
                // sbagliato è un versamento imputato a un altro soggetto.
                $table->string('cf_contribuente', 16)->nullable();
                $table->string('denominazione_contribuente', 255)->nullable();

                $table->bigInteger('totale_debito')->default(0);
                $table->bigInteger('saldo')->default(0);

                $table->date('data_quietanza')->nullable();
                $table->string('quietanza_path', 500)->nullable();
                $table->string('quietanza_hash', 64)->nullable();

                $table->text('motivo_annullamento')->nullable();
                $table->text('note')->nullable();

                $table->uuid('idempotency_key')->nullable()->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

                // NESSUN softDeletes, deliberatamente: il giornale è append-only e una
                // delega va conservata dieci anni. L'annullamento è uno *stato*.
                $table->timestamps();

                $table->index(['condominio_id', 'stato', 'data_scadenza'], 'deleghe_f24_lavoro_index');
            });
        }

        // ── Le righe della sezione Erario: al massimo sei per delega ──────────
        if (! Schema::hasTable('righe_f24')) {
            Schema::create('righe_f24', function (Blueprint $table) {
                $table->id();
                $table->foreignId('delega_f24_id')->constrained('deleghe_f24')->cascadeOnDelete();

                $table->unsignedTinyInteger('ordine');
                $table->string('sezione', 20)->default('erario');
                $table->string('codice_tributo', 4);

                // STRINGA e non intero: il tracciato vuole quattro caratteri eterogenei
                // ('0006' per giugno, ma anche '0101' o sigle). Modellarlo come numero
                // costringe a ri-serializzarlo male in export.
                $table->char('rateazione_mese_rif', 4);
                $table->char('anno_riferimento', 4);

                $table->bigInteger('importo_debito')->default(0);
                $table->timestamps();

                $table->unique(['delega_f24_id', 'ordine']);
            });
        }

        // ── Quali pagamenti sta versando una riga di delega ───────────────────
        if (! Schema::hasTable('riga_f24_pagamento')) {
            Schema::create('riga_f24_pagamento', function (Blueprint $table) {
                $table->id();
                $table->foreignId('riga_f24_id')->constrained('righe_f24')->cascadeOnDelete();
                $table->foreignId('pagamento_fornitore_id')
                    ->constrained('pagamenti_fornitori')->restrictOnDelete();

                // Quanto di quel pagamento entra in questa riga. Normalmente è l'intera
                // ritenuta del pagamento, ma tenerlo esplicito evita di doverlo dedurre
                // quando una delega verrà splittata per superamento delle sei righe.
                $table->bigInteger('importo')->default(0);
                $table->timestamps();

                // Un pagamento non può essere versato due volte dalla stessa riga.
                $table->unique(['riga_f24_id', 'pagamento_fornitore_id'], 'riga_f24_pagamento_unico');

                // Serve a rispondere «questo pagamento è già stato versato?» senza scorrere
                // le deleghe: è la domanda che il modulo fa più spesso.
                $table->index('pagamento_fornitore_id', 'riga_f24_pagamento_ricerca');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('riga_f24_pagamento');
        Schema::dropIfExists('righe_f24');
        Schema::dropIfExists('deleghe_f24');
    }
};
