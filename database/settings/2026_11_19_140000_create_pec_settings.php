<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (decisioni 564 e 572): la casella PEC dello studio, in un gruppo suo. Di serie vuota, con la porta 465 e SSL, come la usano quasi tutti i
 * gestori PEC; la imposta lo studio dalla pagina «Configurazione email».
 *
 * Rieseguibile: ogni proprietà si scrive solo se manca, e un valore già scritto non si tocca. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    private const DI_SERIE = [
        'pec.indirizzo' => null,
        'pec.server' => null,
        'pec.porta' => 465,
        'pec.cifratura' => 'ssl',
        'pec.utente' => null,
        'pec.password' => null,
        'pec.impostata_il' => null,
        'pec.ultima_prova' => null,
    ];

    public function up(): void
    {
        foreach (self::DI_SERIE as $proprieta => $valore) {
            if (! $this->migrator->exists($proprieta)) {
                $this->migrator->add($proprieta, $valore);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::DI_SERIE) as $proprieta) {
            $this->migrator->deleteIfExists($proprieta);
        }
    }
};
