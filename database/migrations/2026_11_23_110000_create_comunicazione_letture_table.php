<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDICE = 'comunicazione_letture_comunicazione_id_user_id_unique';

    /** Colonna => [tabella di arrivo, regola alla cancellazione]. */
    private const CHIAVI = [
        'comunicazione_id' => ['comunicazioni', 'cascade'],
        'user_id'          => ['users', 'cascade'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('comunicazione_letture')) {
            Schema::create('comunicazione_letture', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('comunicazione_id');
                $table->unsignedBigInteger('user_id');
                $table->dateTime('aperta_il')->nullable();
                $table->dateTime('presa_visione_il')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex('comunicazione_letture', self::INDICE)) {
            Schema::table('comunicazione_letture', fn (Blueprint $table) => $table->unique(['comunicazione_id', 'user_id'], self::INDICE));
        }

        foreach (self::CHIAVI as $colonna => [$arrivo, $regola]) {
            if (Schema::hasTable($arrivo) && ! $this->foreignKeyEsiste('comunicazione_letture', $colonna)) {
                Schema::table('comunicazione_letture', function (Blueprint $table) use ($colonna, $arrivo, $regola) {
                    $chiave = $table->foreign($colonna, "comunicazione_letture_{$colonna}_foreign")->references('id')->on($arrivo);
                    $regola === 'cascade' ? $chiave->cascadeOnDelete() : $chiave->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('comunicazione_letture');
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
