<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (Coda 679, la sala): due scelte dello studio per la sala delle assemblee.
 *
 * - `delega_oltre_il_quinto`: con più di venti condòmini, la delega che porta il delegato oltre un quinto dei condòmini o del valore (art. 67 c. 1 disp.
 *   att.): `rifiuta` di serie, con l'articolo; `decide_il_presidente`, con il suo motivo.
 * - `voto_discorde_comproprietari`: i comproprietari presenti che votano in modo diverso: `astenuti` di serie; `decide_il_presidente`.
 *
 * Rieseguibile: un valore già scritto non si tocca. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('assemblee.delega_oltre_il_quinto')) {
            $this->migrator->add('assemblee.delega_oltre_il_quinto', 'rifiuta');
        }
        if (! $this->migrator->exists('assemblee.voto_discorde_comproprietari')) {
            $this->migrator->add('assemblee.voto_discorde_comproprietari', 'astenuti');
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('assemblee.voto_discorde_comproprietari');
        $this->migrator->deleteIfExists('assemblee.delega_oltre_il_quinto');
    }
};
