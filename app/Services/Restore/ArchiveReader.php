<?php

namespace App\Services\Restore;

use App\Services\Restore\Exceptions\InvalidArchivePasswordException;
use App\Services\Restore\Exceptions\MalformedArchiveException;
use RuntimeException;
use ZipArchive;

/**
 * Lettura ed estrazione degli archivi di backup: la controparte in lettura
 * dell'Archiver (che sa solo scrivere).
 *
 * Il principio di fondo: NON ci si fida dell'archivio, nemmeno se l'abbiamo
 * scritto noi — un ripristino accetta anche file caricati via FTP o upload.
 * Ogni nome di voce viene validato prima di toccare il filesystem:
 *  - niente traversal (segmenti "..", percorsi assoluti, backslash, NUL);
 *  - niente symlink (attributi esterni Unix);
 *  - SOLO le voci che un backup di KondoManager può contenere
 *    (manifest.json, db/database.sql, files/**): qualunque altra voce è
 *    manomissione e interrompe tutto con MalformedArchiveException.
 *
 * Il percorso di scrittura è sempre ricostruito da noi sotto la cartella di
 * destinazione: il nome della voce non arriva MAI grezzo a filesystem.
 *
 * L'estrazione è a batch (indice di ripresa + limiti per numero di voci e
 * byte): è il pezzo che la fase "extracting" del ripristino chiama dentro
 * il budget di tempo di ogni step HTTP.
 */
class ArchiveReader
{
    private ?ZipArchive $zip = null;

    public function __construct(
        private readonly string $path,
        private readonly ?string $password = null,
    ) {}

    public function open(): void
    {
        if ($this->zip !== null) {
            return;
        }

        if (! is_file($this->path)) {
            throw new RuntimeException("Archivio non trovato: {$this->path}");
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::RDONLY) !== true) {
            throw new MalformedArchiveException(
                "Impossibile aprire l'archivio {$this->path}: file corrotto o non zip."
            );
        }

        if ($this->password !== null && $this->password !== '') {
            $zip->setPassword($this->password);
        }

        $this->zip = $zip;
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    public function entryCount(): int
    {
        $this->open();

        return $this->zip->numFiles;
    }

    /**
     * L'archivio ha almeno una voce cifrata? (Il flag `encrypted` del
     * manifest non basta: per leggerlo servirebbe già la password — e un
     * archivio manomesso potrebbe mentire.)
     */
    public function isEncrypted(): bool
    {
        $this->open();

        for ($index = 0; $index < $this->zip->numFiles; $index++) {
            $stat = $this->zip->statIndex($index);

            if ($stat !== false && ($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legge e decodifica manifest.json. È anche la VERIFICA della password:
     * su un archivio cifrato, una password assente o errata fa fallire
     * proprio questa lettura.
     */
    public function readManifest(): array
    {
        $this->open();

        $raw = $this->zip->getFromName('manifest.json');

        if ($raw === false) {
            if ($this->isEncrypted()) {
                throw new InvalidArchivePasswordException(
                    'Archivio cifrato: password mancante o errata.'
                );
            }

            throw new MalformedArchiveException(
                "L'archivio non contiene un manifest.json leggibile: non è un backup di KondoManager."
            );
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            throw new MalformedArchiveException('manifest.json corrotto: non è JSON valido.');
        }

        return $manifest;
    }

    /**
     * Estrae un batch di voci a partire da $fromIndex, entro i limiti dati.
     *
     * @return array{nextIndex: int, done: bool, files: int, bytes: int}
     */
    public function extractBatch(
        string $targetDir,
        int $fromIndex,
        int $maxEntries = 200,
        int $maxBytes = 67108864,
    ): array {
        $this->open();

        $files = 0;
        $bytes = 0;
        $index = max(0, $fromIndex);

        while ($index < $this->zip->numFiles && $files < $maxEntries && $bytes < $maxBytes) {
            $stat = $this->zip->statIndex($index);

            if ($stat === false) {
                throw new MalformedArchiveException("Voce #{$index} illeggibile: archivio corrotto.");
            }

            $name = (string) $stat['name'];
            $this->assertSafeEntryName($name);
            $this->assertNotSymlink($index, $name);

            // Voci-cartella: solo contenitori, niente da estrarre.
            if (str_ends_with($name, '/')) {
                $index++;

                continue;
            }

            $bytes += $this->extractEntry($index, $name, $stat, $targetDir);
            $files++;
            $index++;
        }

        return [
            'nextIndex' => $index,
            'done' => $index >= $this->zip->numFiles,
            'files' => $files,
            'bytes' => $bytes,
        ];
    }

    /* ------------------------------------------------------------------
     | Guardie
     | ------------------------------------------------------------------ */

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\')) {
            throw new MalformedArchiveException(
                "Voce con nome illegale nell'archivio: possibile manomissione."
            );
        }

        if (str_starts_with($name, '/')) {
            throw new MalformedArchiveException(
                "Voce con percorso assoluto nell'archivio (\"{$name}\"): possibile attacco zip-slip."
            );
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new MalformedArchiveException(
                    "Voce con traversal di percorso nell'archivio (\"{$name}\"): possibile attacco zip-slip."
                );
            }
        }

        $isAllowed = $name === 'manifest.json'
            || str_starts_with($name, 'db/')
            || str_starts_with($name, 'files/');

        if (! $isAllowed) {
            throw new MalformedArchiveException(
                "Voce inattesa nell'archivio (\"{$name}\"): un backup di KondoManager non la conterrebbe."
            );
        }
    }

    private function assertNotSymlink(int $index, string $name): void
    {
        $opsys = 0;
        $attributes = 0;

        if ($this->zip->getExternalAttributesIndex($index, $opsys, $attributes)
            && $opsys === ZipArchive::OPSYS_UNIX
            && ((($attributes >> 16) & 0xF000) === 0xA000)) {
            throw new MalformedArchiveException(
                "Voce symlink nell'archivio (\"{$name}\"): possibile manomissione."
            );
        }
    }

    private function extractEntry(int $index, string $name, array $stat, string $targetDir): int
    {
        // Percorso di destinazione ricostruito da noi: cartella target +
        // nome GIÀ validato. Mai il nome grezzo direttamente al filesystem.
        $target = rtrim($targetDir, '/').'/'.$name;
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Impossibile creare la cartella di estrazione {$directory}.");
        }

        $stream = $this->zip->getStream($name);

        if ($stream === false) {
            if (($stat['encryption_method'] ?? ZipArchive::EM_NONE) !== ZipArchive::EM_NONE) {
                throw new InvalidArchivePasswordException(
                    'Archivio cifrato: password mancante o errata.'
                );
            }

            throw new MalformedArchiveException("Voce \"{$name}\" illeggibile: archivio corrotto.");
        }

        $out = fopen($target, 'wb');

        if ($out === false) {
            fclose($stream);

            throw new RuntimeException("Impossibile scrivere il file estratto {$target}.");
        }

        $written = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        // Con la cifratura AES la manomissione emerge già dallo stream; per
        // gli archivi in chiaro il controllo dimensione è l'ultima rete.
        if ($written === false || $written !== (int) $stat['size']) {
            @unlink($target);

            throw new MalformedArchiveException(
                "Voce \"{$name}\" estratta incompleta: attesi {$stat['size']} byte."
            );
        }

        return (int) $written;
    }
}
