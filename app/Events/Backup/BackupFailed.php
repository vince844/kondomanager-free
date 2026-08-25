<?php

namespace App\Events\Backup;

use App\Models\Backup;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Emesso quando un backup fallisce o viene marcato come stantio.
 * Nessun listener nel free: è un punto di aggancio per il plugin backup.
 */
class BackupFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Backup $backup) {}
}
