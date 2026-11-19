<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** preferenza nuova => preferenza da cui eredita lo stato. */
    private const EREDITA_DA = [
        'scheduled_communication_published' => 'approved_communication',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        foreach (self::EREDITA_DA as $nuova => $sorella) {
            $giaPresenti = DB::table('notification_preferences')
                ->where('type', $nuova)
                ->pluck('user_id')
                ->all();

            $daCreare = DB::table('notification_preferences')
                ->where('type', $sorella)
                ->when($giaPresenti !== [], fn ($q) => $q->whereNotIn('user_id', $giaPresenti))
                ->get(['user_id', 'enabled']);

            if ($daCreare->isEmpty()) {
                continue;
            }

            DB::table('notification_preferences')->insert(
                $daCreare->map(fn ($r) => [
                    'user_id'    => $r->user_id,
                    'type'       => $nuova,
                    'enabled'    => $r->enabled,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all()
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        DB::table('notification_preferences')
            ->whereIn('type', array_keys(self::EREDITA_DA))
            ->delete();
    }
};
