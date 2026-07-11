<?php

namespace App\Events\Backup;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emesso dopo l'eliminazione di un backup (archivio e record).
 * Nessun listener nel free: è un punto di aggancio per il plugin backup.
 */
class BackupDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Backup $backup) {}
}
