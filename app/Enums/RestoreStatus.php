<?php

namespace App\Enums;

/**
 * Stati della macchina a stati del ripristino. Ogni step HTTP fa avanzare
 * il ripristino da PENDING fino a COMPLETED (o FAILED). A differenza del
 * backup, lo stato NON vive nel database (l'import lo sovrascrive): vive
 * nel file di stato del ripristino. Vedi docs/ripristino_backup_design.md.
 */
enum RestoreStatus: string
{
    case PENDING = 'pending';
    case SAFETY_BACKUP = 'safety_backup';
    case EXTRACTING = 'extracting';
    case VERIFYING = 'verifying';
    case IMPORTING_DATABASE = 'importing_database';
    case RESTORING_FILES = 'restoring_files';
    case FINALIZING = 'finalizing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * Stati in cui il ripristino è ancora in lavorazione e può essere ripreso.
     *
     * @return array<self>
     */
    public static function runningStates(): array
    {
        return [
            self::PENDING,
            self::SAFETY_BACKUP,
            self::EXTRACTING,
            self::VERIFYING,
            self::IMPORTING_DATABASE,
            self::RESTORING_FILES,
            self::FINALIZING,
        ];
    }

    public function isRunning(): bool
    {
        return in_array($this, self::runningStates(), true);
    }

    /**
     * Da questo stato in poi il database è stato (o sta per essere)
     * sovrascritto: un rollback richiede il backup di sicurezza, non il
     * semplice ripristino dei file. Serve alla UI per il messaggio giusto.
     */
    public function hasTouchedDatabase(): bool
    {
        return in_array($this, [
            self::IMPORTING_DATABASE,
            self::RESTORING_FILES,
            self::FINALIZING,
            self::COMPLETED,
        ], true);
    }
}
