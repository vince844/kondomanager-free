<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Una chiave stabile per le categorie dei documenti che il **codice** deve saper ritrovare.
 *
 * ## Il difetto che chiude — Coda 106
 *
 * `FatturaPassivaService` cercava la categoria degli allegati **per etichetta**:
 * `CategoriaDocumento::where('name', 'Fatture')->first()`. L'etichetta però è dell'amministratore,
 * che può rinominarla quando vuole — `UpdateCategoriaRequest` valida il solo
 * `required|string|max:255`. Da quel momento la ricerca non trova più niente e il servizio scrive
 * `category_id` a **null**: ogni allegato di fattura entra in archivio **senza categoria**, non
 * compare in nessuna vista per categoria, e nessun messaggio lo dice.
 *
 * ⚠️ **La cancellazione era protetta per caso, la rinomina non lo era affatto.**
 * `CategoriaDocumentoController::destroy()` rifiuta se la categoria ha documenti, quindi «Fatture»
 * in uso non si poteva cancellare; ma rinominarla riusciva sempre — ed è l'azione più probabile
 * delle due.
 *
 * ## Perché uno slug e non un `di_sistema`
 *
 * Un booleano direbbe *«questa è di sistema»* senza dire **quale**: il codice deve poter chiedere
 * «la categoria delle fatture», non «una qualunque delle protette». Serve un discriminante, e uno
 * slug è la forma più piccola che ce l'ha.
 *
 * ⚠️ **Non è mostrato e non è modificabile**: non sta nel `$fillable` del modello e non compare in
 * nessun modulo. È deliberato — l'etichetta resta libera, ed è tutto il punto di questa migrazione.
 *
 * ## Additiva, e senza effetti su chi già funziona
 *
 * La colonna è **annullabile**: tutte le categorie esistenti e tutte quelle che l'amministratore
 * creerà restano con `slug` nullo, e non cambia niente per loro. Il backfill tocca **una riga sola**,
 * quella che si chiama esattamente «Fatture».
 *
 * ⚠️ **Su un'installazione dove la rinomina è già avvenuta il backfill non trova nulla**, e il
 * comportamento resta identico a oggi: non peggiora, ma non si ripara da solo. Ripararlo
 * richiederebbe un modo per dire «questa è la categoria delle fatture» dall'interfaccia, che è una
 * decisione più grande e non sta in questa beta.
 *
 * Va nel dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categorie_documento')) {
            return;
        }

        // ⚠️ Guardia sulla colonna, separata da quella sul dato: su MySQL il DDL non è
        // transazionale, quindi un'esecuzione morta fra i due passi lascerebbe la colonna senza il
        // backfill, e un secondo tentativo deve poter fare solo la metà che manca.
        if (! Schema::hasColumn('categorie_documento', 'slug')) {
            Schema::table('categorie_documento', function (Blueprint $tabella) {
                $tabella->string('slug', 60)->nullable()->after('name');
            });
        }

        // Il backfill: la riga che oggi si chiama «Fatture» prende la chiave stabile. Se qualcuno
        // l'ha già rinominata non c'è niente da agganciare, e la migrazione non inventa: lascia
        // tutto com'è.
        DB::table('categorie_documento')
            ->where('name', 'Fatture')
            ->whereNull('slug')
            ->update(['slug' => 'fatture']);
    }

    /**
     * ⚠️ **Il `down()` toglie la colonna, e qui si può.**
     *
     * A differenza delle migrazioni che scrivono categorie, questa non porta via nessun dato
     * dell'amministratore: lo slug è una chiave nostra, non sua. Togliendola si torna esattamente
     * allo stato precedente, con la ricerca per etichetta che riprende a fare da sola — e infatti
     * il servizio la tiene come ripiego proprio per questo.
     */
    public function down(): void
    {
        if (Schema::hasColumn('categorie_documento', 'slug')) {
            Schema::table('categorie_documento', function (Blueprint $tabella) {
                $tabella->dropColumn('slug');
            });
        }
    }
};
