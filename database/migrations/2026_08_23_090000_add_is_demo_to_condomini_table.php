<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca i condomini creati dal programma a scopo dimostrativo.
 *
 * ## Perché serve una colonna, e non basta il nome
 *
 * Dalla beta.71 la pagina di creazione offre un pulsante che costruisce un **condominio
 * dimostrativo**: struttura, millesimi, preventivo, piano rate, incassi, fatture, fondi. Serve a
 * chi installa KondoManager e vuole capire cosa fa senza ribattere tutto a mano.
 *
 * Quel condominio deve poter essere **rimosso**, e qui sta il punto: i vincoli del database
 * impediscono di eliminare un condominio che ha movimenti contabili — `pagamenti_fornitori`,
 * `deleghe_f24` e `casse` sono in `ON DELETE RESTRICT`, ed è giusto così. Ma il condominio
 * dimostrativo **non è la contabilità di nessuno**: quei dati li ha scritti il programma, e
 * cancellarli non butta via niente di reale.
 *
 * ⚠️ **Perché non riconoscerlo dal codice identificativo.** Il seeder scrive `DEMO-<timestamp>`, e
 * bastava un `LIKE 'DEMO-%'`. Ma è fragile in un modo che costa caro: un amministratore può
 * chiamare così un condominio **suo** — «DEMO-Rossi» per una simulazione di preventivo — e
 * ritrovarselo fra quelli eliminabili in blocco. Un criterio di cancellazione non si deduce da una
 * stringa che l'utente può scrivere.
 *
 * ## Cosa NON fa
 *
 * Non tocca nessun condominio esistente: il valore predefinito è `false`, e i condomini già a
 * database restano quello che sono. Nessuna riga viene riscritta.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⚠️ Guardia sulla colonna: su MySQL il DDL non è transazionale, e questa migrazione deve
        // poter girare due volte senza rompersi — è la regola del progetto, presidiata da
        // `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
        if (! Schema::hasColumn('condomini', 'is_demo')) {
            Schema::table('condomini', function (Blueprint $table) {
                $table->boolean('is_demo')
                    ->default(false)
                    ->after('codice_identificativo')
                    ->comment('Condominio costruito dal programma a scopo dimostrativo: eliminabile anche con movimenti contabili');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('condomini', 'is_demo')) {
            Schema::table('condomini', function (Blueprint $table) {
                $table->dropColumn('is_demo');
            });
        }
    }
};
