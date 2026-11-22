<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (Coda 679, §1.2 del progetto della tappa 2): le impostazioni delle assemblee. Per ora una sola: i giorni della posta dello studio, di serie
 * 3, con cui il programma propone il giorno entro cui spedire le raccomandate (decisione 565). La casella PEC dello studio non sta qui: dove si imposta non
 * è ancora deciso (decisione 564), e il programma la legge da un punto solo.
 *
 * Rieseguibile: un valore già scritto non si tocca. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('assemblee.giorni_di_posta')) {
            $this->migrator->add('assemblee.giorni_di_posta', 3);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('assemblee.giorni_di_posta');
    }
};
