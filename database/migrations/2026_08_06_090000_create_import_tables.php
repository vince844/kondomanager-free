<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'importatore dati: il lotto, i suoi file e ciò che ha scritto in archivio.
 *
 * Sono **tre tabelle nuove e nessun `ALTER`** su tabelle vive — la forma più prudente che una
 * migrazione possa avere sul salto 1.9.1 → 1.10, che è l'unico senza backup automatico: una
 * `CREATE TABLE` interrotta non lascia dati a metà, al contrario di un `ALTER`.
 *
 * **Rieseguibile**, come tutte quelle del salto: ogni `create` è protetto da `hasTable`, e le
 * foreign key nascono dentro la stessa `create` — su una tabella nuova non c'è il problema
 * MySQL delle guardie separate per colonna e per FK, che riguarda gli `ALTER`.
 *
 * ## Perché una pivot polimorfa e non `import_batch_id` su ogni entità
 *
 * `docs/import_migrazione_dati.md` §7 prevede «`import_batch_id` su ogni entità creata».
 * Qui si è scelto diversamente, e vale la pena scriverne il motivo perché è una divergenza
 * consapevole dal documento, non una dimenticanza.
 *
 * L'importatore tocca **quattordici** tipi di entità (§5 del documento). La colonna su ognuna
 * significherebbe quattordici `ALTER TABLE` su tabelle popolate — `condomini`, `anagrafiche`,
 * `immobili`, `tabelle`, `saldi` — cioè esattamente la categoria di migrazione che questa
 * release ha deciso di evitare. E significherebbe rifarne un'altra a ogni livello nuovo.
 *
 * `import_batch_items` porta la stessa informazione in una tabella sola, con in più due cose
 * che la colonna non avrebbe dato:
 *
 * - **l'azione**: creato, unito a un record esistente, saltato. Il rapporto post-import
 *   (§13.3) e la decisione sui duplicati (§10.3) vivono qui, e con la colonna sarebbero
 *   servite altre due colonne per entità;
 * - **la riga di origine**: da quale riga di quale file viene questo record, che è ciò che
 *   permette di rispondere a «questo numero da dove esce» senza avere più il file.
 *
 * Il prezzo è una `join` per sapere chi ha creato una riga. Si paga solo nel rapporto e
 * nell'annullamento, che sono due schermate — non nelle query del motore, che di questa
 * tabella non sanno niente e non devono saperne.
 *
 * ## Perché non c'è una finestra temporale per l'annullamento
 *
 * Lo stato del lotto non contiene una scadenza. L'annullamento è possibile finché **nessuna
 * operazione successiva dipende** dalle entità create (§13.2): è una condizione, non un
 * timer. Un giornale append-only non può «tornare esattamente com'era» dopo che ci si è
 * scritto sopra, e prometterlo con una data è una promessa che si rompe da sola.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Il lotto: una sessione di importazione, dall'apertura all'esito ──────────
        if (! Schema::hasTable('import_batches')) {
            Schema::create('import_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();

                // NULL finché il livello «condominio» non è stato confermato: il primo file
                // può essere proprio quello che crea il condominio.
                $table->foreignId('condominio_id')->nullable()
                    ->constrained('condomini')->nullOnDelete();

                // Chi ha eseguito l'import. Resta anche se l'utente viene rimosso: il
                // rapporto (§13.3) è il documento di consegna della migrazione assistita e
                // deve poter dire chi l'ha fatta.
                $table->foreignId('user_id')->nullable()
                    ->constrained('users')->nullOnDelete();

                // 'danea' | 'manual' | 'csv_generico' | …
                $table->string('sorgente', 40);

                // in_corso | parziale | completato | annullato
                $table->string('stato', 20)->default('in_corso');

                // Il livello a cui il lotto è arrivato (§5). Serve alla ripresa dopo
                // un'interruzione: è il checkpoint, non un progresso decorativo.
                $table->string('livello_corrente', 40)->nullable();

                // Le decisioni dell'utente sui casi che non possiamo risolvere noi: unisci,
                // salta, crea nuovo, dividi in due (§10.3).
                //
                // Stanno **sul lotto** e non in sessione perché devono sopravvivere a un
                // ricaricamento della pagina e alla ripresa dopo un'interruzione: un'importazione
                // che perde le decisioni già prese le richiede tutte da capo, e su quaranta unità
                // è il momento in cui l'amministratore chiude la scheda.
                $table->json('decisioni')->nullable();

                // Il rapporto integrale: contatori per livello, avvisi non bloccanti,
                // decisioni prese sui duplicati, totale di riferimento dei saldi e scarto.
                // JSON e non colonne, perché la forma cresce con i livelli.
                $table->json('rapporto')->nullable();

                $table->timestamp('completato_at')->nullable();
                $table->timestamp('annullato_at')->nullable();
                $table->timestamps();

                $table->index(['stato', 'created_at']);
            });
        }

        // ── I file del lotto: cosa è stato caricato e come è stato riconosciuto ──────
        if (! Schema::hasTable('import_files')) {
            Schema::create('import_files', function (Blueprint $table) {
                $table->id();

                $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();

                $table->string('nome_originale');
                $table->string('percorso');                 // dove è stato messo in storage
                $table->unsignedBigInteger('dimensione');   // byte
                $table->string('hash', 64);                 // sha256: riconosce il ricaricamento identico

                // Cosa il sistema ha creduto che fosse, e quanto ci credeva (§6).
                $table->string('report_type', 60)->nullable();
                $table->unsignedTinyInteger('confidenza')->nullable();   // 0–100

                // Vero se è stato l'utente a scegliere il tipo, smentendo il punteggio.
                // Non è statistica: è la traccia di chi ha deciso, e serve nel rapporto.
                $table->boolean('tipo_forzato')->default(false);

                // Colonne riconosciute e ignorate, per il pannello che il §14.0 rende
                // obbligatorio anche quando è tutto verde.
                $table->json('colonne')->nullable();

                $table->timestamps();

                $table->index(['import_batch_id', 'report_type']);
            });
        }

        // ── Cosa il lotto ha scritto: una riga per entità toccata ────────────────────
        if (! Schema::hasTable('import_batch_items')) {
            Schema::create('import_batch_items', function (Blueprint $table) {
                $table->id();

                $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
                $table->foreignId('import_file_id')->nullable()
                    ->constrained('import_files')->nullOnDelete();

                // Il livello del §5 a cui questa riga appartiene: 'condominio', 'esercizi', …
                $table->string('livello', 40);

                // L'entità toccata, polimorfa e **nullable**.
                //
                // Nullable perché due casi legittimi non hanno un'entità dietro: la riga
                // `saltato`, che per definizione non ha creato niente, e la titolarità, che in
                // Kondomanager vive nella pivot `anagrafica_immobile` e non ha un model proprio.
                // La prima stesura usava `morphs()`: il commento diceva già «passa da qui anche
                // il caso saltato», e la colonna lo vietava.
                $table->nullableMorphs('importabile');

                // creato | unito | saltato
                // 'unito' significa: la riga del file è confluita in un record che esisteva
                // già, per decisione esplicita dell'utente (§10.3). L'annullamento non lo
                // elimina — non l'ha creato lui.
                $table->string('azione', 20);

                // Da quale riga del file viene. 1-based come la vede l'utente in Excel,
                // NULL per le entità dedotte dal banner e non da una riga.
                $table->unsignedInteger('riga_sorgente')->nullable();

                $table->timestamps();

                $table->index(['import_batch_id', 'livello']);
                $table->index(['import_batch_id', 'azione']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_items');
        Schema::dropIfExists('import_files');
        Schema::dropIfExists('import_batches');
    }
};
