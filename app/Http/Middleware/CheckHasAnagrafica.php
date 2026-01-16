<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Auth;
use App\Enums\Role;

class CheckHasAnagrafica
{
    /**
     * This middleware checks if the user has an anagrafica and if not redirects to the create anagrafica page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {

        $user = Auth::user();

        if (Auth::check() && !$user->anagrafica && !$user->hasRole(Role::AMMINISTRATORE->value)) {
            return redirect()->route('user.anagrafiche.create');
        }

        return $next($request);
    }
}
