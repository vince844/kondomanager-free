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
 * Provider centralizzato per la configurazione Mail.
 * * Funzionalità:
 * - Configurazione SMTP da Database con fallback su .env
 * - Sincronizzazione automatica per Queue Jobs
 * - Reset intelligente del Mail Manager
 * - Supporto hosting condiviso e server dedicati
 * - Backup completo di SMTP + FROM per ripristino sicuro
 */
class MailConfigServiceProvider extends ServiceProvider
{
    /**
     * Cache statica della configurazione originale del .env
     * Salva TUTTO: server (smtp) + identità (from)
     */
    protected static $defaultConfig = null;

    public function boot(): void
    {
        // ================================================================
        // 0. SAFETY CHECK: BYPASS DURANTE L'INSTALLAZIONE
        // Se l'app non è ancora configurata o il DB è giù, ci fermiamo qui
        // ================================================================
        if (!$this->isDatabaseReady()) {
            return;
        }

        // Verifica che la tabella settings esista (evita crash durante migrazioni)
        if (!Schema::hasTable('settings')) {
            return;
        }

        // ================================================================
        // 1. BACKUP COMPLETO: Salva TUTTA la config mail del .env
        // ================================================================
        if (static::$defaultConfig === null) {
            static::$defaultConfig = [
                'smtp' => config('mail.mailers.smtp'), // Host, Port, Username, Password, Encryption
                'from' => config('mail.from'),         // Address, Name
            ];
        }

        // ================================================================
        // 2. CONFIGURAZIONE PER RICHIESTE WEB (Sincrone)
        // ================================================================
        $this->syncMailConfig();

        // ================================================================
        // 3. CONFIGURAZIONE PER QUEUE (Asincrone)
        // ================================================================
        Queue::before(function (JobProcessing $event) {
            try {
                $jobName = $event->job->resolveName();
                
                if ($this->isMailJob($jobName)) {
                    $this->syncMailConfig($jobName);
                }
            } catch (\Throwable $e) {
                Log::error('Mail queue sync error: ' . $e->getMessage());
            }
        });
    }

    /**
     * Helper per verificare in modo sicuro se possiamo parlare col DB.
     */
    protected function isDatabaseReady(): bool
    {
        try {
            // Controlla se la connessione base risponde (senza lanciare eccezioni fatali)
            DB::connection()->getPdo();
            return true;
        } catch (\Exception $e) {
            // Il DB non è configurato o irraggiungibile (es. installazione in corso)
            return false;
        }
    }

    /**
     * Sincronizza la configurazione SMTP dal Database al Runtime.
     * * @param string|null $jobName Nome del job (solo per logging)
     * @return void
     */
    protected function syncMailConfig(?string $jobName = null): void
    {
        try {
            // Ricarica i settings dal database (bypass cache Spatie)
            $settings = app(MailSettings::class)->refresh();

            // ============================================================
            // CASO 1: Database ATTIVO e configurato
            // ============================================================
            if ($settings->mail_enabled && !empty($settings->mail_host)) {
                
                // Costruisce configurazione SMTP completa
                $smtpConfig = [
                    'transport' => 'smtp',
                    'host' => $settings->mail_host,
                    'port' => (int) $settings->mail_port,
                    'username' => $settings->mail_username,
                    'password' => $this->decryptPassword($settings->mail_password),
                    'encryption' => $settings->mail_encryption === 'null' ? null : $settings->mail_encryption,
                    'timeout' => 30,
                ];

                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp', $smtpConfig);
                
                // IMPORTANTE: Sovrascrivi anche il mittente (FROM)
                Config::set('mail.from', [
                    'address' => $settings->mail_from_address,
                    'name' => $settings->mail_from_name,
                ]);

                // Log solo in debug mode e solo per queue jobs
                if (config('app.debug') && $jobName) {
                    Log::info('Queue: SMTP caricato da DB', [
                        'job' => class_basename($jobName),
                        'host' => $settings->mail_host,
                        'from' => $settings->mail_from_address,
                    ]);
                }
            } 
            // ============================================================
            // CASO 2: Database SPENTO - Ripristina .env COMPLETO
            // ============================================================
            else {
                $envHost = static::$defaultConfig['smtp']['host'] ?? null;

                // Se .env è vuoto o localhost, usa il driver log (sicurezza)
                if (empty($envHost) || in_array($envHost, ['127.0.0.1', 'localhost'])) {
                    Config::set('mail.default', 'log');
                } else {
                    // Ripristina TUTTO: SMTP + FROM originale del .env
                    Config::set('mail.default', 'smtp');
                    Config::set('mail.mailers.smtp', static::$defaultConfig['smtp']);
                    Config::set('mail.from', static::$defaultConfig['from']);
                }
            }

            // ============================================================
            // RESET DEL MAIL MANAGER
            // ============================================================
            if (app()->resolved('mail.manager')) {
                app('mail.manager')->forgetMailers();
            }

        } catch (\Exception $e) {
            // Fallback sicuro: usa il driver log
            Config::set('mail.default', 'log');
            Log::error('Mail Config Sync Error: ' . $e->getMessage());
        }
    }

    /**
     * Decripta la password SMTP in modo sicuro.
     * * @param string|null $encrypted Password criptata
     * @return string|null Password in chiaro
     */
    protected function decryptPassword(?string $encrypted): ?string
    {
        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception $e) {
            // Fallback: usa password raw (potrebbe non essere criptata)
            return $encrypted;
        }
    }

    /**
     * Identifica se un job necessita della configurazione Mail.
     * * @param string $jobName Nome completo della classe del job
     * @return bool
     */
    protected function isMailJob(string $jobName): bool
    {
        // Controllo generico
        if (str_contains($jobName, 'Mail') || str_contains($jobName, 'Notification')) {
            return true;
        }

        // Pattern espliciti Laravel
        $mailJobPatterns = [
            'Illuminate\Notifications\SendQueuedNotifications',
            'Illuminate\Mail\SendQueuedMailable',
        ];

        foreach ($mailJobPatterns as $pattern) {
            if (str_contains($jobName, $pattern)) {
                return true;
            }
        }

        return false;
    }
}