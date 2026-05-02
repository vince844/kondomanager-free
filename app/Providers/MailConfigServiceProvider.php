<?php

namespace App\Providers;

use App\Settings\MailSettings;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;

/**
 * MailConfigServiceProvider
 *
 * Centralizza la configurazione del driver di posta a runtime.
 * Legge le impostazioni salvate nel database (tabella `settings` via Spatie)
 * e le applica alla configurazione Laravel prima di ogni richiesta HTTP
 * e prima di ogni job in coda che coinvolge l'invio email.
 *
 * Driver supportati: smtp, sendmail.
 * Fallback automatico alla configurazione .env se il DB non è disponibile
 * o se la configurazione da database è disattivata dall'utente.
 */
class MailConfigServiceProvider extends ServiceProvider
{
    /**
     * Snapshot della configurazione mail originale letta dal .env al boot.
     * Usato come fallback quando la configurazione da DB è disattivata.
     * Statico per essere inizializzato una sola volta per ciclo di vita dell'app.
     */
    protected static ?array $defaultConfig = null;

    public function boot(): void
    {
        // Evita crash durante installazione o quando il DB non è raggiungibile
        if (!$this->isDatabaseReady()) return;

        // Evita crash durante le migrazioni, prima che la tabella esista
        if (!Schema::hasTable('settings')) return;

        // Salva la config .env originale una sola volta (smtp + from)
        if (static::$defaultConfig === null) {
            static::$defaultConfig = [
                'smtp' => config('mail.mailers.smtp'),
                'from' => config('mail.from'),
            ];
        }

        // Sincronizza la config per le richieste HTTP sincrone
        $this->syncMailConfig();

        // Sincronizza la config per i job in coda (asincroni)
        // I worker hanno un ciclo di vita lungo: senza questo hook
        // userebbero sempre la config del momento del boot, ignorando
        // eventuali modifiche salvate successivamente nel pannello.
        Queue::before(function (JobProcessing $event) {
            try {
                if ($this->isMailJob($event->job->resolveName())) {
                    $this->syncMailConfig($event->job->resolveName());
                }
            } catch (\Throwable $e) {
                Log::error('Mail queue sync error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Verifica che il database sia raggiungibile prima di tentare
     * qualsiasi query. Previene errori fatali durante l'installazione
     * o in ambienti con DB non configurato.
     */
    protected function isDatabaseReady(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Punto centrale di sincronizzazione.
     * Legge le impostazioni dal DB (bypass cache Spatie con ->refresh())
     * e delega al metodo corretto in base al driver scelto dall'utente.
     * In caso di errore imprevisto, fallback sicuro al driver log.
     *
     * @param string|null $jobName Nome del job (usato solo per il log in debug)
     */
    protected function syncMailConfig(?string $jobName = null): void
    {
        try {
            $settings = app(MailSettings::class)->refresh();

            // Se la configurazione da DB è disattivata, usa il fallback .env
            if (!$settings->mail_enabled) {
                $this->applyEnvFallback();
                return;
            }

            // Seleziona il metodo di configurazione in base al driver scelto
            match ($settings->mail_driver ?? 'smtp') {
                'sendmail' => $this->applySendmailConfig($settings),
                default    => $this->applySmtpConfig($settings, $jobName),
            };

            // Forza la ricreazione del mailer con la nuova configurazione.
            // Senza questo, Laravel riuserebbe l'istanza già in cache
            // con i parametri vecchi.
            if (app()->resolved('mail.manager')) {
                app('mail.manager')->forgetMailers();
            }

        } catch (\Exception $e) {
            Config::set('mail.default', 'log');
            Log::error('Mail Config Sync Error: ' . $e->getMessage());
        }
    }

    /**
     * Applica la configurazione SMTP letta dal database.
     * Se l'host è vuoto (configurazione incompleta), fa fallback al .env.
     * La password viene decriptata al volo — è salvata cifrata nel DB.
     *
     * @param string|null $jobName Usato solo per il log debug in coda
     */
    protected function applySmtpConfig(MailSettings $settings, ?string $jobName = null): void
    {
        if (empty($settings->mail_host)) {
            $this->applyEnvFallback();
            return;
        }

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp', [
            'transport'  => 'smtp',
            'host'       => $settings->mail_host,
            'port'       => (int) $settings->mail_port,
            'username'   => $settings->mail_username,
            'password'   => $this->decryptPassword($settings->mail_password),
            'encryption' => $settings->mail_encryption === 'null' ? null : $settings->mail_encryption,
            'timeout'    => 30,
        ]);
        $this->applyFrom($settings);

        if (config('app.debug') && $jobName) {
            Log::info('Queue: SMTP caricato da DB', [
                'job'  => class_basename($jobName),
                'host' => $settings->mail_host,
            ]);
        }
    }

    /**
     * Applica la configurazione Sendmail.
     * Non richiede credenziali: usa il binario sendmail del server locale.
     * Ideale per hosting condivisi (es. Altervista) dove le porte SMTP
     * esterne (465, 587) sono bloccate dal firewall dell'hosting.
     */
    protected function applySendmailConfig(MailSettings $settings): void
    {
        Config::set('mail.default', 'sendmail');
        $this->applyFrom($settings);
    }

    /**
     * Ripristina la configurazione originale letta dal .env al boot.
     * Se il .env non ha un host SMTP valido (es. localhost o vuoto),
     * imposta il driver log per evitare errori silenziosi.
     */
    protected function applyEnvFallback(): void
    {
        $envHost = static::$defaultConfig['smtp']['host'] ?? null;

        if (empty($envHost) || in_array($envHost, ['127.0.0.1', 'localhost'])) {
            Config::set('mail.default', 'log');
        } else {
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp', static::$defaultConfig['smtp']);
            Config::set('mail.from', static::$defaultConfig['from']);
        }
    }

    /**
     * Imposta il mittente (from address e from name) dalla configurazione DB.
     * Estratto in metodo separato perché comune a tutti i driver.
     */
    protected function applyFrom(MailSettings $settings): void
    {
        Config::set('mail.from', [
            'address' => $settings->mail_from_address,
            'name'    => $settings->mail_from_name,
        ]);
    }

    /**
     * Decripta la password SMTP salvata cifrata nel database.
     * Fallback alla stringa grezza se la decriptazione fallisce
     * (es. dati migrati da versioni precedenti non cifrate).
     */
    protected function decryptPassword(?string $encrypted): ?string
    {
        if (!$encrypted) return null;
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception $e) {
            return $encrypted;
        }
    }

    /**
     * Determina se un job in coda richiede la configurazione mail.
     * Usato dall'hook Queue::before per evitare di sincronizzare
     * inutilmente la config per job che non inviano email.
     */
    protected function isMailJob(string $jobName): bool
    {
        return str_contains($jobName, 'Mail')
            || str_contains($jobName, 'Notification')
            || str_contains($jobName, 'Illuminate\Notifications\SendQueuedNotifications')
            || str_contains($jobName, 'Illuminate\Mail\SendQueuedMailable');
    }
}