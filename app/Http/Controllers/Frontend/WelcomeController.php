<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(GeneralSettings $settings): Response
    {
        return Inertia::render('Welcome',[
            'can_register' => $settings->user_frontend_registration,
        ]);
    }
}
