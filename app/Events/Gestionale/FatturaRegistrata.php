<?php

namespace App\Events\Gestionale;

use App\Models\Gestionale\FatturaPassiva;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FatturaRegistrata
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * $userId viene passato esplicitamente perché il listener è ShouldQueue:
     * viene eseguito in background dove auth() non è disponibile.
     */
    public function __construct(
        public FatturaPassiva $fattura,
        public int $userId
    ) {}
}
