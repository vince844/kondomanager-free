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
    public function up(): void
    {
        if (! Schema::hasTable('commenti') || ! Schema::hasTable('users')) {
            return;
        }

        if (! Schema::hasColumn('commenti', 'discussione_user_id')) {
            Schema::table('commenti', function (Blueprint $table) {
                $table->unsignedBigInteger('discussione_user_id')->nullable();
            });
        }

        if (! $this->foreignKeyEsiste('commenti', 'discussione_user_id')) {
            Schema::table('commenti', function (Blueprint $table) {
                $table->foreign('discussione_user_id', 'commenti_discussione_user_id_foreign')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('commenti')) {
            return;
        }

        $mysql = DB::getDriverName() === 'mysql';
        if ($mysql && $this->foreignKeyEsiste('commenti', 'discussione_user_id')) {
            Schema::table('commenti', fn (Blueprint $table) => $table->dropForeign('commenti_discussione_user_id_foreign'));
        }

        if (Schema::hasColumn('commenti', 'discussione_user_id')) {
            $haForeign = ! $mysql && $this->foreignKeyEsiste('commenti', 'discussione_user_id');
            Schema::table('commenti', function (Blueprint $table) use ($haForeign) {
                if ($haForeign) {
                    $table->dropForeign(['discussione_user_id']);
                }
                $table->dropColumn('discussione_user_id');
            });
        }
    }

    private function foreignKeyEsiste(string $tabella, string $colonna): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return Schema::hasColumn($tabella, $colonna) && $this->sqliteHaForeign($tabella, $colonna);
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

    private function sqliteHaForeign(string $tabella, string $colonna): bool
    {
        foreach (DB::select("PRAGMA foreign_key_list({$tabella})") as $fk) {
            if (($fk->from ?? null) === $colonna) {
                return true;
            }
        }

        return false;
    }
};
