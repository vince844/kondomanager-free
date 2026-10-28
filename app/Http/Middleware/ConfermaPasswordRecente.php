<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * La password ridigitata da poco, sulle azioni che valgono l'account (Coda 261, decisione 127.7).
 *
 * Le azioni: le cinque della 2FA (anche la pagina, che mostra i codici di recupero), lo scarico di un
 * backup, la password dei backup, le impostazioni della posta (salvataggio e prova); e, dalla decisione
 * 134.1, quelle che danno poteri, chieste dai loro controller solo quando l'azione lo vuole: concedere un
 * ruolo diverso da `utente` e `fornitore` o un permesso aggiuntivo che non è sui propri contenuti, cambiare
 * l'email di un account che non ha soltanto quei due ruoli o che ha un permesso aggiuntivo così, creare o
 * modificare un ruolo su misura, cambiare il ruolo della registrazione pubblica o accenderla quando quel ruolo
 * non è uno dei due. La conferma vale quindici minuti e la scrive `ConfirmablePasswordController`
 * (`auth.password_confirmed_at`); il login non la scrive, quindi non vale come conferma.
 *
 * ## Come si chiede la password (Coda 404)
 *
 * - Le richieste che la pagina manda da sé, quelle Inertia (`X-Inertia`) e quelle JSON (`fetch`, `axios`),
 *   ricevono un 423 con `conferma_password: true`, prima di qualunque scrittura. La pagina apre una finestra
 *   sulla stessa pagina, manda la password in JSON a `POST /confirm-password` e rifà la stessa richiesta con
 *   gli stessi dati: il modulo non si perde. Per il 423 non resta un indirizzo di ritorno in sessione, e
 *   quello eventuale si toglie: la conferma in JSON non rimanda da nessuna parte.
 * - Una pagina aperta dal browser, un collegamento e un modulo senza JavaScript ricevono il rimando alla
 *   pagina `password.confirm` con il ritorno (`password.recente:<rotta>`; senza, una pagina `GET` torna a
 *   sé stessa). È anche la rete di sicurezza se la finestra non c'è.
 * - Lo scarico di un backup è un collegamento, non una richiesta della pagina: prima di seguirlo la pagina
 *   chiede lo stato della conferma a `GET /confirm-password/stato`.
 *
 * Perché non il `password.confirm` di Laravel (`RequirePassword::using('password.confirm', 900)`): dopo la
 * conferma riporta all'indirizzo della richiesta, e per lo scarico di un backup è il posto sbagliato: la
 * conferma è un modulo Inertia, e il file come risposta finirebbe dentro la finestra della pagina invece di
 * scaricarsi. Qui la rotta dice dove tornare.
 */
class ConfermaPasswordRecente
{
    /** Quanto vale una conferma per queste azioni: quindici minuti (decisione 127.7). */
    public const SECONDI = 900;

    public function handle(Request $request, Closure $next, ?string $ritorno = null): Response
    {
        if (! self::scaduta($request)) {
            return $next($request);
        }

        return self::richiedi($request, $ritorno);
    }

    /**
     * La password è stata ridigitata più di quindici minuti fa, o mai in questa sessione?
     */
    public static function scaduta(Request $request): bool
    {
        $confermata = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return Date::now()->unix() - $confermata > self::SECONDI;
    }

    /**
     * La risposta che chiede la password: un 423 alla pagina che l'ha chiesta da sé (Inertia o JSON), il
     * rimando alla pagina della conferma a tutto il resto.
     *
     * @param  string|null  $ritorno  La rotta dove tornare dopo la conferma come pagina; senza, una pagina torna a sé.
     * @param  array<string, mixed>  $parametri  I parametri della rotta di ritorno (la scheda da riaprire).
     */
    public static function richiedi(Request $request, ?string $ritorno = null, array $parametri = []): Response
    {
        if ($request->expectsJson() || $request->header('X-Inertia')) {
            // La finestra della pagina rifà la richiesta dopo la conferma: nessun ritorno da ricordare, e quello
            // rimasto da un'altra richiesta non deve decidere dove andrà la prossima conferma come pagina.
            $request->session()->forget('url.intended');

            return response()->json([
                'message' => __('auth.header.confirm_password.description'),
                'conferma_password' => true,
            ], 423);
        }

        if ($ritorno !== null) {
            redirect()->setIntendedUrl(route($ritorno, $parametri));
        } elseif ($request->isMethod('GET')) {
            redirect()->setIntendedUrl($request->fullUrl());
        } else {
            $request->session()->forget('url.intended');
        }

        return redirect()->route('password.confirm');
    }
}
