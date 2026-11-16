<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le risposte dell'amministratore ai promemoria che il programma calcola (i sei mesi per agire dell'art. 1129 c. 9 c.c., i 180 giorni del rendiconto) e ai
 * compiti dell'agenda: una riga per cosa a cui si risponde, con lo stato di oggi e il registro dei gesti dentro. Le date non si scrivono mai: si calcolano a
 * ogni lettura; qui c'è solo ciò che l'amministratore ha detto, e deve restare negli anni.
 *
 * - `argomento`: `compito` (un evento dell'agenda), `sei_mesi` (la riga di un esercizio, con o senza persona), `rendiconto` (i 180 giorni), `persona`.
 * - `chiave`: unica, `argomento|condominio|esercizio|persona` con `-` dove manca, o `compito|<evento>`. L'indice unico con colonne nulle non tiene su MySQL; la
 *   chiave sì, e due risposte insieme scrivono la stessa riga.
 * - `evento_id`, `promemoria_evento_id`, `agenda_evento_id`: gli eventi dell'agenda legati alla risposta; diventano nulli quando l'evento se ne va (anche con
 *   una query, o con la potatura degli eventi finiti da un anno), e la risposta resta con il titolo e il tipo copiati.
 * - Il condominio, l'esercizio e la persona se ne vanno in cascata con la risposta, come le note e gli accordi.
 *
 * In più, sulla dispensa dei sei mesi, `togli_sollecito_tesoreria`: la scelta dell'amministratore di togliere anche il sollecito in tesoreria (di serie no).
 *
 * Rieseguibile: la tabella, l'indice unico, i due indici, ognuna delle sette chiavi esterne e la colonna della dispensa sono statement distinti su MySQL (il
 * DDL non è transazionale), una guardia ciascuno. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends Migration
{
    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'condominio_id' => ['condomini', 'cascade'],
        'evento_id' => ['eventi', 'set null'],
        'esercizio_id' => ['esercizi', 'cascade'],
        'anagrafica_id' => ['anagrafiche', 'cascade'],
        'promemoria_evento_id' => ['eventi', 'set null'],
        'agenda_evento_id' => ['eventi', 'set null'],
        'aggiornata_da' => ['users', 'set null'],
    ];

    private const UNICO = 'risposte_promemoria_chiave_unica';

    /** Nome => colonne. */
    private const INDICI = [
        'risposte_promemoria_condominio_argomento_index' => ['condominio_id', 'argomento'],
        'risposte_promemoria_argomento_stato_index' => ['argomento', 'stato', 'ricordami_il'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('risposte_promemoria')) {
            Schema::create('risposte_promemoria', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('condominio_id')->nullable()->comment('Nullo solo per un compito dell\'agenda senza condominio.');
                $table->string('argomento', 20)->comment('compito, sei_mesi, rendiconto, persona');
                $table->unsignedBigInteger('evento_id')->nullable()->comment('Il compito dell\'agenda a cui si risponde.');
                $table->string('titolo', 255)->nullable()->comment('Il titolo del compito, copiato al primo gesto.');
                $table->string('tipo_compito', 60)->nullable()->comment('Il tipo del compito, copiato al primo gesto.');
                $table->unsignedBigInteger('esercizio_id')->nullable();
                $table->unsignedBigInteger('anagrafica_id')->nullable();
                $table->string('chiave', 100)->comment('argomento|condominio|esercizio|persona, o compito|evento.');
                $table->string('stato', 20)->nullable()->comment('agito, fatto, non_serve, ricordamelo; nullo dopo «Riapri».');
                $table->date('giorno')->nullable()->comment('Il giorno dichiarato («hai agito il …»).');
                $table->date('ricordami_il')->nullable()->comment('Il giorno scelto, copiato: il giorno vero è quello dell\'evento nell\'agenda.');
                $table->unsignedBigInteger('promemoria_evento_id')->nullable()->comment('Il promemoria di «Ricordamelo» nell\'agenda.');
                $table->unsignedBigInteger('agenda_evento_id')->nullable()->comment('L\'evento di «Mettilo in agenda».');
                $table->text('nota')->nullable()->comment('L\'ultima nota.');
                $table->json('dati')->nullable()->comment('Ciò che serve per decidere se la persona o il compito tornano.');
                $table->json('registro')->nullable()->comment('Le righe {tipo, il, utente_id, utente, nota, dati}, scritte solo dal server.');
                $table->unsignedBigInteger('aggiornata_da')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('risposte_promemoria', self::UNICO)) {
            Schema::table('risposte_promemoria', fn (Blueprint $table) => $table->unique(['chiave'], self::UNICO));
        }

        foreach (self::INDICI as $nome => $colonne) {
            if (! Schema::hasIndex('risposte_promemoria', $nome)) {
                Schema::table('risposte_promemoria', fn (Blueprint $table) => $table->index($colonne, $nome));
            }
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('risposte_promemoria', $colonna)) {
                Schema::table('risposte_promemoria', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "risposte_promemoria_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }

        if (Schema::hasTable('dispense_sei_mesi') && ! Schema::hasColumn('dispense_sei_mesi', 'togli_sollecito_tesoreria')) {
            Schema::table('dispense_sei_mesi', fn (Blueprint $table) => $table->boolean('togli_sollecito_tesoreria')->default(false)->after('tutte_le_persone')
                ->comment('La dispensa toglie anche il sollecito in tesoreria: una scelta dell\'amministratore, di serie no.'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('risposte_promemoria');
        if (Schema::hasTable('dispense_sei_mesi') && Schema::hasColumn('dispense_sei_mesi', 'togli_sollecito_tesoreria')) {
            Schema::table('dispense_sei_mesi', fn (Blueprint $table) => $table->dropColumn('togli_sollecito_tesoreria'));
        }
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
                if (($fk->from ?? null) === $colonna) {
                    return true;
                }
            }

            return false;
        }

        return DB::selectOne('
            SELECT COUNT(*) AS n
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
              AND referenced_table_name IS NOT NULL
        ', [$tabella, $colonna])->n > 0;
    }
};
