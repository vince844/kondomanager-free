<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quante righe vuole vedere ciascuno, elenco per elenco.
 *
 * ## Perché una tabella e non una colonna JSON su `users`
 *
 * La forma ovvia sarebbe una `preferenze_tabelle` JSON su `users`. È stata scartata per tre
 * ragioni, tutte emerse prima di scrivere il codice:
 *
 * - **`users` viaggia intero a ogni richiesta autenticata**, dentro `auth.user`. Appendere lì un
 *   blob che cresce con il numero di elenchi visitati significa pagarlo su ogni pagina, comprese
 *   le ventisei che di preferenze non sanno niente.
 * - **Due schede aperte si sovrascrivono a vicenda.** Con un blob unico ogni salvataggio è un
 *   leggi-modifica-riscrivi: la scheda che salva per seconda riscrive l'intero oggetto letto
 *   *prima* che l'altra salvasse, e la preferenza dell'altra tabella sparisce. Con una riga per
 *   coppia utente-elenco le due scritture non si incontrano.
 * - **Le righe preesistenti avrebbero `NULL`**, e un cast `array` su `NULL` non è `[]`: la forma
 *   `array_merge($user->preferenze, [...])` darebbe errore *solo* agli utenti che aggiornano da
 *   una versione precedente, cioè a tutti tranne che in sviluppo.
 *
 * ## Rieseguibile
 *
 * `CREATE TABLE` sola, protetta da `hasTable`, con la foreign key dentro la stessa `create` — su
 * una tabella nuova non si pone il problema MySQL delle guardie separate per colonna e per
 * vincolo, che riguarda gli `ALTER`. Una `CREATE` interrotta non lascia dati a metà.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('preferenze_tabelle_utente')) {
            return;
        }

        Schema::create('preferenze_tabelle_utente', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Il nome della rotta dell'elenco — `admin.gestionale.immobili.index` — più un
            // eventuale suffisso per le pagine che mostrano due elenchi. È l'identità di «questa
            // tabella» vista dal programma, e non richiede una dichiarazione in ventisei file.
            $table->string('chiave', 191);

            $table->unsignedSmallInteger('per_page');

            $table->timestamps();

            // ⚠️ Una riga per coppia. Senza questo vincolo due richieste in volo insieme —
            // due schede, o un doppio clic sul selettore — inserirebbero due righe per lo stesso
            // elenco, e da lì in poi quale delle due vince dipende dall'ordine di lettura.
            $table->unique(['user_id', 'chiave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preferenze_tabelle_utente');
    }
};
