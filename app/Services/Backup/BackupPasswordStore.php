<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;

/**
 * Custodia della password di cifratura predefinita dei backup.
 *
 * La password è impostata una volta sola dall'amministratore e riusata per
 * tutti i backup cifrati (così non va ridigitata né dimenticata). È salvata
 * cifrata con l'APP_KEY in un file dentro storage/app/backups, che è già:
 * - fuori dalla document root (non raggiungibile via web),
 * - escluso dai backup stessi (config backup.exclude): la password non
 *   finisce MAI dentro un archivio, quindi un backup scaricato o rubato
 *   resta illeggibile senza conoscere la password a parte.
 *
 * Non è nel database: un dump del DB non la contiene. L'unico modo per
 * ottenerla è l'accesso diretto al file system del server live.
 */
class BackupPasswordStore
{
    private function path(): string
    {
        return config('backup.password_file') ?: storage_path('app/backups/.default-password');
    }

    public function has(): bool
    {
        return is_file($this->path());
    }

    /**
     * Password in chiaro, o null se non impostata / file corrotto.
     */
    public function get(): ?string
    {
        if (! $this->has()) {
            return null;
        }

        try {
            return Crypt::decryptString(File::get($this->path()));
        } catch (\Throwable) {
            return null;
        }
    }

    public function set(string $password): void
    {
        $path = $this->path();

        File::ensureDirectoryExists(dirname($path));
        File::put($path, Crypt::encryptString($password));
        @chmod($path, 0600);
    }

    public function clear(): void
    {
        if ($this->has()) {
            @unlink($this->path());
        }
    }
}
