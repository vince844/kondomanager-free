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
 * non è uno dei due. La conferma vale quindici minuti e si dà alla pagina `password.confirm` di sempre
 * (`ConfirmablePasswordController`, che scrive `auth.password_confirmed_at`); il login non la scrive, quindi
 * non vale come conferma.
 *
 * Perché non il `password.confirm` di Laravel (`RequirePassword::using('password.confirm', 900)`):
 * dopo la conferma riporta all'indirizzo della richiesta, e per due delle nostre richieste è il posto
 * sbagliato. Lo scarico di un backup è un collegamento al file: la conferma è un modulo Inertia, e il
 * file come risposta finirebbe dentro la finestra della pagina invece di scaricarsi. Le chiamate
 * della pagina della 2FA e la prova della posta ricevono un 423 senza che la destinazione venga
 * ricordata, e la conferma riporterebbe alla dashboard. Qui la rotta dice dove tornare
 * (`password.recente:<nome della rotta>`); senza, una pagina (`GET`) torna a sé stessa.
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
     * La risposta che chiede la password: la pagina della conferma, o un 423 a chi chiede JSON.
     *
     * @param  string|null  $ritorno  La rotta dove tornare dopo la conferma; senza, una pagina torna a sé.
     * @param  array<string, mixed>  $parametri  I parametri della rotta di ritorno (la scheda da riaprire).
     */
    public static function richiedi(Request $request, ?string $ritorno = null, array $parametri = []): Response
    {
        if ($ritorno !== null) {
            redirect()->setIntendedUrl(route($ritorno, $parametri));
        } elseif ($request->isMethod('GET') && ! $request->expectsJson()) {
            redirect()->setIntendedUrl($request->fullUrl());
        } else {
            $request->session()->forget('url.intended');
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.header.confirm_password.description')], 423);
        }

        return redirect()->route('password.confirm');
    }
}
