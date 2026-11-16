<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
