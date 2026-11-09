<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * La partita IVA, il codice fiscale, il sito e il logo dello studio, che vanno in testa alle stampe con gli altri suoi dati. Vuoti: li scrive
 * l'amministratore.
 *
 * Rieseguibile, una proprietà alla volta: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già, e un aggiornamento interrotto a metà
 * lascerebbe le prime scritte e le altre no. Una proprietà già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    /** @var array<string, mixed> */
    private const PROPRIETA = [
        'print.studio_partita_iva'    => null,
        'print.studio_codice_fiscale' => null,
        'print.studio_sito'           => null,
        'print.logo_stampe_path'      => null,
    ];

    public function up(): void
    {
        foreach (self::PROPRIETA as $nome => $valore) {
            if (! $this->migrator->exists($nome)) {
                $this->migrator->add($nome, $valore);
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PROPRIETA) as $nome) {
            $this->migrator->deleteIfExists($nome);
        }
    }
};
