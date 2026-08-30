<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La classificazione ATECO, nella forma che il programma può interrogare.
 *
 * ## Cosa c'è dentro, misurato sulla fonte e non dedotto
 *
 * Il file ISTAT «Struttura ATECO 2025 italiano inglese» contiene **3.257 codici** su **sei livelli
 * gerarchici**, con il titolo in italiano e in inglese e il codice del padre su ogni riga:
 *
 * | livello | quanti | esempio    | che cos'è      |
 * | :------ | -----: | :--------- | :------------- |
 * | 1       |     22 | `A`        | sezione — è una **lettera** |
 * | 2       |     87 | `01`       | divisione      |
 * | 3       |    287 | `01.1`     | gruppo         |
 * | 4       |    651 | `01.11`    | classe         |
 * | 5       |    920 | `01.11.0`  | categoria      |
 * | 6       |  1.290 | `01.11.00` | sottocategoria |
 *
 * Il codice va da **1 a 8 caratteri**. La colonna è `varchar(12)`: il margine non è scaramanzia, è
 * lo spazio per una revisione futura che allunghi la sottocategoria senza chiedere un `ALTER`.
 *
 * ⚠️ **Il primo livello è una lettera**, e questo smentisce la forma «due cifre più punti» che
 * sembrava ovvia prima di aprire il file: una `regex` scritta su quella premessa avrebbe rifiutato
 * tutte e ventidue le sezioni.
 *
 * ## Perché `versione_fonte` e non una data
 *
 * Sui Comuni la fonte dichiara **quando** è aggiornata — ISTAT la scrive nel nome del foglio, «CODICI
 * al 21_02_2026» — e ha senso, perché i comuni si fondono e cambiano nome durante l'anno.
 *
 * L'ATECO no. **Verificato cella per cella su entrambi i fogli del file: una data non c'è.** E non è
 * una dimenticanza di ISTAT: l'ATECO non cambia in continuazione, cambia per **revisione della
 * classificazione**, e l'identità del dato è il nome della revisione — «ATECO 2025». La domanda utile
 * non è «di che giorno è questo file» ma «ne è uscita una nuova».
 *
 * Quindi il timbro obbligatorio è `versione_fonte`. `fonte_al` resta **nullable** e si valorizza solo
 * se chi importa la dichiara: riempirla con il `last-modified` HTTP sarebbe scrivere un dato che il
 * documento di processo, dopo averlo misurato sui Comuni, definisce inaffidabile — l'intestazione
 * diceva 26/02 mentre il foglio diceva 21/02, e i due valori non erano nemmeno stabili fra client.
 *
 * ## Nessuna foreign key verso qui, come per i Comuni
 *
 * `fornitori.codice_ateco` resta una colonna di testo e nessun vincolo la lega a questa tabella. È
 * la stessa scelta dei Comuni e la stessa ragione: una classificazione invecchia, e un vincolo
 * impedirebbe di scrivere il codice giusto proprio quando l'elenco è indietro di una revisione.
 *
 * ## Idempotenza
 *
 * Guardia sulla sola esistenza della tabella: non ci sono foreign key da aggiungere separatamente.
 * Va nel dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('codici_ateco')) {
            return;
        }

        Schema::create('codici_ateco', function (Blueprint $table) {
            $table->id();

            // La chiave naturale. Univoca **dentro una revisione**: se un domani convivessero due
            // classificazioni, l'unicità andrebbe spostata sulla coppia con `versione_fonte`.
            // Oggi la tabella ne ospita una sola, e l'aggiornamento la riscrive per intero.
            $table->string('codice', 12)->unique();

            $table->string('titolo');

            // ISTAT pubblica anche l'inglese nello stesso file: costa una colonna e serve a chi usa
            // il programma in inglese, che è una delle quattro lingue.
            $table->string('titolo_en')->nullable();

            // 1 = sezione … 6 = sottocategoria. Serve a mostrare l'albero e a filtrare: chi cerca un
            // fornitore vuole quasi sempre una sottocategoria, non una sezione.
            $table->unsignedTinyInteger('livello');

            // Il codice del livello superiore, come lo dà la fonte. È una stringa e non una foreign
            // key su `id`: così l'aggiornamento può scrivere le righe in qualunque ordine senza
            // dover risolvere i padri prima dei figli.
            $table->string('codice_padre', 12)->nullable();

            // L'ordine dell'albero, dato dalla fonte: permette di elencare i codici nella posizione
            // gerarchica giusta senza ricostruirla ordinando le stringhe (che metterebbe `01.11.00`
            // prima di `01.2`).
            $table->unsignedInteger('ordine');

            // La forma su cui si cerca: codice e titolo, minuscoli, senza accenti. Stessa ragione di
            // `comuni.nome_ricerca` — i test girano su SQLite e la produzione su MySQL, e le due non
            // trattano accenti e maiuscole allo stesso modo.
            $table->string('testo_ricerca');

            // Il timbro: «ATECO 2025». Obbligatorio — una riga che non sa da quale classificazione
            // viene non è interrogabile con onestà.
            $table->string('versione_fonte', 40);

            // Facoltativa, e solo se dichiarata da chi importa: vedi sopra.
            $table->date('fonte_al')->nullable();

            $table->timestamps();

            // ⚠️ Due soli indici, e i due che mancano sono stati **tolti dopo averli misurati**.
            // `testo_ricerca` non è servibile da un `LIKE '%…%'` — il jolly iniziale esclude il
            // B-tree per costruzione — ed era il più grosso della tabella (67 byte medi × 3.257).
            // `codice_padre` non è filtrato da nessuna interrogazione: la gerarchia si risale con
            // `whereIn('codice', …)`, che usa l'unique. Il giorno che la ricerca dovesse andare
            // veloce su un elenco più grande la strada è un FULLTEXT, non questi.
            $table->index('livello');
            $table->index('ordine');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('codici_ateco');
    }
};
