<?php

namespace App\Services\Restore;

/**
 * Mutua esclusione degli step di ripristino tramite flock su file.
 *
 * Perché non Cache::lock come fa il backup: in questa applicazione la cache
 * vive nel DATABASE, che durante l'import viene sovrascritto — il lock
 * sparirebbe proprio nel momento in cui serve. flock ha anche una proprietà
 * preziosa sugli hosting condivisi: se il processo PHP muore (timeout,
 * kill), il sistema operativo rilascia il lock da solo, niente lucchetti
 * fantasma da ripulire.
 */
class RestoreLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private ?string $path = null) {}

    /**
     * Tenta di acquisire il lock SENZA attendere (il polling degli step è
     * idempotente: se un altro processo sta lavorando, si risponde lo stato
     * corrente e basta — stesso pattern di BackupManager::runStep).
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true; // già nostro
        }

        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($path, 'c');

        if ($handle === false) {
            return false;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    private function path(): string
    {
        return $this->path ??= (config('backup.restore.lock_file')
            ?: storage_path('app/backups/.restore-lock'));
    }
}
