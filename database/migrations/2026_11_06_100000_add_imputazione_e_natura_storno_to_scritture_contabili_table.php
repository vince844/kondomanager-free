<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.3 (decisioni 217 e 218): due fatti che solo l'amministratore conosce, scritti sull'incasso.
 *
 * - `imputazione` e `imputazione_nota`: a che cosa va un versamento. `ordine_scadenze` (l'ordine che il programma propone),
 *   `indicata_dal_versante`, `scelta_amministratore`, `fuori_ordine` (non dichiarata), `compensazione` (solo credito); la nota fino a
 *   255 caratteri. Le scrive il salvataggio dell'incasso (`StoreIncassoRateAction`).
 * - `natura_storno`: perché un incasso è stato stornato, sull'incasso stornato. `errore_registrazione` (il versamento non c'è mai
 *   stato), `versamento_tornato` (c'era ed è tornato indietro). La scrive lo storno (`StornoIncassoRateAction`).
 *
 * Nulle per gli incassi e gli storni di prima e per ogni altra scrittura: nessun dato già scritto si riscrive. Tre colonne senza
 * chiave esterna: una guardia per colonna, perché su MySQL ognuna è uno statement a sé e il DDL non è transazionale.
 */
return new class extends Migration
{
    private const COLONNE = ['imputazione', 'imputazione_nota', 'natura_storno'];

    public function up(): void
    {
        if (! Schema::hasColumn('scritture_contabili', 'imputazione')) {
            Schema::table('scritture_contabili', function (Blueprint $table) {
                $table->string('imputazione', 30)->nullable()->after('stato');
            });
        }

        if (! Schema::hasColumn('scritture_contabili', 'imputazione_nota')) {
            Schema::table('scritture_contabili', function (Blueprint $table) {
                $table->string('imputazione_nota', 255)->nullable()->after('imputazione');
            });
        }

        if (! Schema::hasColumn('scritture_contabili', 'natura_storno')) {
            Schema::table('scritture_contabili', function (Blueprint $table) {
                $table->string('natura_storno', 30)->nullable()->after('imputazione_nota');
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLONNE as $colonna) {
            if (Schema::hasColumn('scritture_contabili', $colonna)) {
                Schema::table('scritture_contabili', function (Blueprint $table) use ($colonna) {
                    $table->dropColumn($colonna);
                });
            }
        }
    }
};
