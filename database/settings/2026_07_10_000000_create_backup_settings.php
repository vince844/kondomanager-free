<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class CreateBackupSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('backup.retention_keep_last', 5);
    }
}
