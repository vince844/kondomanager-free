<?php

namespace App\Http\Middleware;

use App\Helpers\RouteHelper;
use App\Traits\HandleFlashMessages;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckHasAnagrafica
{
    use HandleFlashMessages;

    /**
     * Le pagine del portale leggono i palazzi dall'anagrafica collegata all'account: senza, gli elenchi
     * e la dashboard andavano in errore 500 (decisione 134.4). Chi non ce l'ha non arriva alle pagine, e
     * una frase dice perché:
     * - chi ha il pannello (l'amministratore, il collaboratore, un ruolo su misura con l'accesso) va alla
     *   dashboard del pannello, la sua casa, che non chiede un'anagrafica;
     * - gli altri vanno alla pagina dove completano i loro dati, la stessa dove li manda l'accesso
     *   (`RedirectHelper::userHomeRoute`).
     * Chi ha il pannello **e** un'anagrafica passa come chiunque abbia un'anagrafica.
     *
     * «Ha il pannello» è la stessa domanda che sceglie il prefisso degli indirizzi e dei pulsanti delle
     * mail (`RouteHelper::getRoutePrefixForUser`): un pulsante che porta al portale lo riceve solo chi
     * questo middleware non rimanda al pannello.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null || $user->anagrafica) {
            return $next($request);
        }

        if (RouteHelper::getRoutePrefixForUser($user) === 'admin') {
            return redirect()->route('admin.dashboard')->with(
                $this->flashWarning(__('anagrafiche.panel_without_anagrafica'))
            );
        }

        return redirect()->route('user.anagrafiche.create')->with(
            $this->flashWarning(__('anagrafiche.account_without_anagrafica'))
        );
    }
}
