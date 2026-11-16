<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('mail.mail_enabled', false);
        $this->migrator->add('mail.mail_host', null);
        $this->migrator->add('mail.mail_port', 587); 
        $this->migrator->add('mail.mail_username', null);
        $this->migrator->add('mail.mail_password', null);
        $this->migrator->add('mail.mail_encryption', 'tls');
        $this->migrator->add('mail.mail_from_address', null);
        $this->migrator->add('mail.mail_from_name', 'Kondomanager');

    }
};
