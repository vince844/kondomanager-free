<?php

namespace App\Services\Restore;

use RuntimeException;

/**
 * Stato del ripristino su FILE (mai nel database): l'import sovrascrive il
 * database stesso — sessioni, cache e tabella backups incluse — quindi il
 * checkpoint deve sopravvivere fuori. Stesso ruolo della colonna checkpoint
 * per i backup, ma su disco (pattern install-progress.json dell'installer).
 *
 * Le scritture sono atomiche (file temporaneo nella stessa cartella +
 * rename): uno step interrotto a metà scrittura non lascia mai uno stato
 * corrotto, al massimo quello precedente.
 *
 * Contiene anche il token di autenticazione degli step: la sessione
 * dell'admin muore quando l'import sovrascrive la tabella sessions, quindi
 * gli step si autenticano con un token monouso (pattern update_bridge.json
 * di UpdateService) di cui qui vive SOLO l'hash — il valore in chiaro esce
 * una volta sola, verso il browser che ha avviato il ripristino.
 *
 * Classe volutamente senza dipendenze dal framework (config() solo come
 * fallback del percorso): testabile in isolamento puro.
 */
class RestoreState
{
    public function __construct(private ?string $path = null) {}

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @throws RuntimeException se il file esiste ma non è JSON valido:
     *                          uno stato corrotto non va MAI interpretato
     *                          come "nessun ripristino in corso".
     */
    public function get(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $raw = file_get_contents($this->path());
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            throw new RuntimeException(
                "Stato del ripristino illeggibile ({$this->path()}): file corrotto o manomesso."
            );
        }

        return $decoded;
    }

    public function put(array $state): array
    {
        $state['updated_at'] = time();

        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $temp = $path.'.tmp.'.bin2hex(random_bytes(4));
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($temp, $json) === false || ! rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException("Impossibile scrivere lo stato del ripristino in {$path}.");
        }

        @chmod($path, 0600);

        return $state;
    }

    /**
     * Legge, applica la patch e riscrive atomicamente. Le chiavi della patch
     * sovrascrivono quelle esistenti (array_replace, non ricorsivo: chi
     * aggiorna un checkpoint lo sostituisce per intero, mai per pezzi).
     */
    public function merge(array $patch): array
    {
        return $this->put(array_replace($this->get() ?? [], $patch));
    }

    public function clear(): void
    {
        if ($this->exists()) {
            @unlink($this->path());
        }
    }

    /* ------------------------------------------------------------------
     | Token degli step
     | ------------------------------------------------------------------ */

    /**
     * Genera il token di step, ne salva l'HASH nello stato e restituisce il
     * valore in chiaro: va mostrato/consegnato UNA volta sola. È anche la
     * "chiave di ripresa" che l'admin conserva per riprendere un ripristino
     * interrotto (la sessione a quel punto non esiste più).
     */
    public function issueToken(int $ttlSeconds): string
    {
        $token = bin2hex(random_bytes(32));

        $this->merge([
            'token_hash' => hash('sha256', $token),
            'token_expires_at' => time() + $ttlSeconds,
        ]);

        return $token;
    }

    public function validateToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $state = $this->get();
        } catch (RuntimeException) {
            return false;
        }

        $hash = $state['token_hash'] ?? null;
        $expiresAt = $state['token_expires_at'] ?? 0;

        if (! is_string($hash) || $hash === '' || time() > (int) $expiresAt) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    private function path(): string
    {
        return $this->path ??= (config('backup.restore.state_file')
            ?: storage_path('app/backups/.restore-state.json'));
    }
}
