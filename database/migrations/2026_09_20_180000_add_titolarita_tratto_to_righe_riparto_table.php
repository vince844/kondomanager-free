<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 11 (1.11.0-beta.31): il **tratto** di titolarità della riga, non solo il conteggio.
 *
 * La migrazione 6 congela `giorni_titolarita`: quanti giorni del periodo la riga copre. Non dice **quali**.
 * La verifica indipendente delle correzioni della Fase 1-bis (20/09/2026, rilievi L1-1, L1-2, L1-4) ha
 * mostrato che il conguaglio del passaggio, dovendo dividere una quota già pro rata, era costretto ad
 * assumere che quei giorni fossero «in coda» al periodo — falso per la riga di chi è uscito prima della
 * generazione, per la quota di un predecessore già tagliata alla sua uscita, per due righe della stessa
 * persona sullo stesso conto (ha cambiato quota nell'anno). Con il tratto congelato la divisione è
 * `tratto ∩ competenza` diviso alla decorrenza, per ogni riga, senza ipotesi.
 *
 * `titolarita_dal`/`titolarita_al`: il tratto effettivo della riga (D7: `data_inizio` se ha un predecessore,
 * altrimenti l'inizio del periodo; `data_fine` se c'è, altrimenti la fine), ritagliato sull'estensione della
 * competenza; per una riga di ripiego (decisione 22) gli estremi dei giorni scoperti che ha preso. Nulli
 * sulle righe atemporali e su quelle scritte prima di questa migrazione (il conguaglio le divide come prima).
 *
 * Rieseguibile: guardia `hasColumn` per colonna; nessuna FK. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('righe_riparto')) {
            return;
        }

        Schema::table('righe_riparto', function (Blueprint $table) {
            if (! Schema::hasColumn('righe_riparto', 'titolarita_dal')) {
                $table->date('titolarita_dal')->nullable()->after('giorni_titolarita')
                    ->comment('Primo giorno del tratto di titolarità che questa riga copre, dentro la competenza (B2, S8-bis). Nullo = riga atemporale o anteriore.');
            }
            if (! Schema::hasColumn('righe_riparto', 'titolarita_al')) {
                $table->date('titolarita_al')->nullable()->after('titolarita_dal')
                    ->comment('Ultimo giorno del tratto di titolarità che questa riga copre, dentro la competenza (B2, S8-bis).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('righe_riparto')) {
            return;
        }
        Schema::table('righe_riparto', function (Blueprint $table) {
            foreach (['titolarita_al', 'titolarita_dal'] as $c) {
                if (Schema::hasColumn('righe_riparto', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
