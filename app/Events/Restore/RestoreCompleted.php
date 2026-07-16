<?php

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
