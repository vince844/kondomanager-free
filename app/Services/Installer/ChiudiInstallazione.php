<?php

namespace App\Services\Installer;

use Illuminate\Support\Facades\File;

/**
 * Il passo «fine» del wizard, senza il wizard: il file di lock che dice «installata». È lo
 * stesso file che `CheckInstaller` guarda per tenere chiuso il wizard.
 */
class ChiudiInstallazione
{
    public function esegui(): void
    {
        File::put(config('installer.options.lock_file'), now()->toDateTimeString());
    }

    public function installata(): bool
    {
        return File::exists(config('installer.options.lock_file'));
    }
}
