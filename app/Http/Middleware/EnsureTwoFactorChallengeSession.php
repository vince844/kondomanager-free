<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureTwoFactorChallengeSession
{
    /**
     * Quanto vale lo stato «password giusta» lasciato dal login per la sfida 2FA (Coda 259).
     *
     * La password giusta apre la pagina del codice per dieci minuti, che bastano per prendere il
     * telefono; dopo si rimette la password.
     */
    public const DURATA_SECONDI = 600;

    /** Le chiavi di sessione dello stato della sfida, da togliere tutte insieme. */
    public const CHIAVI = ['login.id', 'login.remember', 'login.at', 'login.tentativi'];

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (! $request->session()->has('login.id')) {
            return redirect()->route('login');
        }

        // Uno stato senza l'ora in cui è nato (lasciato da una versione precedente) non si sa quanto
        // sia vecchio: vale come scaduto.
        $nato = $request->session()->get('login.at');

        if (! is_numeric($nato) || now()->getTimestamp() - (int) $nato > self::DURATA_SECONDI) {
            $request->session()->forget(self::CHIAVI);

            // Nel riquadro dei rifiuti del login (`avviso`): un errore sul campo andrebbe al modulo della
            // pagina da cui si parte, e il modulo nuovo del login non lo mostrerebbe.
            return redirect()->route('login')->with('avviso', __('auth.two_factor_expired'));
        }

        $userId = $request->session()->get('login.id');
        $user = User::find($userId);

        if (! $user) {
            return redirect()->route('login');
        }

        // Share the user with the request for controllers to use
        $request->merge(['two_factor_auth_user' => $user]);

        return $next($request);
    }
}
