<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * beta.53 — «Pertinenza di»: il legame fra un box e il suo appartamento.
 *
 * ## Perché una chiave esterna e non la tabella molti-a-molti che c'era già
 *
 * `immobile_pertinenza` esisteva dal 07/08/2025 con `immobile_id`, `pertinenza_id` e
 * `quota_possesso`, ed era **vuota**: nessun controller, nessuna vista, nessun test, nessun
 * seeder la toccava. Non è stata riusata, ed è una scelta di dominio prima che di codice.
 *
 * **La cardinalità molti-a-molti modella una cosa che non può esistere.** L'art. 817 c.c. chiede
 * il requisito soggettivo — i due beni devono appartenere allo stesso proprietario — e da lì
 * discende che una pertinenza ha **un solo** bene principale. Il caso che il commento della
 * vecchia relazione invocava, «il box è condiviso da due unità», si scioglie in tre letture e
 * nessuna è una molti-a-molti:
 *
 * 1. il box è di Tizio e Caio al 50 % con gli appartamenti di uno e dell'altro → il vincolo **non
 *    c'è**, perché il box non appartiene allo stesso proprietario di nessuno dei due; è
 *    comproprietà del box, e si scrive già in `anagrafica_immobile`;
 * 2. i due appartamenti sono della stessa persona → la destinazione la dichiara il proprietario, e
 *    la dichiara a **uno**;
 * 3. il box è bene comune a un gruppo di unità ex art. 1117 c.c. → non è una pertinenza, è un bene
 *    la cui spesa si ripartisce ex art. 1123 co. 3 con una tabella a perimetro ristretto.
 *
 * Un appartamento con box, cantina e soffitta è invece **uno-a-molti dal lato del principale**,
 * che è esattamente ciò che questa chiave esterna esprime.
 *
 * `quota_possesso` sparisce con la tabella: fra un'unità e la sua pertinenza non esiste nessuna
 * «quota di possesso»: la quota esiste fra **persone** e unità, e sta in `anagrafica_immobile`.
 *
 * ## Le due colonne, e perché sono due
 *
 * - `pertinenza_di_immobile_id` — l'unità principale, quando è **in questo condominio**.
 * - `pertinenza_di_esterna` — testo libero, per quando non lo è. Non è un ripiego: l'art. 9 co. 5
 *   della L. 122/1989 (Tognoli) consente di cedere un parcheggio **solo con contestuale
 *   destinazione a pertinenza di altra unità sita nello stesso comune**, che può stare in un altro
 *   condominio o non essere gestita dal programma. Una chiave esterna lì non arriva, e senza il
 *   campo l'amministratore lascerebbe vuoto — che è l'informazione opposta.
 *
 * Le due sono alternative: lo presidia l'applicazione, non lo schema.
 *
 * ## Cosa NON c'è, di proposito
 *
 * **Nessuna data di validità.** Serviranno — l'art. 818 co. 3 c.c. rende la cessazione un fatto
 * con una data opponibile, e il massimale sull'unità privata conta unità e pertinenze
 * unitariamente — ma arriveranno **con il primo calcolo che le legge**, per la regola del progetto
 * «ogni nuova data deve nascere con il suo lettore, o non nascere». Il progetto ha già quattro
 * famiglie di date scritte e mai lette.
 *
 * **Nessun vincolo di profondità né di condominio nello schema.** Che una pertinenza non possa
 * avere pertinenze, e che il principale stia nello stesso condominio, lo verifica l'applicazione:
 * una foreign key composita irrigidirebbe lo schema per due regole che si spiegano meglio con un
 * messaggio che con un errore SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL non ha DDL transazionale: se una esecuzione precedente è morta a metà si riparte
        // da uno stato pulito. È il pattern della migrazione delle coperture (beta.19).
        $this->cleanupPartialMigration();

        // ⚠️ **Due blocchi e non uno, e senza `after()`.** Un `ALTER ... ADD ... AFTER` impedisce
        // `ALGORITHM=INSTANT` su MariaDB e su MySQL < 8.0.29: su una tabella `immobili` popolata
        // ricostruisce la tabella e allarga la finestra in cui il processo può morire — che su
        // hosting condiviso è il caso normale, non l'eccezione. La posizione della colonna non
        // serve a niente nel codice, e costa esattamente il rischio che questa migrazione cerca
        // di evitare.
        //
        // Separati, colonna e vincolo sono ciascuno rieseguibile per conto suo.
        Schema::table('immobili', function (Blueprint $table) {
            $table->unsignedBigInteger('pertinenza_di_immobile_id')
                ->nullable()
                ->comment('Unità principale di cui questa è pertinenza, nello stesso condominio (beta.53)');

            $table->string('pertinenza_di_esterna')
                ->nullable()
                ->comment('Unità principale fuori da questo condominio, in chiaro: caso Tognoli (beta.53)');
        });

        Schema::table('immobili', function (Blueprint $table) {
            $table->foreign('pertinenza_di_immobile_id')
                ->references('id')->on('immobili')
                ->nullOnDelete();
        });

        $this->rimuoviPivotInutilizzata();
    }

    public function down(): void
    {
        $this->cleanupPartialMigration();

        // La pivot non si ricrea: era vuota e senza scrittori, e ricrearla rimetterebbe in circolo
        // proprio la cardinalità che questa migrazione toglie.
    }

    /**
     * Riporta la tabella allo stato precedente, **qualunque sia il punto in cui ci si è fermati**.
     *
     * ⚠️ **La prima stesura usava `dropConstrainedForeignId()`, e falliva esattamente nell'unico
     * stato parziale che MySQL sa produrre.** Su MySQL il blocco di `up()` si compilava in tre
     * statement distinti — aggiungi colonna, aggiungi vincolo, aggiungi seconda colonna — e un
     * processo che muore fra il primo e il secondo lascia **colonna presente e vincolo assente**.
     * Al tentativo successivo `dropConstrainedForeignId()` emette per primo
     * `ALTER TABLE immobili DROP FOREIGN KEY …`, che su un vincolo inesistente dà
     * **errore 1091**. Riprodotto su MySQL 8.0.33.
     *
     * Non è un fallimento che si risolve riprovando: `SystemFinalizer` ritenta tre volte e
     * fallisce identico, e da lì in poi **ogni** `php artisan migrate` su quell'installazione si
     * ferma sullo stesso statement — bloccando anche le migrazioni successive. Uscirne richiede
     * phpMyAdmin, e chi aggiorna da una 1.9.x non ha backup automatico.
     *
     * ⚠️ **La suite non poteva vederlo, e vale la pena saperlo.** Gira su SQLite, dove
     * `SQLiteGrammar::compileDropForeign()` con le colonne valorizzate **non produce alcuno
     * statement** («Handled on table alteration…»): il drop è un'operazione vuota e passa sempre.
     * È il rovescio della lezione della beta.34 — lì lo schema di prova era più severo del vero,
     * qui è più permissivo, ed è la forma peggiore delle due perché non fallisce mai.
     *
     * La verifica del vincolo prima di toglierlo è la stessa che il progetto usa dal 01/03/2026
     * in `update_saldi_table`, scritta per questo identico problema.
     */
    private function cleanupPartialMigration(): void
    {
        $mysql = DB::getDriverName() === 'mysql';

        // Su MySQL il vincolo è uno statement a sé, e va emesso **solo se c'è davvero**.
        if ($mysql && $this->foreignKeyEsiste('immobili', 'pertinenza_di_immobile_id')) {
            Schema::table('immobili', function (Blueprint $table) {
                $table->dropForeign(['pertinenza_di_immobile_id']);
            });
        }

        Schema::table('immobili', function (Blueprint $table) use ($mysql) {
            if (Schema::hasColumn('immobili', 'pertinenza_di_immobile_id')) {
                // ⚠️ **Su SQLite il vincolo va nominato qui, nello stesso blueprint.**
                //
                // Non perché SQLite emetta un `DROP FOREIGN KEY` — `compileDropForeign()` non
                // produce alcuno statement — ma perché è così che Laravel sa **escluderlo dalla
                // definizione** quando ricostruisce la tabella. Senza, la tabella si ricostruisce
                // con la chiave ancora dichiarata su una colonna che non c'è più, e SQLite
                // rifiuta: «unknown column … in foreign key definition».
                //
                // Su MySQL invece qui non ci va: sarebbe un `ALTER` separato senza la guardia
                // sopra, cioè di nuovo l'errore 1091 che questo metodo esiste per evitare.
                if (! $mysql) {
                    $table->dropForeign(['pertinenza_di_immobile_id']);
                }

                $table->dropColumn('pertinenza_di_immobile_id');
            }
            if (Schema::hasColumn('immobili', 'pertinenza_di_esterna')) {
                $table->dropColumn('pertinenza_di_esterna');
            }
        });
    }

    /**
     * Esiste una chiave esterna su questa colonna? **Domanda solo per MySQL.**
     *
     * Su SQLite non si chiede: `information_schema` non c'è, e la risposta non servirebbe comunque
     * — lì il vincolo non si toglie a parte ma si omette dalla ricostruzione, che è quello che fa
     * il ramo `! $mysql` qui sopra.
     */
    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
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

    /**
     * ⚠️ **Si cancella solo se è vuota, e non è timidezza.**
     *
     * Su questo database è vuota, verificato. E deve esserlo ovunque, perché nessun percorso del
     * programma ha mai potuto scriverci: zero occorrenze in controller, viste, comandi, importatore
     * e seeder. Ma «deve» non è «è»: qualcuno può averci scritto da database, e una migrazione che
     * cancella dati altrui in silenzio è il difetto peggiore che una migrazione possa avere.
     *
     * Se ci sono righe, la tabella resta e si registra a log. Uno schema disallineato su
     * un'installazione è un problema molto più piccolo di un dato perso su tutte.
     */
    private function rimuoviPivotInutilizzata(): void
    {
        if (! Schema::hasTable('immobile_pertinenza')) {
            return;
        }

        $righe = DB::table('immobile_pertinenza')->count();

        if ($righe > 0) {
            Log::warning(
                "Migrazione: «immobile_pertinenza» contiene {$righe} righe e NON è stata rimossa. "
                .'Era una tabella senza scrittori nel programma: quelle righe sono state inserite '
                .'da fuori. Vanno guardate e travasate a mano in immobili.pertinenza_di_immobile_id '
                .'prima di eliminarla.'
            );

            return;
        }

        Schema::drop('immobile_pertinenza');
    }
};
