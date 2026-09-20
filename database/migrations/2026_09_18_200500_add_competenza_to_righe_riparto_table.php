<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2, migrazione 6 (1.11.0-beta.31): il congelato temporale sta in `righe_riparto`, per riga.
 *
 * Decisione 15 del progetto: periodo di competenza e gradino sono proprietà della **spesa** (variano
 * per conto), i giorni sono del **soggetto sul conto** — la grana che `righe_riparto` già persiste
 * (soggetto × unità × conto foglia × componente). Quattro colonne nullable: **nullo = riga atemporale**,
 * cioè il comportamento di oggi e di D8 (nessun cambio di titolarità nel periodo → `quota / somma_quote`).
 * Si fa adesso perché il registro è nato il 15/09 ed è quasi vuoto: aggiungere colonne dopo è una
 * migrazione su dati vivi.
 *
 * `gradino_competenza` porta il valore di `App\Services\Riparto\GradinoCompetenza` (`dichiarata` ·
 * `delibera` · `capitolo` · `gestione` · `esercizio`). Con più tratti (decisione 20) `competenza_dal`/`al`
 * sono gli estremi dell'insieme e `giorni_titolarita` è la somma sui tratti: il dettaglio dei tratti
 * sta in `competenze_capitolo`.
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
            if (! Schema::hasColumn('righe_riparto', 'competenza_dal')) {
                $table->date('competenza_dal')->nullable()->after('quota_possesso')
                    ->comment('Primo giorno del periodo di competenza usato per questa riga (B2). Nullo = riga atemporale.');
            }
            if (! Schema::hasColumn('righe_riparto', 'competenza_al')) {
                $table->date('competenza_al')->nullable()->after('competenza_dal')
                    ->comment('Ultimo giorno del periodo di competenza usato per questa riga (B2).');
            }
            if (! Schema::hasColumn('righe_riparto', 'gradino_competenza')) {
                $table->string('gradino_competenza', 16)->nullable()->after('competenza_al')
                    ->comment('dichiarata · delibera · capitolo · gestione · esercizio (GradinoCompetenza).');
            }
            if (! Schema::hasColumn('righe_riparto', 'giorni_titolarita')) {
                $table->unsignedInteger('giorni_titolarita')->nullable()->after('gradino_competenza')
                    ->comment('Giorni di titolarità del soggetto dentro il periodo, sommati sui tratti (D7).');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('righe_riparto')) {
            return;
        }
        Schema::table('righe_riparto', function (Blueprint $table) {
            foreach (['giorni_titolarita', 'gradino_competenza', 'competenza_al', 'competenza_dal'] as $c) {
                if (Schema::hasColumn('righe_riparto', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
