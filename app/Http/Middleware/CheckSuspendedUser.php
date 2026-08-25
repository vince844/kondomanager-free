<?php

namespace App\Http\Middleware;

use Closure;
use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;

class CheckSuspendedUser
{
    /**
     * This middleware checks if the user is suspended and if it is logs it out and redirect to login.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (Auth::check() && $user->suspended()) {

            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Rimando al login, non una view 403: su una richiesta Inertia una pagina HTML di
            // errore diventa il modale a tutto schermo, che non porta da nessuna parte. Chi è
            // stato sospeso deve trovarsi davanti la schermata di accesso con il motivo scritto.
            return redirect()->route('login')
                ->withErrors(['email' => __('errors.403.account_suspended')]);
        }
     
        return $next($request);
    }
}
