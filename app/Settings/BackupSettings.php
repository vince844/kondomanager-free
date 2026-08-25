<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class BackupSettings extends Settings
{
    // Numero di backup completati da conservare: i più vecchi oltre questo
    // limite vengono eliminati automaticamente al termine di ogni backup.
    public int $retention_keep_last = 5;

    public static function group(): string
    {
        return 'backup';
    }
}
