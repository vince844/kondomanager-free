<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chi ha modificato una comunicazione, una segnalazione o un documento.
 *
 * ## Perché serve
 *
 * La beta.64 aggiunge un avviso di modifica che finisce nella casella di posta di tutto il
 * condominio. Senza questa colonna quell'avviso è costretto a nominare chi ha **creato** l'oggetto,
 * perché è l'unico autore registrato: scrivere «modificata da Tizio» prendendo il creatore sarebbe
 * una bugia su chi ha fatto cosa, detta a un centinaio di persone.
 *
 * E la domanda esiste anche fuori dalla mail. In uno studio associato — dove il condominio è
 * seguito da più persone — *«chi ha cambiato questa comunicazione dopo che era stata pubblicata?»*
 * è una domanda che prima o poi qualcuno fa, e oggi non ha risposta da nessuna parte: non c'è
 * nemmeno nel log applicativo.
 *
 * ## Forma della colonna
 *
 * `bigint unsigned` **nullable**, con chiave esterna verso `users` — identica a `created_by` tranne
 * che per la nullabilità, e per una ragione precisa: le righe già in archivio non sono mai state
 * modificate *da qualcuno che sappiamo*, e riempirle col creatore direbbe il falso. `null` significa
 * «mai modificata, o modificata prima che lo registrassimo», che è esattamente la verità.
 *
 * ⚠️ **Guardie separate per colonna e per vincolo.** Su MySQL sono due statement distinti e il DDL
 * non è transazionale: un'interruzione a metà lascia la colonna senza la chiave, e la riesecuzione
 * deve saperlo riconoscere. È la regola che il progetto si è dato dopo la beta.31.
 */
return new class extends Migration
{
    /** Le tre tabelle che l'avviso di modifica riguarda. */
    private const TABELLE = ['comunicazioni', 'segnalazioni', 'documenti'];

    public function up(): void
    {
        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella)) {
                continue;
            }

            if (! Schema::hasColumn($tabella, 'updated_by')) {
                Schema::table($tabella, function (Blueprint $table) {
                    $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
                });
            }

            if (! $this->haLaChiaveEsterna($tabella)) {
                Schema::table($tabella, function (Blueprint $table) {
                    // `nullOnDelete()` e non `cascadeOnDelete()`: cancellare l'utente che ha fatto
                    // una modifica non deve cancellare la comunicazione. Si perde il nome, resta
                    // il documento — che è l'ordine di importanza giusto.
                    $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABELLE as $tabella) {
            if (! Schema::hasTable($tabella) || ! Schema::hasColumn($tabella, 'updated_by')) {
                continue;
            }

            if ($this->haLaChiaveEsterna($tabella)) {
                Schema::table($tabella, function (Blueprint $table) use ($tabella) {
                    $table->dropForeign($tabella.'_updated_by_foreign');
                });
            }

            Schema::table($tabella, function (Blueprint $table) {
                $table->dropColumn('updated_by');
            });
        }
    }

    /**
     * La chiave esterna su `updated_by` esiste già?
     *
     * Si interroga `information_schema` invece di fidarsi di `hasColumn`: la colonna può esserci
     * **senza** il vincolo, ed è precisamente lo stato che un'interruzione a metà produce su MySQL.
     *
     * ⚠️ **Su SQLite risponde «sì» senza chiedere niente, e non è una scorciatoia.** La suite gira
     * in memoria su SQLite mentre il prodotto gira su MySQL: là `information_schema` non esiste e
     * la domanda mandava in errore **tutti e 27** i casi di `UpgradeMigrationsRerunTest`, compresi
     * quelli che non c'entravano niente. E la risposta non servirebbe comunque: SQLite non sa
     * aggiungere una chiave esterna a una tabella già creata, quindi il blocco va saltato.
     * È la stessa difesa che usa `2026_08_15_100000_add_pertinenza_di_to_immobili`.
     */
    private function haLaChiaveEsterna(string $tabella): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $tabella)
            ->where('COLUMN_NAME', 'updated_by')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }
};
