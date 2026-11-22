<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (Coda 679, la convocazione): «Promemoria per email delle convocazioni da spedire» nelle impostazioni della posta, acceso di serie. Il
 * promemoria va a chi ha preparato l'assemblea, due giorni lavorativi prima del giorno limite del compito «Spedire la convocazione entro il …» e la mattina
 * di quel giorno, se restano avvisi da spedire.
 *
 * Rieseguibile: un valore già scritto non si tocca (uno studio che l'ha spento lo trova spento). Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('mail.promemoria_convocazioni')) {
            $this->migrator->add('mail.promemoria_convocazioni', true);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('mail.promemoria_convocazioni');
    }
};
