<?php

namespace App\Contracts\Backup;

/**
 * Contratto per le destinazioni di salvataggio degli archivi di backup.
 *
 * Il free include la sola destinazione locale (disco 'backups'). Il plugin
 * backup potrà registrare destinazioni cloud (Google Drive, S3, ...) nel
 * DestinationManager: il core le userà attraverso questo contratto senza
 * conoscerne i dettagli.
 */
interface BackupDestination
{
    /**
     * Identificativo univoco della destinazione (es. "local").
     */
    public function name(): string;

    /**
     * Sposta l'archivio finito dalla posizione temporanea alla destinazione.
     *
     * @param  string  $sourcePath  Percorso assoluto dell'archivio temporaneo.
     * @param  string  $filename  Nome del file di destinazione.
     */
    public function store(string $sourcePath, string $filename): void;

    public function exists(string $filename): bool;

    public function delete(string $filename): void;

    public function size(string $filename): int;

    /**
     * Percorso assoluto locale dell'archivio, se la destinazione lo può
     * fornire (serve al download diretto). Le destinazioni remote
     * restituiscono null.
     */
    public function localPath(string $filename): ?string;
}
