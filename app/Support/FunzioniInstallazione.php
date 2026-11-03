<?php

namespace App\Support;

use App\Settings\GeneralSettings;

/**
 * Cosa questa installazione sa fare e cosa non fa, detto in un posto solo (1.11.0-beta.33).
 *
 * Quattro funzioni si accendono o spengono da fuori — dallo zip, dal `.env`, dall'ambiente del
 * container — e ognuna era letta dove serviva, con la sua chiave. Qui c'è la mappa, condivisa
 * al frontend come `funzioni`: la pagina «Questa installazione» la mostra, e chi deve nascondere
 * una voce di menù o una scheda ha una domanda sola da fare. Nessuna parola «piano» o «SaaS»:
 * sono fatti dell'installazione, uguali per chi si autoospita.
 */
final class FunzioniInstallazione
{
    /** @return array<string, bool> */
    public static function mappa(): array
    {
        return [
            // Wizard, controllo degli aggiornamenti e aggiornamento dal pannello: la stessa chiave
            // (config/installer.php, INSTALLER_ENABLED).
            'aggiornamenti_in_app' => config('installer.run_installer', false) === true,
            // Il pianificatore chiamato da fuori (cron-job.org e simili) invece del cron del server.
            'pianificatore_esterno' => self::pianificatoreEsterno(),
            // I backup interni (BACKUP_ENABLED): spenti dove li fa l'infrastruttura.
            'backup' => (bool) config('backup.enabled', true),
            // I documenti su un disco S3 (DOCUMENTI_DISK=s3) invece che sul server.
            'archiviazione_esterna' => config('kondomanager.disco_documenti', 'local') !== 'local',
        ];
    }

    private static function pianificatoreEsterno(): bool
    {
        try {
            return (bool) app(GeneralSettings::class)->external_cron_enabled;
        } catch (\Throwable) {
            // Prima dell'installazione la tabella delle impostazioni non c'è: la mappa non deve
            // rompere niente.
            return false;
        }
    }
}
