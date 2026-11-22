<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Settings;


class BackupSettings extends ImpostazioniDelRegistro
{
    // Numero di backup completati da conservare: i più vecchi oltre questo
    // limite vengono eliminati automaticamente al termine di ogni backup.
    public int $retention_keep_last = 5;

    public static function group(): string
    {
        return 'backup';
    }
}
