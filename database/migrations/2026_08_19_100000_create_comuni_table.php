<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'elenco dei Comuni italiani con il loro codice catastale.
 *
 * ## Perché una tabella e non un file letto a runtime
 *
 * Sono 7.894 righe e si cercano per nome. Un file JSON caricato a ogni richiesta significherebbe
 * decodificare 855 KB e scandire un array di 7.894 voci per ogni carattere digitato; una tabella
 * con due indici risponde senza scandire niente. Il file resta la **fonte** — viaggia col codice —
 * e questa tabella è la sua forma interrogabile.
 *
 * ## `codice_catasto` è la chiave naturale, misurata sulla fonte
 *
 * Sulle 7.894 righe ISTAT il codice catastale è sempre presente, sempre di quattro caratteri e
 * **mai duplicato**. È quindi la chiave su cui l'aggiornamento fa `updateOrCreate`, e l'unicità qui
 * è il presidio a database di quella misura: se una fonte futura la smentisse, l'aggiornamento si
 * fermerebbe invece di scrivere due comuni con lo stesso codice.
 *
 * ## `fonte_al` sta su ogni riga, e non è ridondanza inutile
 *
 * È la data che **ISTAT dichiara di sé** — la scrive nel nome del foglio dell'XLSX, «CODICI al
 * 21_02_2026» — e non la data in cui abbiamo scaricato il file. Sta sulla riga e non in un unico
 * posto perché un aggiornamento parziale (o un elenco caricato a mano con `--da`) lascia righe di
 * provenienze diverse, e in quel caso avere una data sola direbbe il falso su metà tabella.
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
        if (Schema::hasTable('comuni')) {
            return;
        }

        Schema::create('comuni', function (Blueprint $table) {
            $table->id();

            // Il codice catastale del comune, detto anche codice Belfiore: quattro caratteri, «H501»
            // per Roma. È il dato che nessuno ricorda a memoria ed è la ragione per cui questa
            // tabella esiste.
            $table->string('codice_catasto', 4)->unique();

            $table->string('nome');

            // La seconda denominazione dei comuni bilingui: «Aldein» accanto ad «Aldino». Sono 124
            // sulla fonte, e chi li cerca in tedesco deve trovarli.
            $table->string('nome_altra_lingua')->nullable();

            // La forma su cui si cerca: nome e seconda denominazione, minuscoli, senza accenti e
            // senza apostrofi. Non è ridondanza — è ciò che permette di trovare «Forlì» scrivendo
            // «Forli» e «Sant'Agata» scrivendo «Sant Agata», **senza dipendere dalla collation del
            // database**: i test girano su SQLite e la produzione su MySQL, e le due non trattano
            // accenti e maiuscole allo stesso modo. La regola di normalizzazione sta in un posto
            // solo, `Comune::normalizza()`, ed è la stessa che si applica a ciò che l'utente scrive.
            $table->string('nome_ricerca');

            $table->string('sigla', 2);
            $table->string('provincia');
            $table->string('regione');

            $table->date('fonte_al');

            $table->timestamps();

            $table->index('nome');
            $table->index('nome_ricerca');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comuni');
    }
};
