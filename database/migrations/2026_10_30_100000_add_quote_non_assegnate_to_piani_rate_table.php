<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coda 414 (1.11.0-beta.59, decisione 153.6): le quote non assegnate accettate con «Procedi comunque», registrate alla generazione del piano.
 *
 * Una colonna JSON nullable su `piani_rate`: l'elenco delle voci (chiave, motivo, unità, voce, tabella, cifra in centesimi) e lo scarto della
 * generazione, scritti da `GeneratePianoRateAction` e letti da `PianoRateQuoteService::statoAllineamento()`. Nessun dato già scritto si
 * riscrive: i piani accettati prima di questa versione restano con la colonna vuota, e si registrano al primo ricalcolo.
 *
 * Una sola colonna senza chiave esterna: la guardia è una, sulla colonna, e rieseguire `up()` dopo un'interruzione non fa niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('piani_rate', 'quote_non_assegnate')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->json('quote_non_assegnate')->nullable()->after('nota_scoperti');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('piani_rate', 'quote_non_assegnate')) {
            Schema::table('piani_rate', function (Blueprint $table) {
                $table->dropColumn('quote_non_assegnate');
            });
        }
    }
};
