<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Seed dal valore attuale di config('app.name') (letto da APP_NAME nel .env),
        // così le installazioni esistenti non si ritrovano rinominate al primo aggiornamento.
        $this->migrator->add('general.app_name', config('app.name', 'Kondomanager'));
    }

    public function down(): void
    {
        $this->migrator->delete('general.app_name');
    }
};
