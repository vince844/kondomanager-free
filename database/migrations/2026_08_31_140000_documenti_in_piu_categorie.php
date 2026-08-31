<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un documento può stare in **più categorie**, non più in una sola.
 *
 * ## Da dove viene
 *
 * Richiesta arrivata dal forum: *«sarebbe utile poter assegnare a un documento archiviato più
 * categorie, attualmente è possibile associarlo a un'unica categoria. In alcuni casi aiuterebbe la
 * ricerca dei documenti»*. Il filtro dell'archivio accetta già **un array** di categorie, quindi
 * cercarne più d'una insieme si poteva da sempre: quello che mancava era che un documento potesse
 * *starci* in più d'una.
 *
 * ## ⚠️ Perché la colonna sparisce invece di restare «per sicurezza»
 *
 * L'istinto è tenere `documenti.category_id` durante la transizione. **È la scelta più pericolosa
 * delle due**, e il motivo è tutto in cosa succede a un punto del codice che nessuno ha convertito:
 *
 * - se la colonna **resta**, quel punto continua a funzionare e legge un dato **parziale o vecchio**.
 *   Nessun errore, nessun log: divergenza silenziosa. È la classe di difetto che questo ciclo ha
 *   pagato tre volte — la categoria che nessuna schermata mostrava, il pulsante che non eliminava,
 *   il controllo che diceva sempre «va bene»;
 * - se la colonna **sparisce**, quel punto **esplode alla prima richiesta**, e lo si trova subito.
 *
 * Rumoroso batte silenzioso. E si sfrutta: togliendo anche la relazione `Documento::categoria()`, la
 * suite dei test diventa **l'inventario dei punti da convertire**, che è più affidabile di un `grep`
 * fatto a mano.
 *
 * ## Il nome della tabella
 *
 * `documento_categoria` e non `categoria_documento`, che è la forma canonica di Laravel: qui
 * sarebbe a **una lettera di distanza** da `categorie_documento`, la tabella delle categorie, e due
 * nomi che si distinguono per una `e` sono una trappola per chi legge una query a colpo d'occhio.
 *
 * ## Cosa NON cambia
 *
 * I documenti di un **soggetto** — fornitore, unità immobiliare, anagrafica — non hanno categoria e
 * continuano a non averla: i loro moduli non la chiedono affatto. ⚠️ Imporre «almeno una categoria»
 * a livello di schema **romperebbe** il caricamento di un documento su un fornitore, che oggi
 * funziona. L'obbligo vive nei quattro moduli dell'archivio, dove è l'utente a scegliere.
 *
 * *(Misurato il 31/08/2026: dei sei documenti a database, i tre senza categoria sono tutti di un
 * soggetto — due unità e un fornitore — e i due d'archivio ce l'hanno entrambi. Non c'è niente da
 * sanare.)*
 *
 * Va nel dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 */
return new class extends Migration
{
    private const PONTE = 'documento_categoria';

    public function up(): void
    {
        if (! Schema::hasTable('documenti') || ! Schema::hasTable('categorie_documento')) {
            return;
        }

        // ── 1. la tabella ponte ─────────────────────────────────────────────────────────────
        if (! Schema::hasTable(self::PONTE)) {
            Schema::create(self::PONTE, function (Blueprint $tabella) {
                $tabella->id();

                $tabella->foreignId('documento_id')
                    ->constrained('documenti')
                    // Cancellato il documento, i suoi legami se ne vanno con lui: non sono un dato
                    // a sé, sono il documento.
                    ->cascadeOnDelete();

                $tabella->foreignId('categoria_documento_id')
                    ->constrained('categorie_documento')
                    // ⚠️ **Rete, non porta.** La cancellazione di una categoria in uso la rifiuta
                    // già `CategoriaDocumentoController::destroy()`, ed è lì che deve stare la
                    // decisione — un rifiuto spiegato vale più di una cascata silenziosa. Questo
                    // `cascade` esiste solo perché il database non resti con righe orfane se quella
                    // guardia venisse aggirata da uno script.
                    ->cascadeOnDelete();

                // La stessa categoria due volte sullo stesso documento non significa niente, e
                // farebbe comparire l'etichetta doppia nella cella.
                $tabella->unique(['documento_id', 'categoria_documento_id'], 'documento_categoria_unico');

                $tabella->timestamps();
            });
        }

        // ── 2. il travaso ───────────────────────────────────────────────────────────────────
        // Solo se la colonna c'è ancora: su una riesecuzione è già sparita, e questo passo va
        // saltato invece di fallire.
        if (Schema::hasColumn('documenti', 'category_id')) {
            $adesso = now();

            DB::table('documenti')
                ->whereNotNull('category_id')
                ->orderBy('id')
                ->chunkById(200, function ($documenti) use ($adesso) {
                    $righe = [];

                    foreach ($documenti as $documento) {
                        // ⚠️ La categoria potrebbe non esistere più: la colonna è `nullOnDelete`,
                        // ma un database toccato a mano può avere un id orfano. Un `insert` con una
                        // chiave esterna morta farebbe fallire l'intero aggiornamento.
                        $esiste = DB::table('categorie_documento')
                            ->where('id', $documento->category_id)
                            ->exists();

                        if (! $esiste) {
                            continue;
                        }

                        $righe[] = [
                            'documento_id'           => $documento->id,
                            'categoria_documento_id' => $documento->category_id,
                            'created_at'             => $adesso,
                            'updated_at'             => $adesso,
                        ];
                    }

                    if ($righe !== []) {
                        // `insertOrIgnore`: se la migrazione è già passata a metà, i legami che ci
                        // sono già non devono far fallire il secondo tentativo.
                        DB::table(self::PONTE)->insertOrIgnore($righe);
                    }
                });

            // ── 3. via la colonna ───────────────────────────────────────────────────────────
            // ⚠️ Da qui in poi, ogni punto del codice non convertito **fallisce forte**. È voluto:
            // vedi il blocco in testa al file.
            Schema::table('documenti', function (Blueprint $tabella) {
                // La chiave esterna va tolta prima della colonna: su MySQL sono due statement
                // distinti, e togliere la colonna con il vincolo ancora attaccato fallisce.
                $tabella->dropConstrainedForeignId('category_id');
            });
        }
    }

    /**
     * Il ritorno indietro: si rimette la colonna e ci si travasa **una** categoria per documento.
     *
     * ⚠️ **È una riduzione con perdita, e va detto.** Un documento che sta in tre categorie ne
     * conserva una sola: si tiene la più vecchia — l'id più basso della tabella ponte, cioè quella
     * assegnata per prima — perché è l'unica scelta che non richiede di indovinare quale conti di
     * più. Le altre si perdono, ed è inevitabile: la colonna può contenere un valore solo.
     */
    public function down(): void
    {
        if (! Schema::hasTable('documenti')) {
            return;
        }

        if (! Schema::hasColumn('documenti', 'category_id')) {
            Schema::table('documenti', function (Blueprint $tabella) {
                $tabella->foreignId('category_id')
                    ->nullable()
                    ->after('description')
                    ->constrained('categorie_documento')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable(self::PONTE)) {
            $prime = DB::table(self::PONTE)
                ->select('documento_id', DB::raw('MIN(categoria_documento_id) as categoria'))
                ->groupBy('documento_id')
                ->get();

            foreach ($prime as $riga) {
                DB::table('documenti')
                    ->where('id', $riga->documento_id)
                    ->update(['category_id' => $riga->categoria]);
            }

            Schema::dropIfExists(self::PONTE);
        }
    }
};
