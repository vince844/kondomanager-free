<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.comunicazioni_registra_aperture')) {
            $this->migrator->add('general.comunicazioni_registra_aperture', false);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.comunicazioni_registra_aperture');
    }
};
