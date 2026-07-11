<?php

namespace Tests\Support;

use App\Contracts\Backup\DatabaseDumperInterface;
use App\Services\Backup\Support\StepBudget;

/**
 * Dumper finto per i test dello step-runner e del controller: scrive un
 * dump minimo e completa in un solo passo. La correttezza del dumper reale
 * è coperta da MySqlDumperRoundTripTest.
 */
class FakeBackupDumper implements DatabaseDumperInterface
{
    public function dumpFilename(): string
    {
        return 'database.sql';
    }

    public function dump(string $targetPath, array &$state, StepBudget $budget): bool
    {
        file_put_contents($targetPath, "-- dump di prova\nINSERT INTO finta VALUES (1);\n");

        $state['rows_written'] = ['finta' => 1];
        $state['rows_written_total'] = 1;
        $state['rows_estimated_total'] = 1;
        $state['warnings'] = [];
        $state['stage'] = 'done';

        return true;
    }
}
