<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Il numero di vani diventa decimale.
 *
 * ## Origine
 *
 * Segnalazione di un amministratore sul forum, agosto 2026: *«non permette di inserire un numero
 * di vani non intero (e in alcune visure catastali tale valore non è intero) […] non dà errori ma
 * non salva il dato»*. La colonna era `integer` e la regola `integer`: `6,5` faceva fallire la
 * validazione, e con lei l'intera richiesta — in creazione l'unità non nasceva, in modifica non
 * si aggiornava nessun campo.
 *
 * ## Perché `decimal(8,2)` — e perché **non** `decimal(4,1)` né `decimal(5,2)`
 *
 * `docs/evoluzione_anagrafica_e_motore_riparto.md` prevedeva `decimal(4,1)` «per valori come
 * 13,5», scritto però nel 2026 come previsione e non misurando delle visure: la consistenza
 * catastale può presentarsi anche in quarti di vano. Da lì la prima stesura era passata a
 * `decimal(5,2)`.
 *
 * ⚠️ **E `decimal(5,2)` era pericoloso, l'ha trovato la revisione avversariale.** Fino alla
 * beta.61 la regola su questo campo era `sometimes|nullable|integer`: **nessun massimo**. Un
 * amministratore che avesse battuto `1200` — una superficie finita nella casella sbagliata, una
 * cifra di troppo — se l'è vista salvare senza un fiato. Su quella installazione l'`ALTER` verso
 * `decimal(5,2)` non passa: MySQL in `STRICT_TRANS_TABLES` risponde **1264 Out of range**, e
 * l'aggiornamento si ferma a metà — proprio sulla release che non ha ancora il backup automatico.
 *
 * `decimal(8,2)` regge fino a 999.999,99 e costa esattamente uguale. È anche la stessa precisione
 * di `superficie` nella stessa tabella, che è la ragione per cui è la scelta meno sorprendente
 * per chi legge lo schema.
 *
 * ## Cosa NON fa
 *
 * Non tocca nessun dato: `int` → `decimal(8,2)` è un allargamento. Sul database di sviluppo,
 * misurato il 20/08/2026: sei unità valorizzate, da 4 a 6, massimo 6, **zero** fuori intervallo.
 * Su un'installazione di un cliente non lo sappiamo, ed è esattamente il motivo della guardia
 * qui sotto: se un valore impossibile ci fosse, questa migrazione lo **nomina** invece di
 * lasciare che a fermarla sia un codice d'errore del driver.
 *
 * ## Idempotenza
 *
 * Guardia per **colonna** soltanto: su `numero_vani` non insiste nessuna chiave esterna, quindi
 * la doppia guardia colonna/vincolo che questo progetto usa altrove qui non ha un secondo caso da
 * coprire. Rieseguire il `change()` su una colonna già `decimal(8,2)` la riscrive identica —
 * verificato dal dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('immobili', 'numero_vani')) {
            return;
        }

        // ⚠️ **Si guarda prima, e si dice cosa si è trovato.** Un `ALTER` che stringe l'intervallo
        // di una colonna fallisce con `1264 Out of range` su MySQL in modalità stretta, cioè con un
        // messaggio che non nomina né la riga né il valore: chi aggiorna vede l'aggiornamento
        // fermarsi e non ha niente in mano. Con `decimal(8,2)` il caso è remoto — servirebbe un
        // valore oltre 999.999 in un campo che conta le stanze — ma «remoto» non è «impossibile»,
        // e il costo di questa guardia è una query su una tabella piccola.
        $fuoriIntervallo = DB::table('immobili')
            ->whereNotNull('numero_vani')
            ->where(fn ($q) => $q->where('numero_vani', '>', 999999.99)->orWhere('numero_vani', '<', -999999.99))
            ->pluck('numero_vani', 'id');

        if ($fuoriIntervallo->isNotEmpty()) {
            $elenco = $fuoriIntervallo->map(fn ($v, $id) => "unità #{$id} = {$v}")->implode(', ');

            Log::warning('Migrazione vani decimali: valori fuori dall\'intervallo di decimal(8,2)', [
                'unita' => $fuoriIntervallo->toArray(),
            ]);

            throw new RuntimeException(
                "Impossibile convertire «numero vani» a decimale: queste unità hanno un valore "
                ."che non ci sta ({$elenco}). Correggili dalla scheda dell'unità e rilancia "
                ."l'aggiornamento — il dato non è stato toccato."
            );
        }

        Schema::table('immobili', function (Blueprint $table) {
            $table->decimal('numero_vani', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('immobili', 'numero_vani')) {
            return;
        }

        // Il ritorno indietro è volutamente parziale, come in
        // `2026_08_18_100000_rendi_facoltativi_descrizione_e_interno.php`: tornare a `integer`
        // con dei valori decimali a database li **troncherebbe in silenzio**, che è esattamente
        // il difetto che questa migrazione esiste per togliere. Se c'è anche una sola unità con
        // dei decimali, la colonna resta com'è.
        // Il confronto si fa in PHP e non con un `FLOOR()` in SQL: le funzioni matematiche di
        // SQLite sono opzionali e non tutte le build le hanno, e una migrazione inversa che
        // fallisce con «no such function» è peggio di una che non torna indietro.
        $ciSonoDecimali = DB::table('immobili')
            ->whereNotNull('numero_vani')
            ->pluck('numero_vani')
            ->contains(fn ($valore) => (float) $valore !== floor((float) $valore));

        if ($ciSonoDecimali) {
            return;
        }

        Schema::table('immobili', function (Blueprint $table) {
            $table->integer('numero_vani')->nullable()->change();
        });
    }
};
