<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Restore;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emesso al termine con successo di un ripristino. Porta l'esito completo
 * (comprese le eventuali azioni di pulizia 2FA/SMTP) per la pagina finale.
 */
class RestoreCompleted
{
    use Dispatchable;

    public function __construct(public string $uuid, public array $outcome) {}
}
