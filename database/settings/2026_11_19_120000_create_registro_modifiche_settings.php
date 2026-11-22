<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (Coda 679, §11 del progetto, decisione 518 (1)): per quanto tempo si tengono le righe del registro delle modifiche, di serie 10 anni.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una durata già scelta non si tocca. Nel dataset di
 * `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('registro_modifiche.anni_di_conservazione')) {
            $this->migrator->add('registro_modifiche.anni_di_conservazione', 10);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('registro_modifiche.anni_di_conservazione');
    }
};
