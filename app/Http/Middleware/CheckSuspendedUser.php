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

            return response()->view('errors.403', [
                'exception' => new Exception(__('errors.403.account_suspended'),)
            ], 403);
        }
     
        return $next($request);
    }
}
