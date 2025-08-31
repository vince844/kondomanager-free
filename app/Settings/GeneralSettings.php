<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public bool $user_frontend_registration;

    public static function group(): string
    {
        return 'general';
    }
    
}