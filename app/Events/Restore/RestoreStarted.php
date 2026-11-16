<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Restore;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emesso quando un ripristino viene avviato, prima del primo step.
 * Nessun listener nel free: punto di aggancio per assistenza/plugin.
 * Porta l'uuid (non un model: la tabella backups verrà sovrascritta).
 */
class RestoreStarted
{
    use Dispatchable;

    public function __construct(public string $uuid, public array $manifest) {}
}
