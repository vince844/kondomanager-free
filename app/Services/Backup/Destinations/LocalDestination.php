<?php

namespace App\Services\Backup\Destinations;

use App\Contracts\Backup\BackupDestination;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Destinazione locale: archivi salvati sul disco Laravel 'backups'
 * (storage/app/backups, fuori dalla document root).
 */
class LocalDestination implements BackupDestination
{
    public function __construct(private readonly string $disk = 'backups') {}

    public function name(): string
    {
        return 'local';
    }

    public function store(string $sourcePath, string $filename): void
    {
        $targetPath = $this->absolutePath($filename);

        File::ensureDirectoryExists(dirname($targetPath));

        // L'archivio temporaneo vive già sullo stesso filesystem: il rename
        // è atomico e non richiede spazio disco aggiuntivo.
        if (! @rename($sourcePath, $targetPath)) {
            throw new RuntimeException("Impossibile spostare l'archivio di backup in {$targetPath}");
        }
    }

    public function exists(string $filename): bool
    {
        return Storage::disk($this->disk)->exists($filename);
    }

    public function delete(string $filename): void
    {
        Storage::disk($this->disk)->delete($filename);
    }

    public function size(string $filename): int
    {
        return Storage::disk($this->disk)->size($filename);
    }

    public function localPath(string $filename): ?string
    {
        return $this->absolutePath($filename);
    }

    private function absolutePath(string $filename): string
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk->path($filename);
    }
}
