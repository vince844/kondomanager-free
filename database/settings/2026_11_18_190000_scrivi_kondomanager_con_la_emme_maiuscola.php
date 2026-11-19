<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Decisione 490: il nome di serie «Kondomanager» si scrive «KondoManager». Cambia il nome dell'applicazione e il nome del mittente delle email solo dove
 * valgono esattamente il valore di serie: chi li ha personalizzati resta com'è. Solo dati; rieseguita non trova più niente da cambiare. Tornando indietro
 * non si rimette: non si distingue il valore di serie da uno scritto da chi lo voleva così.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['general.app_name', 'mail.mail_from_name'] as $proprieta) {
            if ($this->migrator->exists($proprieta)) {
                $this->migrator->update($proprieta, fn ($valore) => $valore === 'Kondomanager' ? 'KondoManager' : $valore);
            }
        }
    }

    public function down(): void
    {
        // Niente: il valore scritto a mano e quello di serie non si distinguono.
    }
};
