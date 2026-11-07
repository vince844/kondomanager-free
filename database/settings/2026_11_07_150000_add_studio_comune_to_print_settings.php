<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Il comune dello studio che scrive le lettere ai condòmini: il luogo in testa alle lettere e agli avvisi di compensazione. Vuoto: lo scrive
 * l'amministratore, e senza il luogo è il comune del condominio.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una proprietà già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('print.studio_comune')) {
            $this->migrator->add('print.studio_comune', null);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('print.studio_comune');
    }
};
