<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Coda 384: «La verifica in due passaggi è obbligatoria per chi entra nel pannello», spenta di serie anche sulle installazioni che si aggiornano.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.due_fattori_obbligatoria_pannello')) {
            $this->migrator->add('general.due_fattori_obbligatoria_pannello', false);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.due_fattori_obbligatoria_pannello');
    }
};
