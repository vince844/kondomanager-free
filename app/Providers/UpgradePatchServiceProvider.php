<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Class UpgradePatchServiceProvider
 *
 * Questo provider è una "Exit Strategy" temporanea per la migrazione v1.8 -> v1.9.
 *
 * PROBLEMA:
 * Gli utenti che aggiornano dalla v1.8 usano il vecchio script di installazione che non conosce
 * la nuova variabile TRUSTED_PROXIES. Risultato: su hosting condivisi (Altervista, SiteGround)
 * o dietro Cloudflare, l'app si rompe (Mixed Content, Cron Job falliti) subito dopo l'aggiornamento.
 *
 * SOLUZIONE:
 * Questo provider viene caricato al boot di Laravel. Controlla se il .env è "vecchio" (manca la variabile)
 * e, se rileva un ambiente Proxy/Shared, inietta automaticamente la configurazione mancante.
 *
 * @package App\Providers
 */
class UpgradePatchServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Nessun servizio da registrare nel container.
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Esegue la logica di auto-riparazione del .env.
        // TODO: Rimuovere questo provider (e la registrazione in bootstrap/providers.php)
        // nella versione v1.11, quando si presume che tutti abbiano migrato o usino il nuovo installer.
        $this->autoPatchEnv();
    }

    /**
     * Inietta automaticamente TRUSTED_PROXIES=* nel .env se manca e se l'ambiente lo richiede.
     *
     * Questa funzione è "Fail-Safe": se qualcosa va storto (permessi, errori IO),
     * fallisce silenziosamente per non bloccare l'intera applicazione (White Screen of Death).
     *
     * @return void
     */
    protected function autoPatchEnv()
    {
        // 1. SAFETY CHECK:
        // Non eseguiamo mai questa logica in locale (sviluppo) o se il file .env non esiste (es. test CI/CD).
        if (!file_exists(base_path('.env')) || config('app.env') === 'local') {
            return;
        }

        // Leggiamo il contenuto attuale del file .env
        $envPath = base_path('.env');
        $content = file_get_contents($envPath);

        // 2. PERFORMANCE CHECK:
        // Se la variabile è già presente (l'utente l'ha messa a mano o è una nuova installazione v1.9),
        // usciamo subito per non sprecare risorse ad ogni singola request.
        if (strpos($content, 'TRUSTED_PROXIES') !== false) {
            return;
        }

        // --- LOGICA DI RILEVAMENTO AMBIENTE ---

        $host = $_SERVER['HTTP_HOST'] ?? '';

        // Rilevamento per NOME host (hosting condivisi noti). Su questi ambienti
        // PHP è raggiungibile SOLO attraverso il proxy dell'host, quindi scrivere
        // TRUSTED_PROXIES=* è sicuro: nessuno può connettersi all'origine per
        // falsificare gli header X-Forwarded-*.
        //
        // SICUREZZA — perché NON usiamo più il rilevamento per header proxy:
        // Prima qui c'era anche un ramo "$isBehindProxy" che attivava l'auto-'*'
        // sulla semplice presenza di X-Forwarded-For / X-Forwarded-Proto /
        // CF-Connecting-IP. Quegli header sono client-suppliable. Finché il fix
        // trusted-proxy era inerte (env letto troppo presto in bootstrap/app.php)
        // il ramo era innocuo; da quando è effettivo (config/trustedproxy.php),
        // scrivere '*' in base a un header falsificabile permetterebbe — su
        // un'origine raggiungibile direttamente — di far passare l'app in
        // "trust-all" con una singola richiesta non autenticata. Rimosso.
        // Chi sta dietro Cloudflare/nginx con dominio proprio deve impostare
        // TRUSTED_PROXIES a mano (preferibilmente la lista IP del proxy), come
        // raccomanda config/trustedproxy.php; il sintomo mixed-content è comunque
        // già coperto dal forceScheme in AppServiceProvider.
        $isRestricted = (strpos($host, 'altervista') !== false) ||
                        (strpos($host, '.av') !== false) ||
                        (strpos($host, 'infinityfree') !== false) ||
                        (strpos($host, 'netsons') !== false);

        // 3. APPLICAZIONE PATCH
        // Solo su hosting condivisi noti (origine non raggiungibile fuori dal proxy).
        if ($isRestricted) {
            try {
                // Prepariamo il blocco da appendere. Usiamo i commenti per far capire all'utente
                // che questa modifica è stata automatica.
                $patch = "\n\n# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*\n";

                // Scriviamo in append (FILE_APPEND) per non sovrascrivere nulla.
                file_put_contents($envPath, $patch, FILE_APPEND);
                
                // 4. RUNTIME (nota): da quando i trusted proxy si configurano
                // via config/trustedproxy.php (letto dal middleware come
                // config('trustedproxy.proxies'), risolto al boot da env), questi
                // putenv/$_ENV NON hanno più effetto sulla richiesta *corrente*:
                // la config è già stata risolta prima del boot dei provider. Il
                // fix diventa attivo dalla richiesta SUCCESSIVA, quando il .env
                // appena riscritto viene ricaricato. Le due righe restano
                // innocue e le teniamo per compatibilità con eventuali usi di
                // env('TRUSTED_PROXIES') a valle nella stessa richiesta.
                putenv("TRUSTED_PROXIES=*");
                $_ENV['TRUSTED_PROXIES'] = '*';
                
            } catch (\Exception $e) {
                // Fail silently: Se non riusciamo a scrivere (es. permessi 444 sul .env),
                // non dobbiamo far crashare il sito. L'utente avrà problemi grafici/https,
                // ma almeno potrà accedere e leggere eventuali avvisi.
            }
        }
    }
}