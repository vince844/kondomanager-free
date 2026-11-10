<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.allegati_moderati_di_serie')) {
            $this->migrator->add('general.allegati_moderati_di_serie', true);
        }

        if (! $this->migrator->exists('general.allegati_massimo_per_elemento')) {
            $this->migrator->add('general.allegati_massimo_per_elemento', 5);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.allegati_moderati_di_serie');
        $this->migrator->deleteIfExists('general.allegati_massimo_per_elemento');
    }
};

