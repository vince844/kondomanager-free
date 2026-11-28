<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.15 (decisione 747): `righe_fattura.conto_addebito_id`, la voce scelta sulla riga addebitata a una sola unità. La voce comune
 * (`conto_id`) di quella riga resta vuota, come dalla 1.9.0, così nessun lettore del preventivo comune la conta; questa colonna la ricorda per
 * l'addebito diretto, che segue la voce su cui è scritto (`App\Services\Riparto\RuoloDellaVoce`). Nulla sulle righe comuni e su quelle senza voce.
 * Eliminata la voce, la riga torna senza voce (`nullOnDelete`) e decide il passaggio. Nessun dato da travasare: le fatture registrate prima non
 * hanno la voce da nessuna parte, e restano come sono.
 *
 * Rieseguibile: una guardia per la colonna, una per l'indice e una per la chiave esterna (su MySQL sono istruzioni distinte, e il DDL non è
 * transazionale). Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo la colonna.
 */
return new class extends Migration
{
    private const INDICE = 'righe_fattura_conto_addebito_id_index';

    private const CHIAVE = 'righe_fattura_conto_addebito_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('righe_fattura')) {
            return;
        }
        if (! Schema::hasColumn('righe_fattura', 'conto_addebito_id')) {
            Schema::table('righe_fattura', fn (Blueprint $t) => $t->unsignedBigInteger('conto_addebito_id')->nullable()->after('immobile_id')
                ->comment('La voce scelta sulla riga addebitata a una sola unità (decisione 747): conto_id resta nullo; l\'addebito diretto segue questa voce.'));
        }
        if (! Schema::hasIndex('righe_fattura', self::INDICE)) {
            Schema::table('righe_fattura', fn (Blueprint $t) => $t->index('conto_addebito_id', self::INDICE));
        }
        if (! $this->chiaveEsiste('righe_fattura', 'conto_addebito_id')) {
            Schema::table('righe_fattura', fn (Blueprint $t) => $t->foreign('conto_addebito_id', self::CHIAVE)->references('id')->on('conti')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('righe_fattura')) {
            return;
        }
        if ($this->chiaveEsiste('righe_fattura', 'conto_addebito_id')) {
            Schema::table('righe_fattura', fn (Blueprint $t) => DB::getDriverName() === 'mysql' ? $t->dropForeign(self::CHIAVE) : $t->dropForeign(['conto_addebito_id']));
        }
        if (Schema::hasIndex('righe_fattura', self::INDICE)) {
            Schema::table('righe_fattura', fn (Blueprint $t) => $t->dropIndex(self::INDICE));
        }
        if (Schema::hasColumn('righe_fattura', 'conto_addebito_id')) {
            Schema::table('righe_fattura', fn (Blueprint $t) => $t->dropColumn('conto_addebito_id'));
        }
    }

    /** Esiste una chiave esterna su questa colonna? Su SQLite si legge `PRAGMA foreign_key_list`. */
    private function chiaveEsiste(string $tabella, string $colonna): bool
    {
        if (! Schema::hasColumn($tabella, $colonna)) {
            return false;
        }
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
