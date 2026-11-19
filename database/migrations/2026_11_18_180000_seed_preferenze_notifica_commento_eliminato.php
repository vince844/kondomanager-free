<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\NotificationType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisione 483 (3): le preferenze nuove «Commento eliminato» (segnalazioni e comunicazioni) nascono come «Commento nascosto», la
 * preferenza di oggi (il valore storico `comment_deleted`, che cambia solo nome): chi l'ha accesa riceve anche l'avviso nuovo, chi l'ha
 * spenta no. Chi non ha la riga della preferenza di oggi (le preferenze esistono solo dopo un salvataggio della pagina) non riceve la
 * nuova. Solo dati: scrive solo per chi non ha già la riga nuova, quindi rieseguita non duplica e non cambia una scelta già fatta.
 */
return new class extends Migration
{
    /** preferenza nuova => preferenza di oggi da cui eredita lo stato. */
    private const EREDITA_DA = [
        NotificationType::COMMENT_REMOVED->value               => NotificationType::COMMENT_HIDDEN->value,
        NotificationType::COMMUNICATION_COMMENT_REMOVED->value => NotificationType::COMMUNICATION_COMMENT_HIDDEN->value,
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
