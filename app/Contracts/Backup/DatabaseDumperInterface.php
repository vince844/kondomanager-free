<?php

namespace App\Contracts\Backup;

use App\Services\Backup\Support\StepBudget;

/**
 * Contratto per i dumper del database.
 *
 * L'implementazione free è in puro PHP/PDO (nessun binario esterno, nessuna
 * dipendenza da proc_open: requisito degli hosting condivisi). Il plugin
 * backup potrà registrare implementazioni alternative (es. fast-path
 * mysqldump su VPS) tramite il container senza toccare il core.
 */
interface DatabaseDumperInterface
{
    /**
     * Fa avanzare il dump verso il completamento, scrivendo su $targetPath
     * (append). Lo stato riprendibile vive in $state: il dumper lo legge,
     * lo muta e il chiamante lo persiste come checkpoint. Deve rispettare
     * il budget di tempo e restituire true solo a dump completato.
     *
     * @param  string  $targetPath  Percorso assoluto del file di dump.
     * @param  array  $state  Stato riprendibile del dump (checkpoint).
     * @param  StepBudget  $budget  Budget di tempo dello step corrente.
     * @return bool True se il dump è completo, false se va ripreso in uno step successivo.
     */
    public function dump(string $targetPath, array &$state, StepBudget $budget): bool;

    /**
     * Nome del file di dump dentro l'archivio (es. "database.sql").
     */
    public function dumpFilename(): string;
}
