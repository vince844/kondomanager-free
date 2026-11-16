<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Il tasso legale scritto a mano dallo studio per gli anni che il programma non ha ancora: nasce con l'elenco vuoto.
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; un tasso già scritto non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('tasso_legale.scritti_a_mano')) {
            $this->migrator->add('tasso_legale.scritti_a_mano', []);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('tasso_legale.scritti_a_mano');
    }
};
