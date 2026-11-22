<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
        public ?int $userId
    ) {}
}
