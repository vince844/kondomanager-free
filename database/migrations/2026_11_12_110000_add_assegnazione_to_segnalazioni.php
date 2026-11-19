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
        if (! Schema::hasTable('segnalazioni')) {
            return;
        }

        if (! Schema::hasColumn('segnalazioni', 'assegnata_il')) {
            DB::table('segnalazioni')->update(['assigned_to' => null]);
        }

        if (! Schema::hasColumn('segnalazioni', 'assegnata_da')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->unsignedBigInteger('assegnata_da')->nullable();
            });
        }

        if (! Schema::hasColumn('segnalazioni', 'assegnata_il')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->dateTime('assegnata_il')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('segnalazioni')) {
            return;
        }

        if (Schema::hasColumn('segnalazioni', 'assegnata_il')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->dropColumn('assegnata_il');
            });
        }

        if (Schema::hasColumn('segnalazioni', 'assegnata_da')) {
            Schema::table('segnalazioni', function (Blueprint $table) {
                $table->dropColumn('assegnata_da');
            });
        }
    }
};

