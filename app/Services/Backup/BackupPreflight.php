<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Verifiche preliminari mostrate in UI prima di consentire un backup.
 *
 * Il wizard di installazione non verifica l'estensione zip, e il driver del
 * database può essere uno di quelli non supportati dal dumper: qui si
 * degrada in modo esplicito invece di fallire a metà lavoro.
 */
class BackupPreflight
{
    public const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    public function __construct(private readonly FileSelector $fileSelector) {}

    /**
     * @return array{
     *     ok: bool,
     *     ok_db_only: bool,
     *     checks: array<int, array{key: string, ok: bool, detail: array}>,
     *     estimated_bytes: int,
     *     estimated_db_bytes: int,
     *     free_bytes: ?int,
     *     encryption_supported: bool
     * }
     */
    public function run(): array
    {
        $checks = [];

        // Estensione zip
        $zipLoaded = extension_loaded('zip') && class_exists(\ZipArchive::class);
        $aes256Supported = $zipLoaded && method_exists(\ZipArchive::class, 'setEncryptionName');
        $checks[] = ['key' => 'zip_extension', 'ok' => $zipLoaded, 'detail' => ['aes256_supported' => $aes256Supported]];

        // Driver database supportato
        try {
            $driver = DB::connection()->getDriverName();
        } catch (\Throwable) {
            $driver = 'unknown';
        }

        $driverSupported = in_array($driver, self::SUPPORTED_DRIVERS, true);
        $checks[] = ['key' => 'db_driver', 'ok' => $driverSupported, 'detail' => ['driver' => $driver]];

        // Cartella dei backup scrivibile
        $backupsRoot = storage_path('app/backups');

        try {
            File::ensureDirectoryExists($backupsRoot.DIRECTORY_SEPARATOR.'tmp');
            $writable = is_writable($backupsRoot);
        } catch (\Throwable) {
            $writable = false;
        }

        $checks[] = ['key' => 'dir_writable', 'ok' => $writable, 'detail' => ['path' => 'storage/app/backups']];

        // Stima dello spazio richiesto vs spazio libero
        $margin = (float) config('backup.free_space_margin', 1.5);
        $databaseBytes = $this->estimateDatabaseBytes($driver);
        $estimated = $databaseBytes + $this->estimateFilesBytes();
        $required = (int) ceil($estimated * $margin);
        $free = $writable ? @disk_free_space($backupsRoot) : false;
        $free = $free === false ? null : (int) $free;

        $checks[] = [
            'key' => 'disk_space',
            'ok' => $free === null || $free > $required,
            'detail' => ['estimated_bytes' => $estimated, 'required_bytes' => $required, 'free_bytes' => $free],
        ];

        // Un backup "solo database" richiede molto meno spazio di uno completo:
        // sarebbe assurdo negarlo proprio sui server con il disco quasi pieno,
        // dove è l'unica opzione praticabile. Tutti i check tranne lo spazio
        // sono identici; lo spazio viene rivalutato sulla sola stima del DB.
        $nonSpaceOk = ! in_array(false, array_column(
            array_filter($checks, fn (array $check) => $check['key'] !== 'disk_space'),
            'ok'
        ), true);

        $dbOnlyRequired = (int) ceil($databaseBytes * $margin);

        return [
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'ok_db_only' => $nonSpaceOk && ($free === null || $free > $dbOnlyRequired),
            'checks' => $checks,
            'estimated_bytes' => $estimated,
            'estimated_db_bytes' => $databaseBytes,
            'free_bytes' => $free,
            // Cifratura AES degli zip: richiede PHP 7.2+ con libzip crypto.
            // Non è un check bloccante: se manca, la UI nasconde l'opzione password.
            'encryption_supported' => $aes256Supported,
        ];
    }

    private function estimateDatabaseBytes(string $driver): int
    {
        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                return (int) DB::connection()->getPdo()->query(
                    'SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0)
                     FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
                )->fetchColumn();
            }

            if ($driver === 'sqlite') {
                $database = DB::connection()->getDatabaseName();

                return is_string($database) && is_file($database) ? (int) filesize($database) : 0;
            }
        } catch (\Throwable) {
            // La stima è solo indicativa: un errore qui non deve bloccare la pagina.
        }

        return 0;
    }

    private function estimateFilesBytes(): int
    {
        try {
            return $this->fileSelector->collect()['total_bytes'];
        } catch (\Throwable) {
            return 0;
        }
    }
}
