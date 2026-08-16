<?php

namespace App\Settings;

use App\Enums\Role;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $user_frontend_registration = false;
    public string $language = 'it';
    public string $app_name = 'Kondomanager';
    public string $version = '1.7.0';
    public bool $external_cron_enabled = false;
    public ?string $external_cron_token = null;
    public string $default_user_role = Role::UTENTE->value;
    public bool $force_comment_moderation = false;

    /**
     * Quante righe mostrano gli elenchi a chi non ha ancora scelto.
     *
     * È il valore di partenza, non un vincolo: resta la scelta di ciascuno, tabella per tabella,
     * e chi la cambia se la ritrova al rientro. Serve all'amministratore che sa già di lavorare
     * su condomìni grandi e non vuole ricominciare da dieci righe su ogni elenco.
     *
     * I valori ammessi sono quelli di `config('pagination.consentite')`.
     */
    public int $default_per_page = 10;


    public static function group(): string
    {
        return 'general';
    }
    
}