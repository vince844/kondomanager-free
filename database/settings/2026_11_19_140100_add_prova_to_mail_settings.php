<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * 1.12.0-beta.13 (decisione 572, proposta 5): il programma ricorda l'ultima prova della posta dello studio e quando la casella è stata impostata, per la
 * riga «Adesso» della pagina «Configurazione email». Di serie vuote: una posta già impostata prima di questa versione risulta «Da provare».
 *
 * Rieseguibile: ogni proprietà si scrive solo se manca. Nel dataset di `UpgradeMigrationsRerunTest`.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        foreach (['mail.mail_impostata_il', 'mail.mail_ultima_prova'] as $proprieta) {
            if (! $this->migrator->exists($proprieta)) {
                $this->migrator->add($proprieta, null);
            }
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('mail.mail_impostata_il');
        $this->migrator->deleteIfExists('mail.mail_ultima_prova');
    }
};
