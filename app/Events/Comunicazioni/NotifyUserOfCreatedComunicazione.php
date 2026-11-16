<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Comunicazioni;

use App\Models\Comunicazione;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfCreatedComunicazione
{
    use Dispatchable, SerializesModels;

    public array $validated;
    public Comunicazione $comunicazione;

    public function __construct(array $validated, Comunicazione $comunicazione)
    {
        $this->validated = $validated;
        $this->comunicazione = $comunicazione;
    }

}
