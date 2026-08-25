<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I CONTI CHE TORNAVANO STORTI (beta.43) — il ruolo che il database non sapeva rappresentare.
 *
 * `anagrafica_immobile.tipologia` era un `ENUM('proprietario','usufruttuario','inquilino')`.
 * Mancava **`nuda_proprietario`**, e quella mancanza non era un vuoto innocuo: rendeva
 * irraggiungibile codice già scritto e già pagato.
 *
 * `CalcoloQuoteService::distribuisciSuTabelle()` dichiara due catene di risoluzione del ruolo,
 * una per il godimento e una per il capitale. La catena capitale è
 * `['nuda_proprietario', 'proprietario']`: nessun valore poteva imboccarla, perché la colonna
 * non poteva contenerne il primo elemento. Tre test in `CascataRuoloRipartoTest` stavano fermi
 * con `->skip('nuda_proprietario non ancora in enum')` in attesa di questa riga.
 *
 * Serve qui perché il riparto dei saldi solidali (art. 63 disp. att. c.c.) deve saper
 * distinguere chi è titolare della **nuda proprietà** da chi ha l'**usufrutto**: sono due
 * soggetti diversi e per l'art. 1004/1005 c.c. pagano cose diverse. Finché esiste un solo
 * ruolo `proprietario`, quella distinzione non è scrivibile.
 *
 * ## Perché VARCHAR(50) e non un ENUM più lungo
 *
 * È il **principio 10** della roadmap — «Backed Enums + VARCHAR(50). Niente `ENUM` MySQL su
 * tabelle che evolveranno» — che per questa colonna era dichiarato e mai applicato. Due
 * ragioni concrete, oltre alla coerenza:
 *
 * 1. **Il ruolo successivo non costerà una migrazione.** `comodatario` è già in discussione da
 *    prima della 1.10; con l'`ENUM` ogni ruolo nuovo è un `ALTER` su una tabella viva.
 * 2. **Lo schema dei test smette di essere più severo di quello di produzione.** Su SQLite
 *    l'`ENUM` di Laravel diventa un `CHECK`, e un valore legittimo in produzione diventa
 *    non scrivibile in un test. È esattamente la trappola che la beta.34 ha pagato con
 *    l'indice `UNIQUE` sopravvissuto su SQLite: uno schema di prova più severo del vero non
 *    protegge, nasconde.
 *
 * Il vocabolario si sposta dove può essere letto e riusato: `App\Enums\RuoloAnagraficaImmobile`.
 *
 * ## Nessun dato cambia
 *
 * Le righe esistenti restano quelle che sono. Chi oggi ha registrato un nudo proprietario come
 * `proprietario` — l'unica cosa che poteva fare — continua a vederlo così, e i suoi riparti non
 * si muovono di un centesimo: `proprietario` è il terminale di entrambe le catene. Il ruolo
 * nuovo è una possibilità in più, non una riclassificazione retroattiva.
 */
return new class extends Migration
{
    private const TABELLA = 'anagrafica_immobile';
    private const COLONNA = 'tipologia';

    public function up(): void
    {
        if (! Schema::hasColumn(self::TABELLA, self::COLONNA)) {
            return;
        }

        // Su MySQL la conversione è un `ALTER` vero e vale la pena non rifarlo: la guardia
        // legge il tipo dichiarato dallo schema, non una tabella di stato. Su SQLite la
        // riesecuzione è una ricostruzione della tabella verso la stessa forma — innocua, e
        // introspezionare il `CHECK` costerebbe più di quanto valga.
        if ($this->giaConvertita()) {
            return;
        }

        Schema::table(self::TABELLA, function (Blueprint $table) {
            // Niente `->nullable()` e niente `->default()`: la colonna nasce `NOT NULL` senza
            // default e deve restare tale. `change()` riscrive la definizione per intero, e
            // ciò che non si ridichiara si perde.
            $table->string(self::COLONNA, 50)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn(self::TABELLA, self::COLONNA)) {
            return;
        }

        // Il rollback fallisce — correttamente — se a database esiste già una riga con
        // `nuda_proprietario`: MySQL rifiuta di restringere un ENUM su valori presenti. È la
        // stessa avvertenza della migrazione degli enum di `scritture_contabili`.
        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE ' . self::TABELLA . ' MODIFY COLUMN ' . self::COLONNA
                . " ENUM('proprietario','usufruttuario','inquilino') NOT NULL"
            );
        }
    }

    private function giaConvertita(): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        $colonna = DB::selectOne(
            'SELECT DATA_TYPE AS tipo FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [self::TABELLA, self::COLONNA]
        );

        return $colonna !== null && strtolower($colonna->tipo) === 'varchar';
    }
};
