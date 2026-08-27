<?php

namespace App\Services\Backup;

use App\Models\Backup;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Costruisce il manifest.json incluso in ogni archivio di backup.
 *
 * Il manifest è versionato (manifest_format): il futuro ripristino da UI
 * lo validerà per verificare compatibilità di versione e integrità dei
 * componenti senza dover ispezionare l'intero archivio.
 */
class ManifestBuilder
{
    /**
     * @param  array  $dumpState  Checkpoint finale del dumper (conteggi righe, warnings).
     * @param  array{count: int, bytes: int}  $filesMeta  Totali dei file archiviati.
     */
    public function build(Backup $backup, string $dumpFilename, ?string $dumpChecksum, array $dumpState, array $filesMeta): array
    {
        $connection = DB::connection();

        try {
            $databaseVersion = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (\Throwable) {
            $databaseVersion = null;
        }

        return [
            'manifest_format' => 1,
            'generator' => 'kondomanager-free',
            'created_at' => now()->toIso8601String(),
            'backup_uuid' => $backup->uuid,
            // 'full' = database + documenti + .env; 'db_only' = solo dump database
            'contents' => $backup->type ?? Backup::TYPE_FULL,
            'encrypted' => (bool) $backup->encrypted,
            'app' => [
                'name' => (string) config('app.name'),
                // ⚠️ La versione REGISTRATA A DATABASE, non quella dei file.
                //
                // Nel caso normale coincidono e non cambia nulla. Divergono in un caso solo, ed è
                // quello che conta: il **backup di sicurezza pre-aggiornamento** gira quando i file
                // della versione nuova hanno già sostituito i vecchi, ma le migrazioni non sono
                // ancora partite. Con `config('app.version')` l'archivio si sarebbe timbrato
                // «1.11.0» pur contenendo uno schema 1.10.0.
                //
                // La conseguenza, misurata il 27/08/2026 sull'artefatto vero: l'amministratore a
                // cui l'aggiornamento va male rimette i file 1.10.0 — la reazione naturale — e
                // `RestorePreflight` rifiuta il backup «proveniente da una versione più recente».
                // Il prodotto rifiutava la propria rete di sicurezza nell'unico momento in cui
                // serviva.
                //
                // Perché qui e non in un campo nuovo: nello scenario del rollback l'archivio viene
                // letto dal codice **vecchio**, che un campo nuovo non lo guarda. E `manifest_format`
                // non si alza per lo stesso motivo — `SUPPORTED_MANIFEST_FORMATS` è una lista
                // chiusa, quindi un formato 2 verrebbe rifiutato prima ancora di leggere la
                // versione, peggiorando esattamente il caso che vogliamo curare.
                //
                // La versione di un backup descrive **ciò che contiene**, non ciò che l'ha prodotto.
                'version' => $this->versioneDelloSchema(),
                // Additivo, e ignorato dai lettori precedenti: serve a chi guarda un archivio per
                // capire in che momento è stato prodotto. Nel backup pre-aggiornamento le due
                // versioni sono diverse, ed è l'unico segno che lo dice.
                'files_version' => (string) config('app.version'),
                'url' => (string) config('app.url'),
            ],
            'php_version' => PHP_VERSION,
            'database' => [
                'driver' => $connection->getDriverName(),
                'version' => $databaseVersion,
                'dump_file' => 'db/'.$dumpFilename,
                'dump_sha256' => $dumpChecksum,
                'tables' => count($dumpState['rows_written'] ?? []),
                'rows' => (int) ($dumpState['rows_written_total'] ?? 0),
                'rows_per_table' => $dumpState['rows_written'] ?? [],
            ],
            // Le migrazioni eseguite: al ripristino su una versione più nuova
            // basterà un artisan migrate per riallineare lo schema.
            'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
            'files' => [
                'prefix' => 'files/',
                'count' => (int) ($filesMeta['count'] ?? 0),
                'bytes' => (int) ($filesMeta['bytes'] ?? 0),
            ],
            // Nei backup "solo database" il file .env non è incluso
            'env_file' => ($backup->type ?? Backup::TYPE_FULL) === Backup::TYPE_DB_ONLY ? null : 'files/.env',
            'warnings' => array_values($dumpState['warnings'] ?? []),
        ];
    }

    /**
     * La versione dell'applicazione a cui lo schema contenuto nel dump corrisponde.
     *
     * È quella registrata nelle impostazioni, cioè quella che le migrazioni hanno raggiunto —
     * non quella dei file, che durante un aggiornamento sono già stati sostituiti.
     *
     * Ripiega sui file se le impostazioni non sono leggibili: succede su un'installazione
     * mai inizializzata, e in quel caso le due versioni coincidono comunque.
     */
    private function versioneDelloSchema(): string
    {
        try {
            $registrata = app(\App\Settings\GeneralSettings::class)->version ?? null;
        } catch (\Throwable) {
            $registrata = null;
        }

        return is_string($registrata) && $registrata !== ''
            ? $registrata
            : (string) config('app.version');
    }
}
