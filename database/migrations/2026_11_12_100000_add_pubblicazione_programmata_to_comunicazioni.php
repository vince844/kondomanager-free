<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comunicazioni')) {
            return;
        }

        if (! Schema::hasColumn('comunicazioni', 'programmata_per')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dateTime('programmata_per')->nullable();
            });
        }

        if (! Schema::hasColumn('comunicazioni', 'programmata_da')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->unsignedBigInteger('programmata_da')->nullable();
            });
        }

        if (! Schema::hasColumn('comunicazioni', 'pubblicata_il')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dateTime('pubblicata_il')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('comunicazioni')) {
            return;
        }

        if (Schema::hasColumn('comunicazioni', 'pubblicata_il')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dropColumn('pubblicata_il');
            });
        }

        if (Schema::hasColumn('comunicazioni', 'programmata_da')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dropColumn('programmata_da');
            });
        }

        if (Schema::hasColumn('comunicazioni', 'programmata_per')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dropColumn('programmata_per');
            });
        }
    }
};
