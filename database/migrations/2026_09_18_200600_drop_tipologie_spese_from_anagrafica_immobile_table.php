<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2, drop (1.11.0-beta.31): via `anagrafica_immobile.tipologie_spese`, il campo che fingeva.
 *
 * Decisione 8 del progetto (12/08/2026): scritto dal controller da `validated()` senza che nessuna
 * FormRequest lo validasse — quindi sempre `NULL` — e mai letto. Le tre scritture sono cadute nella
 * 1.10.0-beta.50; la colonna cade qui, «insieme alle altre migrazioni della 1.11». Il bisogno che
 * avrebbe dovuto servire (limitare un soggetto a certe voci) ha già un progetto diverso e migliore in
 * `evoluzione_anagrafica_e_motore_riparto.md`.
 *
 * ⚠️ Prima di togliere si **conta**: se un'installazione avesse valori (nel database di sviluppo sono
 * 0 su 105), la migrazione si ferma con un messaggio invece di cancellare dati in silenzio — «non si
 * tira a indovinare» vale anche per un drop. `down()` non ricrea la colonna: era vuota.
 *
 * Rieseguibile: `hasColumn`. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anagrafica_immobile') || ! Schema::hasColumn('anagrafica_immobile', 'tipologie_spese')) {
            return;
        }

        $valorizzate = DB::table('anagrafica_immobile')->whereNotNull('tipologie_spese')->count();
        if ($valorizzate > 0) {
            throw new RuntimeException(
                "anagrafica_immobile.tipologie_spese ha {$valorizzate} righe valorizzate: la colonna era ritenuta vuota (decisione 8 del progetto sul subentro). "
                .'La migrazione si ferma per non cancellare dati: esportarli e decidere prima di rilanciarla.'
            );
        }

        Schema::table('anagrafica_immobile', fn (Blueprint $table) => $table->dropColumn('tipologie_spese'));
    }

    public function down(): void
    {
        // La colonna era NULL su ogni riga: non c'è niente da ricreare.
    }
};
