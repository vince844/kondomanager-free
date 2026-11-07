<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * I dati dello studio che scrive le lettere ai condòmini: nome, indirizzo, email, PEC, telefono. Vuoti: li scrive l'amministratore. I testi
 * delle lettere non sono impostazioni: sono i modelli delle lettere, una tabella sua (`modelli_testo`).
 *
 * Rieseguibile, una proprietà alla volta: `add()` lancia `SettingAlreadyExists` se la proprietà c'è già, e un aggiornamento interrotto a metà
 * lascerebbe le prime scritte e le altre no. Una proprietà già scritta non si tocca.
 */
return new class extends SettingsMigration
{
    /** @var array<string, mixed> */
    private const PROPRIETA = [
        'print.studio_nome'       => null,
        'print.studio_indirizzo'  => null,
        'print.studio_email'      => null,
        'print.studio_pec'        => null,
        'print.studio_telefono'   => null,
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
