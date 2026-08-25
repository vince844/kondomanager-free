<?php

namespace App\Services\Backup;

use App\Services\Backup\Support\StepBudget;
use RuntimeException;
use ZipArchive;

/**
 * Costruisce l'archivio zip a batch riprendibili.
 *
 * ZipArchive tiene aperti i file descriptor e comprime solo alla close():
 * l'archivio viene quindi chiuso e riaperto a piccoli batch (per numero di
 * file e per byte), così ogni step resta nel budget di tempo e un backup
 * interrotto riparte dall'indice salvato nel checkpoint.
 *
 * Cifratura opzionale: quando viene fornita una password, ogni entry è
 * cifrata con AES-256 (le entry già scritte nei batch precedenti restano
 * cifrate come sono: alla riapertura basta ripassare la stessa password).
 */
class Archiver
{
    /**
     * Aggiunge un singolo file all'archivio in una sessione dedicata
     * (usato per il dump del database e per il manifest).
     */
    public function addSingleFile(string $zipPath, string $absolutePath, string $entryName, ?string $password = null): void
    {
        $zip = $this->open($zipPath, $password);

        if (! $zip->addFile($absolutePath, $entryName)) {
            $zip->close();
            throw new RuntimeException("Impossibile aggiungere {$entryName} all'archivio di backup.");
        }

        $this->encryptEntry($zip, $entryName, $password);

        $this->close($zip, $zipPath);
    }

    public function addFromString(string $zipPath, string $entryName, string $contents, ?string $password = null): void
    {
        $zip = $this->open($zipPath, $password);

        if (! $zip->addFromString($entryName, $contents)) {
            $zip->close();
            throw new RuntimeException("Impossibile aggiungere {$entryName} all'archivio di backup.");
        }

        $this->encryptEntry($zip, $entryName, $password);

        $this->close($zip, $zipPath);
    }

    /**
     * Fa avanzare l'archiviazione dei file utente entro il budget di tempo.
     *
     * @param  array<int, array{0: string, 1: int}>  $files  Coppie [percorso relativo, dimensione].
     * @param  array  $state  Checkpoint: {file_index: int, bytes_done: int}.
     * @return bool True quando tutti i file sono stati archiviati.
     */
    public function archiveStep(string $zipPath, array $files, array &$state, StepBudget $budget, ?string $password = null): bool
    {
        $state['file_index'] ??= 0;
        $state['bytes_done'] ??= 0;

        $basePath = rtrim(base_path(), DIRECTORY_SEPARATOR);
        $batchFiles = max(1, (int) config('backup.zip_batch_files', 200));
        $batchBytes = max(1024 * 1024, (int) config('backup.zip_batch_bytes', 64 * 1024 * 1024));
        $total = count($files);

        while ($state['file_index'] < $total && ! $budget->exceeded()) {
            $zip = $this->open($zipPath, $password);
            $addedFiles = 0;
            $addedBytes = 0;

            while (
                $state['file_index'] < $total
                && $addedFiles < $batchFiles
                && $addedBytes < $batchBytes
            ) {
                [$relative, $size] = $files[$state['file_index']];
                $absolute = $basePath.DIRECTORY_SEPARATOR.$relative;

                // Un file sparito tra l'enumerazione e questo step non deve
                // far fallire il backup: viene semplicemente saltato.
                if (is_file($absolute)) {
                    if (! $zip->addFile($absolute, 'files/'.$relative)) {
                        $this->close($zip, $zipPath);
                        throw new RuntimeException("Impossibile aggiungere files/{$relative} all'archivio di backup.");
                    }

                    $this->encryptEntry($zip, 'files/'.$relative, $password);

                    $addedBytes += (int) $size;
                }

                $state['file_index']++;
                $addedFiles++;
            }

            // La compressione avviene qui: è la parte costosa del batch.
            $this->close($zip, $zipPath);
            $state['bytes_done'] += $addedBytes;
        }

        return $state['file_index'] >= $total;
    }

    private function open(string $zipPath, ?string $password = null): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($zipPath, ZipArchive::CREATE);

        if ($result !== true) {
            throw new RuntimeException("Impossibile aprire l'archivio di backup (codice ZipArchive {$result}).");
        }

        if ($password !== null && ! $zip->setPassword($password)) {
            $zip->close();
            throw new RuntimeException("Impossibile impostare la password sull'archivio di backup.");
        }

        return $zip;
    }

    /**
     * Marca l'entry appena aggiunta per la cifratura AES-256. Va chiamata
     * prima della close(): è lì che avviene la scrittura vera e propria.
     */
    private function encryptEntry(ZipArchive $zip, string $entryName, ?string $password): void
    {
        if ($password === null) {
            return;
        }

        // Può fallire su build PHP con libzip senza supporto crittografico:
        // meglio un errore chiaro subito che un archivio silenziosamente in chiaro.
        if (! $zip->setEncryptionName($entryName, ZipArchive::EM_AES_256)) {
            $zip->close();
            throw new RuntimeException('Questo server non supporta la cifratura AES degli archivi zip (libzip senza crypto).');
        }
    }

    private function close(ZipArchive $zip, string $zipPath): void
    {
        if (! $zip->close()) {
            throw new RuntimeException("Errore durante la scrittura dell'archivio di backup {$zipPath}.");
        }
    }
}
