<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Gestionale;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\User;
use App\Enums\StatoPianoRate;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PianoRateStatusUpdated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Condominio $condominio,
        public Esercizio $esercizio, 
        public PianoRate $pianoRate,
        public User $user,
        public StatoPianoRate $oldStatus,
        public StatoPianoRate $newStatus
    ) {}
}