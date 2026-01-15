<?php

namespace App\Events\Gestionale;

use App\Models\Gestionale\Rata;
use App\Models\Anagrafica;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IncassoRegistrato implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Rata $rata,
        public Anagrafica $anagrafica
    ) {}
}