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
            'app' => [
                'name' => (string) config('app.name'),
                'version' => (string) config('app.version'),
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
            'env_file' => 'files/.env',
            'warnings' => array_values($dumpState['warnings'] ?? []),
        ];
    }
}
