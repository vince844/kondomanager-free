<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Restore;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Emesso quando un ripristino fallisce. La modalità ripristino RESTA attiva
 * (l'app non deve tornare raggiungibile con un database a metà import):
 * l'admin riprende o esegue il rollback guidato.
 */
class RestoreFailed
{
    use Dispatchable;

    public function __construct(public string $uuid, public string $phase, public string $error) {}
}
