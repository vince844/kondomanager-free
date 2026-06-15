<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

class CreatePrintSettings extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('print.nota_legale_stampe', 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4');
        $this->migrator->add('print.firma_stampe_path', null);
    }
};
