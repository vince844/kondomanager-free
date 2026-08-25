<?php

namespace App\Services\Restore;

use App\Services\Backup\BackupPreflight;
use App\Services\Restore\Exceptions\IncompatibleBackupException;
use Illuminate\Support\Facades\DB;

/**
 * Verifiche preliminari di un ripristino, PRIMA che si tocchi qualsiasi
 * cosa. Separa i controlli BLOCCANTI (versione più nuova del codice, driver
 * incompatibile, formato manifest sconosciuto → eccezione) dalle
 * informazioni che la UI mostra per la conferma consapevole (versione
 * d'origine, contenuto, cambio APP_KEY, cambio dominio).
 *
 * NON valida app.url (decisione di design: il dominio può legittimamente
 * cambiare in un trasferimento) e NON usa la sessione/DB per lo stato
 * (la mutua esclusione è gestita dal RestoreManager via lock file).
 */
class RestorePreflight
{
    /** Formati di manifest che questo codice sa ripristinare. */
    public const SUPPORTED_MANIFEST_FORMATS = [1];

    public function __construct(private readonly BackupPreflight $backupPreflight) {}

    /**
     * @param  array  $manifest  già letto e decodificato (ArchiveReader::readManifest)
     * @return array{
     *     ok: bool,
     *     manifest_format: int,
     *     source_version: ?string,
     *     current_version: string,
     *     needs_migration: bool,
     *     contents: string,
     *     encrypted: bool,
     *     source_url: ?string,
     *     current_url: ?string,
     *     url_changes: bool,
     *     driver: ?string,
     *     current_driver: string,
     *     free_bytes: ?int,
     *     estimated_needed_bytes: int,
     *     enough_space: bool
     * }
     *
     * @throws IncompatibleBackupException su condizioni bloccanti
     */
    public function inspect(array $manifest, int $archiveBytes): array
    {
        $format = (int) ($manifest['manifest_format'] ?? 0);

        if (! in_array($format, self::SUPPORTED_MANIFEST_FORMATS, true)) {
            throw new IncompatibleBackupException(
                "Formato del backup non supportato (manifest_format {$format}): l'archivio è stato creato da una versione non compatibile."
            );
        }

        $sourceVersion = $manifest['app']['version'] ?? null;
        $currentVersion = (string) config('app.version');

        // Downgrade non supportato: un backup di una versione PIÙ NUOVA del
        // codice attuale contiene schemi/dati che questo codice non conosce.
        if (is_string($sourceVersion) && version_compare($sourceVersion, $currentVersion, '>')) {
            throw new IncompatibleBackupException(
                "Il backup proviene da una versione più recente ({$sourceVersion}) di quella installata ({$currentVersion}). "
                .'Aggiorna prima KondoManager, poi riprova il ripristino.'
            );
        }

        $driver = $manifest['database']['driver'] ?? null;
        $currentDriver = $this->currentDriver();

        if (is_string($driver) && ! in_array($driver, BackupPreflight::SUPPORTED_DRIVERS, true)) {
            throw new IncompatibleBackupException(
                "Il backup usa un driver di database non supportato ({$driver})."
            );
        }

        // Cambio di famiglia di driver (es. sqlite → mysql): fuori scope v1.
        if (is_string($driver) && $this->driverFamily($driver) !== $this->driverFamily($currentDriver)) {
            throw new IncompatibleBackupException(
                "Il backup è stato creato con {$driver}, ma questa installazione usa {$currentDriver}: "
                .'il ripristino tra database diversi non è supportato.'
            );
        }

        $free = @disk_free_space(storage_path('app/backups'));
        $free = $free === false ? null : (int) $free;

        // Serve spazio per l'archivio estratto (stimato ~ la dimensione
        // dell'archivio; il dump non cifrato può essere più grande dello zip)
        // con un margine di sicurezza.
        $margin = (float) config('backup.free_space_margin', 1.5);
        $needed = (int) ceil($archiveBytes * $margin);

        $sourceUrl = $manifest['app']['url'] ?? null;
        $currentUrl = config('app.url');

        return [
            'ok' => true,
            'manifest_format' => $format,
            'source_version' => is_string($sourceVersion) ? $sourceVersion : null,
            'current_version' => $currentVersion,
            'needs_migration' => is_string($sourceVersion) && version_compare($sourceVersion, $currentVersion, '<'),
            'contents' => (string) ($manifest['contents'] ?? 'full'),
            'encrypted' => (bool) ($manifest['encrypted'] ?? false),
            'source_url' => is_string($sourceUrl) ? $sourceUrl : null,
            'current_url' => is_string($currentUrl) ? $currentUrl : null,
            'url_changes' => is_string($sourceUrl) && is_string($currentUrl) && $sourceUrl !== $currentUrl,
            'driver' => is_string($driver) ? $driver : null,
            'current_driver' => $currentDriver,
            'free_bytes' => $free,
            'estimated_needed_bytes' => $needed,
            'enough_space' => $free === null || $free > $needed,
        ];
    }

    private function currentDriver(): string
    {
        try {
            return DB::connection()->getDriverName();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /** mysql e mariadb sono intercompatibili; sqlite è una famiglia a sé. */
    private function driverFamily(string $driver): string
    {
        return in_array($driver, ['mysql', 'mariadb'], true) ? 'mysql' : $driver;
    }
}
