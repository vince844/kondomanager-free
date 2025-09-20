<?php

namespace App\Http\Middleware;

use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserRegistration
{
    protected GeneralSettings $settings;

    public function __construct(GeneralSettings $settings)
    {
        $this->settings = $settings;
    }

    public function handle(Request $request, Closure $next): Response
    {
    
        if (! $this->settings->user_frontend_registration) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}

