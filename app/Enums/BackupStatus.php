<?php

namespace App\Enums;

/**
 * Enum BackupStatus
 *
 * Stati della macchina a stati del backup: ogni step HTTP fa avanzare il
 * backup da PENDING fino a COMPLETED (o FAILED). Gli stati "running"
 * corrispondono alle fasi del BackupManager.
 */
enum BackupStatus: string
{
    case PENDING = 'pending';
    case DUMPING_DATABASE = 'dumping_database';
    case ARCHIVING_FILES = 'archiving_files';
    case FINALIZING = 'finalizing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    /**
     * Stati in cui il backup è ancora in lavorazione e può essere ripreso.
     *
     * @return array<self>
     */
    public static function runningStates(): array
    {
        return [self::PENDING, self::DUMPING_DATABASE, self::ARCHIVING_FILES, self::FINALIZING];
    }

    public function isRunning(): bool
    {
        return in_array($this, self::runningStates(), true);
    }

    // Metodo utile per il frontend (badge colore)
    public function color(): string
    {
        return match ($this) {
            self::PENDING, self::DUMPING_DATABASE, self::ARCHIVING_FILES, self::FINALIZING => 'blue',
            self::COMPLETED => 'emerald',
            self::FAILED => 'red',
        };
    }
}
