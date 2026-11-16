<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.allegati_moderati_di_serie')) {
            $this->migrator->add('general.allegati_moderati_di_serie', true);
        }

        if (! $this->migrator->exists('general.allegati_massimo_per_elemento')) {
            $this->migrator->add('general.allegati_massimo_per_elemento', 5);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.allegati_moderati_di_serie');
        $this->migrator->deleteIfExists('general.allegati_massimo_per_elemento');
    }
};

