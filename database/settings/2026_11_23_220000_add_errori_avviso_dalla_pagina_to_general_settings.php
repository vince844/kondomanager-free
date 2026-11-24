<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Coda 788, decisione 713: «I condòmini possono avvisare lo studio dalla pagina d'errore», acceso di serie anche sulle installazioni che si aggiornano.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.errori_avviso_dalla_pagina')) {
            $this->migrator->add('general.errori_avviso_dalla_pagina', true);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.errori_avviso_dalla_pagina');
    }
};
