<?php

namespace App\Services\Restore;

/**
 * La "modalità ripristino": finché il marker esiste, il middleware blocca
 * l'intera applicazione (503 statico) tranne le rotte di ripristino.
 *
 * È un file — non maintenance mode di Laravel (bloccherebbe anche noi e col
 * driver database sparirebbe durante l'import), non un flag in DB o cache
 * (stessa ragione). Il marker vive in storage/framework, NON dentro
 * storage/app/backups: deve restare in piedi anche mentre la fase file
 * ricostruisce storage/app.
 *
 * Il marker NON viene rimosso in caso di fallimento: un'app col database a
 * metà import non deve tornare raggiungibile. Lo rimuove solo la
 * finalizzazione riuscita o la decisione esplicita dell'admin (rollback).
 */
class RestoreMode
{
    public function __construct(private ?string $path = null) {}

    public function enter(string $restoreUuid, ?string $locale = null): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Salviamo la lingua dell'admin che avvia il ripristino: la pagina 503
        // (senza DB né sessione) la usa per mostrare UN SOLO idioma invece di
        // impilare tutte le traduzioni.
        file_put_contents($path, json_encode([
            'restore_uuid' => $restoreUuid,
            'locale' => $locale,
            'entered_at' => time(),
        ]));
    }

    public function exit(): void
    {
        if (file_exists($this->path())) {
            @unlink($this->path());
        }
    }

    /**
     * Chiamato dal middleware su OGNI richiesta: deve costare quanto un
     * file_exists e niente di più (nessun DB, nessuna sessione).
     */
    public function active(): bool
    {
        return file_exists($this->path());
    }

    public function info(): ?array
    {
        if (! $this->active()) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->path()), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function path(): string
    {
        return $this->path ??= (config('backup.restore.marker_file')
            ?: storage_path('framework/restore.lock'));
    }
}
