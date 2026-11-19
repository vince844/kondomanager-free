<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comunicazioni') || ! Schema::hasColumn('comunicazioni', 'pubblicata_il')) {
            return;
        }

        DB::table('comunicazioni')
            ->where('is_published', true)
            ->where('is_approved', true)
            ->whereNull('pubblicata_il')
            ->update(['pubblicata_il' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Non reversibile: non è possibile distinguere i valori valorizzati dal riempimento da quelli successivi.
    }
};
