<?php

namespace App\Services\Backup\Destinations;

use App\Contracts\Backup\BackupDestination;
use Closure;
use InvalidArgumentException;

/**
 * Registro delle destinazioni di backup disponibili.
 *
 * Il free registra la sola destinazione 'local'. Il plugin backup potrà
 * aggiungerne altre dal proprio service provider:
 *
 *     app(DestinationManager::class)->extend('gdrive', fn () => new GoogleDriveDestination(...));
 */
class DestinationManager
{
    /** @var array<string, Closure(): BackupDestination> */
    protected array $creators = [];

    /** @var array<string, BackupDestination> */
    protected array $resolved = [];

    public function __construct()
    {
        $this->extend('local', fn () => new LocalDestination(config('backup.disk', 'backups')));
    }

    public function extend(string $name, Closure $creator): void
    {
        $this->creators[$name] = $creator;
        unset($this->resolved[$name]);
    }

    public function destination(?string $name = null): BackupDestination
    {
        $name = $name ?: 'local';

        if (! isset($this->creators[$name])) {
            throw new InvalidArgumentException("Destinazione di backup [{$name}] non registrata.");
        }

        return $this->resolved[$name] ??= ($this->creators[$name])();
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->creators);
    }
}
