<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * La carta dello studio di ogni gruppo di documenti: per gruppo l'id di una carta di `carte_stampa`, o nullo per la predefinita. Nasce con
 * tutti i gruppi nulli: le stampe escono con la «Carta dello studio».
 *
 * Rieseguibile: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già; una scelta già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('print.carta_per_gruppo')) {
            $this->migrator->add('print.carta_per_gruppo', [
                'lettere' => null, 'ricevute_e_avvisi' => null, 'rendiconto_e_assemblea' => null, 'per_la_persona' => null, 'registri_e_elenchi' => null,
            ]);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('print.carta_per_gruppo');
    }
};
