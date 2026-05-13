<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.default_user_role', 'utente');
    }

    public function down(): void
    {
        $this->migrator->delete('general.default_user_role');
    }
};
