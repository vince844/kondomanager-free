<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * La scelta fra «Il programma suggerisce la prossima azione» e «Scelgo io la prossima azione» passa dalle impostazioni generali dello studio a quelle di ogni
 * condominio (la tabella `impostazioni_condominio`): la riga delle impostazioni generali, dove è stata scritta, si toglie. Rieseguibile: se non c'è, niente.
 * Tornando indietro non si rimette: la scelta non sta più qui.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->deleteIfExists('general.passo_proposto');
    }

    public function down(): void
    {
        // Niente: la scelta è del condominio.
    }
};
