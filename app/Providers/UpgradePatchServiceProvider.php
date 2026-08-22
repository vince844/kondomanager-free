<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Scrive nel `.env` la configurazione dei proxy fidati, quando manca.
 *
 * ## Il problema che risolve
 *
 * Chi aggiorna da una v1.8 ha un `.env` scritto da un installer che non conosceva
 * `TRUSTED_PROXIES`. Su hosting condiviso o dietro un proxy, senza quella variabile Laravel non si
 * fida degli header `X-Forwarded-*`: `request()->ip()` restituisce l'indirizzo del proxy invece di
 * quello del visitatore, e lo schema `https` non viene riconosciuto — da cui contenuti misti e
 * collegamenti sbagliati.
 *
 * ## ⚠️ Cosa è cambiato nella beta.65, e perché
 *
 * Fino alla beta.64 questo provider decideva **guardando `HTTP_HOST`**, cercandoci dentro
 * `altervista`, `.av`, `infinityfree`, `netsons`, e in caso di corrispondenza scriveva
 * `TRUSTED_PROXIES=*`. Due difetti, tutti e due seri:
 *
 * 1. **`HTTP_HOST` lo manda il client.** Dove il web server accetta qualunque `Host` — l'nginx del
 *    `Dockerfile` di root ascolta senza `server_name`, quindi è il *default server* — una richiesta
 *    non autenticata con `Host: x.av` faceva scrivere quella riga, e ci restava.
 * 2. **`strpos($host, '.av')` è una sottostringa, non un dominio.** Prendeva `studio.aversa.it`,
 *    `condominio.avellino.it`, `mio.avvocato.it`: falso positivo con la stessa conseguenza, senza
 *    nessun attaccante.
 *
 * E la conseguenza non è teorica: con `proxies='*'` Laravel crede a `X-Forwarded-For` da chiunque,
 * quindi `request()->ip()` lo sceglie il client — e con esso le chiavi di throttle del login
 * (`email|ip`) e della doppia autenticazione (`userid|2fa|ip`). Ruotando indirizzi falsi **il blocco
 * a cinque tentativi non arriva mai**.
 *
 * ## La correzione: un valore che va bene ovunque, quindi niente da indovinare
 *
 * Si scrive **`TRUSTED_PROXIES=PRIVATE_SUBNETS`**, che è un token riconosciuto da Symfony
 * (`Request::setTrustedProxies()` lo sostituisce con `IpUtils::PRIVATE_SUBNETS`: loopback, le reti
 * RFC1918, la CGNAT `100.64/10` e gli equivalenti IPv6).
 *
 * - **È sicuro su qualunque host.** Chi arriva da internet ha per forza un `REMOTE_ADDR` pubblico,
 *   quindi non rientra in quelle reti e i suoi `X-Forwarded-*` non vengono mai creduti.
 * - **Funziona sugli hosting condivisi e su ogni nginx/apache davanti a php-fpm**, dove il proxy è
 *   loopback o su rete privata.
 * - Se un hosting mette il proprio proxy su un indirizzo **pubblico**, non lo copre: là
 *   l'amministratore mette la lista esplicita, che è quello che `config/trustedproxy.php` già
 *   raccomanda. È un «non aiuta», non un «rompe».
 *
 * **Siccome quel valore è innocuo dove non serve, non c'è più niente da rilevare**: tutto il ramo
 * che leggeva l'host è sparito, e con esso la vulnerabilità.
 *
 * ## Le regole che proteggono le installazioni esistenti
 *
 * Il confine è uno: **si ripulisce solo ciò che abbiamo scritto noi, e lo si dice.**
 *
 * - Una riga `TRUSTED_PROXIES` scritta a mano **non si tocca mai**, qualunque valore abbia.
 * - Il blocco che avevamo scritto noi si riconosce dal suo commento, e si sostituisce **solo se è
 *   ancora esattamente come l'avevamo lasciato**. Se qualcuno ci ha messo mano dentro — anche solo
 *   cambiando il valore — quella è diventata una sua scelta e resta.
 * - Il blocco nuovo **dice cosa è successo, quando e come tornare indietro**, perché modificare
 *   un'impostazione di sicurezza sul server di qualcun altro senza dirglielo non si fa.
 * - Non si cancella mai niente: si sostituisce un blocco nostro o si aggiunge in coda.
 *
 * ## Quando gira
 *
 * Solo quando `installer.run_installer` è attivo — lo stesso interruttore che
 * `CheckForPendingUpdates` usa per portare alla pagina di aggiornamento del database. Fino alla
 * beta.64 girava a **ogni richiesta**, il che è la ragione per cui il difetto qui sopra era
 * raggiungibile da chiunque senza autenticarsi.
 *
 * ⚠️ **Detto onestamente: su un'installazione nata dal wizard quell'interruttore resta acceso.** Lo
 * script di build lo mette a `true` nel pacchetto e **niente nel codice lo rimette a `false`**
 * (verificato il 22/08/2026: nessuna scrittura su `run_installer` in tutto `app/`). Là dentro
 * questo provider gira quindi a ogni richiesta come prima — la differenza è che ora non c'è più
 * niente che un client possa influenzare, quindi il costo è una lettura di file che esce subito
 * appena `TRUSTED_PROXIES` compare nel `.env`. La condizione resta perché protegge le installazioni
 * con l'aggiornamento automatico spento, che sono quelle in cui questo codice non ha niente da fare.
 *
 * TODO: togliere questo provider (e la sua riga in `bootstrap/providers.php`) nella v1.11, quando
 * si potrà dare per scontato che nessuno aggiorni più da una v1.8.
 */
class UpgradePatchServiceProvider extends ServiceProvider
{
    /** Il valore che si scrive: vedi il blocco qui sopra per il perché non è più `*`. */
    public const VALORE = 'PRIVATE_SUBNETS';

    /** L'intestazione del blocco scritto dalla v1.9, quella che va migrata. */
    private const BLOCCO_VECCHIO = "# --- AUTO-PATCH v1.9 (Proxy Fix) ---\nTRUSTED_PROXIES=*";

    /** L'intestazione del blocco nuovo, che serve anche a riconoscerlo la prossima volta. */
    private const INTESTAZIONE_NUOVA = '# --- KondoManager: proxy fidati (scritto automaticamente) ---';

    public function register(): void
    {
        // Nessun servizio da registrare nel container.
    }

    public function boot(): void
    {
        if (! $this->deveGirare()) {
            return;
        }

        try {
            $this->sistemaIlFileEnv(base_path('.env'));
        } catch (\Throwable $e) {
            // Fallimento silenzioso, come prima: se il `.env` è in sola lettura non si deve
            // impedire l'accesso al gestionale. L'amministratore avrà al più indirizzi sbagliati
            // negli URL, e quello si vede e si sistema; una schermata bianca no.
        }
    }

    /**
     * Questo provider deve fare qualcosa, in questa richiesta?
     *
     * ⚠️ **Metodo a parte, e per poterlo provare senza rischi.** La condizione è ciò che tiene
     * questo codice lontano dalle richieste normali, cioè è la metà della correzione di sicurezza
     * della beta.65 — ma provarla chiamando `boot()` vorrebbe dire che, **se la condizione fosse
     * rotta**, il test scriverebbe sul `.env` vero del progetto. Una prova che può fare danno se il
     * codice è sbagliato non è una prova: è un secondo modo di sbagliare.
     *
     * - **Solo durante installazione e aggiornamento** (`installer.run_installer`), che è lo stesso
     *   interruttore con cui `CheckForPendingUpdates` porta alla pagina di aggiornamento del
     *   database. Fino alla beta.64 girava a ogni richiesta, ed è per questo che il difetto era
     *   raggiungibile da chiunque senza autenticarsi.
     * - **Mai in sviluppo**: là il `.env` è di chi sviluppa e non va riscritto da solo.
     */
    public function deveGirare(): bool
    {
        return config('installer.run_installer', false) === true
            && config('app.env') !== 'local';
    }

    /**
     * Applica le regole a un file `.env` e dice cosa ha fatto.
     *
     * ⚠️ **Prende il percorso come argomento, e non è un dettaglio di stile.** Fino alla beta.64 il
     * percorso era cablato dentro il metodo, quindi questa logica **non era provabile**: un test
     * avrebbe dovuto scrivere sul `.env` vero del progetto. È il motivo per cui un difetto di
     * sicurezza è vissuto in questo file per due versioni senza che niente lo segnalasse.
     *
     * @return 'assente'|'aggiunto'|'migrato'|'gia-configurato' cosa è stato fatto
     */
    public function sistemaIlFileEnv(string $percorso): string
    {
        if (! is_file($percorso)) {
            return 'assente';
        }

        $contenuto = (string) file_get_contents($percorso);

        // 1. Il blocco che avevamo scritto noi, ancora intatto: si migra a PRIVATE_SUBNETS.
        if (str_contains($contenuto, self::BLOCCO_VECCHIO)) {
            file_put_contents(
                $percorso,
                str_replace(self::BLOCCO_VECCHIO, $this->bloccoNuovo(migrazione: true), $contenuto)
            );

            return 'migrato';
        }

        // 2. Qualunque altra menzione di TRUSTED_PROXIES è di chi amministra: non si tocca.
        //    Ci rientra anche il commento `# TRUSTED_PROXIES=null` di `.env.example`, ed è voluto:
        //    quell'installazione ha già una riga da scommentare, e scriverne una seconda più sotto
        //    darebbe due righe che dicono cose diverse.
        if (str_contains($contenuto, 'TRUSTED_PROXIES')) {
            return 'gia-configurato';
        }

        // 3. Non c'è niente: si aggiunge in coda, senza toccare una riga di quelle esistenti.
        file_put_contents($percorso, "\n\n".$this->bloccoNuovo(migrazione: false)."\n", FILE_APPEND);

        return 'aggiunto';
    }

    /**
     * Il blocco da scrivere, con dentro la spiegazione.
     *
     * Il testo cambia a seconda che si stia **sostituendo** un `*` scritto da noi o scrivendo per la
     * prima volta: nel primo caso l'amministratore si trova un valore diverso da quello di ieri e ha
     * diritto di sapere perché, e come rimettere il precedente se il suo hosting lo richiede.
     */
    private function bloccoNuovo(bool $migrazione): string
    {
        $intestazione = self::INTESTAZIONE_NUOVA."\n";

        if ($migrazione) {
            return $intestazione
                ."# Qui c'era TRUSTED_PROXIES=*, scritto automaticamente da una versione precedente.\n"
                ."# Sostituito perché '*' fa credere a X-Forwarded-For da chiunque: dove l'origine è\n"
                ."# raggiungibile fuori dal proxy, permette di falsificare il proprio indirizzo IP e\n"
                ."# di aggirare il blocco dei tentativi su accesso e codice a due fattori.\n"
                ."# PRIVATE_SUBNETS si fida solo di proxy su reti private o loopback: sugli hosting\n"
                ."# condivisi e dietro nginx/apache funziona identico, da internet non è falsificabile.\n"
                ."# Se il tuo hosting mette il proxy su un IP pubblico e ti servono gli indirizzi\n"
                ."# reali, scrivi qui la lista esplicita degli IP del proxy (non '*').\n"
                .'TRUSTED_PROXIES='.self::VALORE;
        }

        return $intestazione
            ."# Serve dietro un reverse proxy (hosting condiviso, nginx/apache davanti a php-fpm)\n"
            ."# perché Laravel riconosca https e l'indirizzo IP reale di chi visita.\n"
            ."# PRIVATE_SUBNETS si fida solo di proxy su reti private o loopback: da internet non è\n"
            ."# falsificabile. Se il tuo proxy ha un IP pubblico, scrivi qui la sua lista esplicita.\n"
            .'TRUSTED_PROXIES='.self::VALORE;
    }
}
