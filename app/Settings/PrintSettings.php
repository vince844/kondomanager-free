<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PrintSettings extends Settings
{
    public ?string $nota_legale_stampe = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';
    public ?string $firma_stampe_path = null;

    /**
     * Lo studio dell'amministratore, che scrive le lettere ai condòmini (promemoria, sollecito, diffida, messa in mora): in testa alla lettera,
     * accanto al condominio. Vuoti finché l'amministratore non li scrive; le altre stampe non li usano. L'email è anche quella a cui si risponde.
     */
    public ?string $studio_nome = null;
    public ?string $studio_indirizzo = null;
    public ?string $studio_email = null;
    public ?string $studio_pec = null;
    public ?string $studio_telefono = null;
    /** Il comune dello studio: il luogo in testa alle lettere e agli avvisi; senza, quello del condominio. */
    public ?string $studio_comune = null;

    public static function group(): string
    {
        return 'print';
    }
}
