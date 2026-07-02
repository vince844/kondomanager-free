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
    
    public static function group(): string
    {
        return 'general';
    }
    
}