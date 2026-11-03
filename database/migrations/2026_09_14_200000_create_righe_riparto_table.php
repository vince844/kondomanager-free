<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il dettaglio del riparto, scritto una volta alla generazione (1.11.0-beta.29).
 *
 * `rate_quote` congela solo il totale di ogni (anagrafica, immobile) per rata; `regole_calcolo`
 * è uno snapshot degli importi e dei parametri, non del calcolo. Il motore, nel punto in cui
 * decide quanto spetta a chi, sa conto, tabella, valore del millesimo, ruolo risolto, quota di
 * possesso, riga di fattura — e a `distribuisciSuTabelle()` li fonde in un peso solo, per sempre.
 * Le due stampe del riparto ricostruivano tutto questo **al momento della stampa**, ognuna con
 * un'aritmetica sua: un contributo registrato dopo l'emissione faceva dichiarare uno sconto che
 * nessuna quota aveva avuto, e i centesimi che le due ricostruzioni non sapevano spiegare
 * finivano in una colonna «Fuori riparto» (Code 77, 78).
 *
 * Questa tabella è il registro: **una riga per componente** — (piano, soggetto, unità, conto
 * foglia, tabella × ruolo) per il millesimale, la riga di fattura per l'ad personam, il conto
 * per il già versato — scritta insieme alle quote nella stessa transazione, mai ricalcolata.
 * Per ogni soggetto del piano la somma delle righe è esattamente la quota pura di gestione.
 *
 * ## Le scelte, e perché
 *
 * - **Per piano, non per rata.** Le quote nascono in bulk senza id e la fettina per rata non è
 *   additiva al centesimo (tre conti da 1 cent su due rate danno 1+1+1 sulla prima, mentre la
 *   fettina del totale 3 dà 2 e 1): un dettaglio per rata non sommerebbe alla quota della rata.
 *   Le stampe sommano comunque tutte le rate. La rata resta derivata.
 * - **`nullOnDelete` verso conti e tabelle, e il nome congelato accanto.** Un conto di un piano
 *   in bozza è cancellabile: la riga deve sopravvivere al conto, come fanno le deleghe F24 col
 *   nome del fornitore. `cascadeOnDelete` da `piani_rate`: il dettaglio è del piano, come le rate.
 * - **La radice congelata** (`conto_radice_id`): la stampa per capitolo aggrega sulla radice, e
 *   spostare un sottoconto dopo l'assemblea non deve spostare una colonna di un documento
 *   registrato.
 * - **Nessun UNIQUE composito**: `tabella_id`, `ruolo_richiesto`, `riga_fattura_id` sono nullable
 *   e i NULL non collidono in un indice unico. L'unicità la garantisce la scrittura atomica.
 * - **Niente `softDeletes`**: è un registro, come le deleghe.
 *
 * Rieseguibile: `create` dentro `hasTable`, foreign key nella stessa `create` (tabella nuova:
 * nessuno stato parziale possibile). Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('righe_riparto')) {
            return;
        }

        Schema::create('righe_riparto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piano_rate_id')->constrained('piani_rate')->cascadeOnDelete();
            // riparto | netting | ad_personam | quota_zero
            $table->string('tipo', 20);
            // NULL solo per `quota_zero`: lo zero è dell'unità, non di un soggetto.
            $table->foreignId('anagrafica_id')->nullable()->constrained('anagrafiche')->cascadeOnDelete();
            $table->foreignId('immobile_id')->nullable()->constrained('immobili')->nullOnDelete();
            // Il conto **foglia** su cui il motore ha ripartito; NULL per `quota_zero`.
            $table->foreignId('conto_id')->nullable()->constrained('conti')->nullOnDelete();
            $table->string('conto_nome')->nullable();
            $table->foreignId('conto_radice_id')->nullable()->constrained('conti')->nullOnDelete();
            $table->string('conto_radice_nome')->nullable();
            // NULL per `netting` e `ad_personam`.
            $table->foreignId('tabella_id')->nullable()->constrained('tabelle')->nullOnDelete();
            $table->string('tabella_nome')->nullable();
            $table->string('tabella_quota', 20)->nullable();
            $table->decimal('coefficiente', 5, 2)->nullable();
            // NULL = non compilato, 0 = non partecipa: i due stati dello zero documentato.
            $table->decimal('valore_millesimo', 12, 5)->nullable();
            $table->decimal('somma_valori', 14, 5)->nullable();
            $table->string('ruolo_richiesto', 30)->nullable();
            // varchar e non l'enum a tre valori della pivot: il risolto può essere `nuda_proprietario`.
            $table->string('ruolo_risolto', 30)->nullable();
            $table->decimal('quota_possesso', 5, 2)->nullable();
            // Solo `ad_personam`: la riga di fattura di quell'unità.
            $table->foreignId('riga_fattura_id')->nullable()->constrained('righe_fattura')->nullOnDelete();
            $table->string('riga_descrizione')->nullable();
            // Centesimi con segno: `riparto` col segno del conto, `netting` negativo, `quota_zero` 0.
            $table->bigInteger('importo');
            $table->string('versione_calcolo', 20);
            $table->timestamps();

            $table->index(['piano_rate_id', 'anagrafica_id', 'immobile_id'], 'idx_righe_riparto_soggetto');
            $table->index(['piano_rate_id', 'tipo'], 'idx_righe_riparto_tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('righe_riparto');
    }
};
