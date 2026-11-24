<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.14 (decisione 705, più persone che escono in un passaggio): `subentri.insieme_id`, il primo dei passaggi registrati insieme con lo stesso
 * atto, uno per ogni persona che esce. Lo stesso su tutti, anche sul primo; nullo per un passaggio da solo. Solo sulle righe padre: le righe delle
 * pertinenze restano legate al loro padre con `subentro_padre_id`. Nessun dato da travasare: i passaggi registrati prima restano da soli.
 *
 * Rieseguibile: una guardia per la colonna, una per l'indice e una per la chiave esterna (su MySQL sono istruzioni distinte, e il DDL non è
 * transazionale). Nel dataset di `UpgradeMigrationsRerunTest`, con un `it()` che la interrompe dopo la colonna.
 */
return new class extends Migration
{
    private const INDICE = 'subentri_insieme_id_index';

    private const CHIAVE = 'subentri_insieme_id_foreign';

    public function up(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }
        if (! Schema::hasColumn('subentri', 'insieme_id')) {
            Schema::table('subentri', fn (Blueprint $t) => $t->unsignedBigInteger('insieme_id')->nullable()->after('subentro_padre_id')
                ->comment('Il primo dei passaggi registrati insieme, più persone che escono con lo stesso atto (decisione 705); lo stesso su tutti, anche sul primo; nullo per un passaggio da solo.'));
        }
        if (! Schema::hasIndex('subentri', self::INDICE)) {
            Schema::table('subentri', fn (Blueprint $t) => $t->index('insieme_id', self::INDICE));
        }
        if (! $this->chiaveEsiste('subentri', 'insieme_id')) {
            Schema::table('subentri', fn (Blueprint $t) => $t->foreign('insieme_id', self::CHIAVE)->references('id')->on('subentri')->nullOnDelete());
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('subentri')) {
            return;
        }
        if ($this->chiaveEsiste('subentri', 'insieme_id')) {
            Schema::table('subentri', fn (Blueprint $t) => DB::getDriverName() === 'mysql' ? $t->dropForeign(self::CHIAVE) : $t->dropForeign(['insieme_id']));
        }
        if (Schema::hasIndex('subentri', self::INDICE)) {
            Schema::table('subentri', fn (Blueprint $t) => $t->dropIndex(self::INDICE));
        }
        if (Schema::hasColumn('subentri', 'insieme_id')) {
            Schema::table('subentri', fn (Blueprint $t) => $t->dropColumn('insieme_id'));
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
