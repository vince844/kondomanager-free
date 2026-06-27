<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PrintSettings extends Settings
{
    public ?string $nota_legale_stampe = 'Professione esercitata ai sensi della legge 14 gennaio 2013, n.4';
    public ?string $firma_stampe_path = null;
    
    public static function group(): string
    {
        return 'print';
    }
}
