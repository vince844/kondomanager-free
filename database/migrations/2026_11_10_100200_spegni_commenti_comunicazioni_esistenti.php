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
        if (! Schema::hasTable('comunicazioni') || ! Schema::hasColumn('comunicazioni', 'can_comment')) {
            return;
        }

        DB::table('comunicazioni')
            ->where('can_comment', true)
            ->update(['can_comment' => false]);
    }

    public function down(): void
    {
        // Non è reversibile: non si può sapere quali comunicazioni avessero i commenti accesi in precedenza.
    }
};
