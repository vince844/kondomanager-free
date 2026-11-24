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

        if (! Schema::hasColumn('comunicazioni', 'chiedi_presa_visione')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->boolean('chiedi_presa_visione')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('comunicazioni')) {
            return;
        }

        if (Schema::hasColumn('comunicazioni', 'chiedi_presa_visione')) {
            Schema::table('comunicazioni', function (Blueprint $table) {
                $table->dropColumn('chiedi_presa_visione');
            });
        }
    }
};
