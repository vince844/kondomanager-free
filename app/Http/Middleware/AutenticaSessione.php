<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * `AuthenticateSession` del framework, più la regola per gli account senza password.
 *
 * Il framework chiude una sessione quando la password dell'account non è più quella con cui la
 * sessione è nata (Coda 261), ma salta chi una password non ce l'ha. Un account senza password è in
 * attesa del primo accesso: appena creato, o rimesso così da «reinvia», che è anche il modo con cui
 * l'amministratore chiude fuori chi stava usando l'account. Una sessione di un account così è nata
 * da una password che non c'è più — ogni strada d'ingresso parte da una password giusta: il modulo di
 * accesso, la sfida 2FA che lo segue, la registrazione — quindi si chiude alla prima richiesta, come
 * quella di una password cambiata: login per le pagine, 401 per le richieste JSON. Vale anche per le
 * sessioni nate prima di questa versione, che l'impronta della password non l'hanno mai avuta, e per
 * la sessione aperta da una sfida 2FA cominciata prima di «reinvia» (Coda 202, punto 1).
 *
 * Il primo accesso non cambia: le sue rotte sono per ospiti, e una sessione chiusa qui lascia il
 * browser da ospite. Per chi ha una password vale la regola del framework, invariata. Registrato con
 * l'alias `auth.session` in `bootstrap/app.php`, che il gruppo `web` usa.
 */
class AutenticaSessione extends AuthenticateSession
{
    public function handle($request, Closure $next)
    {
        if ($request->hasSession() && $request->user() && ! $request->user()->getAuthPassword()) {
            $this->logout($request);
        }

        return parent::handle($request, $next);
    }
}
