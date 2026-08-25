<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allarga gli enum di `conti_contabili` per accogliere costi e ricavi.
 *
 * ## Perché c'è un ramo per gli altri driver (aggiunto il 06/08/2026)
 *
 * La prima stesura faceva `MODIFY COLUMN … ENUM(…)` **solo su MySQL** e su ogni altro driver
 * non faceva niente. In produzione è corretto — Kondomanager gira solo su MySQL — ma il
 * database dei **test** è SQLite, costruito dalle migrazioni: lì gli enum sono rimasti quelli
 * originali, `('attivo','passivo')` e `('liquidita','crediti','debiti','fondi')`, e SQLite li
 * applica come vincoli CHECK.
 *
 * L'effetto è che **nessun test poteva creare un conto di costo o di ricavo**. Non un caso
 * limite: l'intero lato economico del piano dei conti era non rappresentabile in prova, e con
 * lui `CondominioService::ensureDefaultConti()` — cioè la procedura con cui l'applicazione
 * costruisce il piano dei conti di ogni condominio nuovo. Nessuna suite la esercitava, perché
 * non poteva.
 *
 * È la lezione della beta.34 («uno schema di prova più severo del vero non protegge: nasconde,
 * perché rende non scrivibili proprio i test dei casi che in produzione accadono»), qui su una
 * famiglia intera invece che su un caso singolo. Trovata dall'importatore della beta.47, che
 * chiama `ensureDefaultConti` per non lasciare in archivio condomìni senza piano dei conti.
 *
 * Il ramo `else` **non tocca MySQL**: converte le due colonne in stringhe sui driver che non
 * hanno gli ENUM, che è come SQLite le rappresenta comunque una volta tolto il CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('conti_contabili', function (Blueprint $table) {
                $table->string('tipo', 20)->nullable(false)->change();
                $table->string('categoria', 20)->nullable(false)->change();
            });

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN tipo ENUM(
                'attivo',
                'passivo',
                'costo',
                'ricavo'
            ) NOT NULL");

            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN categoria ENUM(
                'liquidita',
                'crediti',
                'debiti',
                'fondi',
                'costi',
                'ricavi'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN tipo ENUM(
                'attivo',
                'passivo'
            ) NOT NULL");

            DB::statement("ALTER TABLE conti_contabili MODIFY COLUMN categoria ENUM(
                'liquidita',
                'crediti',
                'debiti',
                'fondi'
            ) NOT NULL");
        }
    }
};