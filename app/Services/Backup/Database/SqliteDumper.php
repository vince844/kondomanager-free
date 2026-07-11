<?php

namespace App\Services\Backup\Database;

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Services\Backup\Support\StepBudget;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Dump" SQLite: copia consistente del file database.
 *
 * VACUUM INTO (SQLite 3.27+) produce uno snapshot compatto e coerente anche
 * a database in uso; se non disponibile si ripiega su una copia del file.
 * Completa sempre in un singolo step.
 */
class SqliteDumper implements DatabaseDumperInterface
{
    public function dumpFilename(): string
    {
        return 'database.sqlite';
    }

    public function dump(string $targetPath, array &$state, StepBudget $budget): bool
    {
        if (($state['stage'] ?? null) === 'done') {
            return true;
        }

        $source = DB::connection()->getDatabaseName();

        if (! is_string($source) || ! is_file($source)) {
            throw new RuntimeException('Il database SQLite non è un file su disco: backup non eseguibile.');
        }

        // VACUUM INTO richiede che il file di destinazione non esista.
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }

        $pdo = DB::connection()->getPdo();

        try {
            $pdo->exec('VACUUM INTO '.$pdo->quote($targetPath));
        } catch (\Throwable $e) {
            if (! @copy($source, $targetPath)) {
                throw new RuntimeException('Impossibile copiare il database SQLite: '.$e->getMessage());
            }
        }

        $state['stage'] = 'done';
        $state['rows_written'] = [];
        $state['rows_written_total'] = 0;
        $state['rows_estimated_total'] = 0;
        $state['warnings'] = [];

        return true;
    }
}
