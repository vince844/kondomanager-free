<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Events\Backup;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emesso alla creazione di un nuovo backup, prima del primo step.
 * Nessun listener nel free: è un punto di aggancio per il plugin backup.
 */
class BackupStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Backup $backup) {}
}
