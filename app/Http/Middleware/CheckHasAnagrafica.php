<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckHasAnagrafica
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if the authenticated user has an anagrafiche record
        if (auth()->check() && !auth()->user()->anagrafica) {
            
            if(!auth()->user()->hasRole('amministratore')){
                // Redirect to the route where the user can create their anagrafiche
                return redirect()->route('user.anagrafiche.create');
            }
           
        }

        // Continue processing the request if the user has an anagrafiche
        return $next($request);
    }
}
