<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `Ufficio` era classificato come **unità abitativa**, e va corretto sulle installazioni esistenti.
 *
 * ## Perché il seeder da solo non basta
 *
 * `TipologieImmobiliSeeder` usa `firstOrCreate(['nome' => …])`: su un database dove la tipologia
 * esiste già **non tocca la categoria**, per costruzione. Correggere la riga nel seeder ripara le
 * installazioni future e lascia intatte tutte quelle in essere — cioè tutte quelle vere.
 *
 * ## Perché non è pignoleria
 *
 * L'errore nasceva da un duplicato: `Ufficio` era dichiarato due volte, `unita_abitativa` prima e
 * `unita_non_abitativa` poi, e vinceva il primo. La distinzione fra abitativo e non abitativo non
 * è decorativa: è quella che serve alla comunicazione delle spese sulle parti comuni, dove
 * l'abitazione principale e le sue pertinenze si accorpano e il resto no. Un ufficio contato fra
 * le abitazioni è un dato che oggi non fa danno e domani sì.
 *
 * ## Perché tocca **solo** `Ufficio`
 *
 * Gli altri due duplicati — `Magazzino` e `Deposito`, dichiarati `unita_non_abitativa` e poi
 * `pertinenza` — non sono un errore: sono un'ambiguità vera del dominio. Un deposito che serve un
 * appartamento è una pertinenza, un magazzino affittato a un'impresa è un'unità autonoma. La
 * categoria non decide se il legame di pertinenza si possa dichiarare — decide solo quanto il
 * campo sia in evidenza — quindi cambiarla toccherebbe una classificazione già vista dagli
 * amministratori per guadagnare un'enfasi. Restano dove sono.
 *
 * ## Idempotenza
 *
 * La `WHERE` sulla categoria vecchia rende la migrazione rieseguibile senza effetti: al secondo
 * giro non trova righe da aggiornare. E non tocca una tipologia che l'amministratore avesse
 * riclassificato a mano in qualcosa di diverso da `unita_abitativa`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $corrette = DB::table('tipologie_immobili')
            ->where('nome', 'Ufficio')
            ->where('categoria', 'unita_abitativa')
            ->update(['categoria' => 'unita_non_abitativa']);

        if ($corrette > 0) {
            // A log e non in silenzio: è una modifica a dati esistenti, e la regola del progetto
            // è che una riparazione non ispezionabile non è riparabile a mano.
            \Illuminate\Support\Facades\Log::info(
                "Migrazione: tipologia «Ufficio» riclassificata da «unita_abitativa» a «unita_non_abitativa» ({$corrette} riga)."
            );
        }
    }

    /**
     * ⚠️ **Non fa niente, e la prima stesura faceva peggio di niente.**
     *
     * Il commento diceva «si riporta indietro solo ciò che questa migrazione ha toccato, con lo
     * stesso criterio», ma il criterio **non distingue** ciò che `up()` ha corretto da ciò che era
     * già giusto. Le due popolazioni finiscono nello stesso stato — `Ufficio` con categoria
     * `unita_non_abitativa` — e da lì non sono più separabili.
     *
     * Su un'installazione nuova la conseguenza è che il rollback *introduce* il difetto: il seeder
     * della beta.53 crea `Ufficio` già come `unita_non_abitativa`, `up()` non trova nulla da
     * correggere ed è un'operazione vuota, ma `down()` prende quella riga sana e la sposta su
     * `unita_abitativa`. Una migrazione all'indietro che sporca un database che non era mai stato
     * sporco è l'esatto contrario di ciò che un rollback promette.
     *
     * Restare fermi è l'unica inversione onesta. La categoria giusta di un ufficio è
     * `unita_non_abitativa` in entrambi i versi: `up()` la ripristina, `down()` non ha motivo di
     * disfarla. È la stessa scelta della pivot delle pertinenze, che non si ricrea.
     */
    public function down(): void
    {
        // Volutamente vuoto.
    }
};
