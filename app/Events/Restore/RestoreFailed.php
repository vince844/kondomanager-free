<?php

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
