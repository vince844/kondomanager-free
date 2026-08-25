<?php

namespace App\Events\Backup;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emesso quando l'archivio è stato finalizzato e salvato sulla destinazione.
 * Nessun listener nel free: è un punto di aggancio per il plugin backup
 * (es. upload su destinazioni cloud, notifiche).
 */
class BackupCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Backup $backup) {}
}
