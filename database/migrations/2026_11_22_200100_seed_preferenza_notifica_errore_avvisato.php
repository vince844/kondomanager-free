<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.12.0-beta.14 (decisione 701): la preferenza di notifica «Errore avvisato» (`error_reported`), l'email a chi ha «Gestisci impostazioni generali» quando
 * un condòmino o un collaboratore avvisa lo studio di un errore. **Spenta di serie**: nasce spenta per chi ha già delle preferenze, e chi non ne ha non riceve
 * niente lo stesso (una preferenza che non c'è vale «spenta»). Solo dati; rieseguita scrive solo per chi non l'ha già, e non riaccende né spegne niente.
 */
return new class extends Migration
{
    private const TIPO = 'error_reported';

    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        $giaPresenti = DB::table('notification_preferences')->where('type', self::TIPO)->pluck('user_id')->all();

        $daCreare = DB::table('notification_preferences')
            ->when($giaPresenti !== [], fn ($q) => $q->whereNotIn('user_id', $giaPresenti))
            ->distinct()
            ->pluck('user_id');

        if ($daCreare->isEmpty()) {
            return;
        }

        DB::table('notification_preferences')->insert(
            $daCreare->map(fn ($utente) => [
                'user_id' => $utente,
                'type' => self::TIPO,
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all()
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        DB::table('notification_preferences')->where('type', self::TIPO)->delete();
    }
};
