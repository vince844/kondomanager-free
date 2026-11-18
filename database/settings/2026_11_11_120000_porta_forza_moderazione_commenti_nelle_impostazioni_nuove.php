<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $forza = DB::table('settings')->where('group', 'general')->where('name', 'force_comment_moderation')->first();
        $eraAttiva = $forza !== null && (bool) json_decode($forza->payload, true);

        if ($eraAttiva) {
            if ($this->migrator->exists('general.commenti_segnalazioni_moderati_di_serie')) {
                $this->migrator->update('general.commenti_segnalazioni_moderati_di_serie', fn () => true);
            } else {
                $this->migrator->add('general.commenti_segnalazioni_moderati_di_serie', true);
            }

            if ($this->migrator->exists('general.commenti_comunicazioni_moderati_di_serie')) {
                $this->migrator->update('general.commenti_comunicazioni_moderati_di_serie', fn () => true);
            } else {
                $this->migrator->add('general.commenti_comunicazioni_moderati_di_serie', true);
            }

            if (Schema::hasTable('segnalazioni') && Schema::hasColumn('segnalazioni', 'commenti_moderati')) {
                DB::table('segnalazioni')->update(['commenti_moderati' => true]);
            }
        }

        $this->migrator->deleteIfExists('general.force_comment_moderation');
    }

    public function down(): void
    {
        if (! $this->migrator->exists('general.force_comment_moderation')) {
            $this->migrator->add('general.force_comment_moderation', false);
        }
    }
};
