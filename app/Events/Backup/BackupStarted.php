<?php

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
