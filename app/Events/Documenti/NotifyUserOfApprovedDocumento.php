<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Documenti;

use App\Models\Documento;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotifyUserOfApprovedDocumento
{
    use Dispatchable, SerializesModels;

    public Documento $documento;
    public $user;

    /**
     * Create a new event instance.
     */
    public function __construct(Documento $documento, $user)
    {
        $this->documento = $documento;
        $this->user = $user;
    }

}
