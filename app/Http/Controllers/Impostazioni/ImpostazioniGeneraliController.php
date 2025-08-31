<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImpostazioniGeneraliController extends Controller
{
    use HandleFlashMessages;

    /**
     * Show general settings page
     */
    public function __invoke(GeneralSettings $settings)
    {
        return Inertia::render('impostazioni/impostazioniGenerali', [
            // Ensure boolean is sent to frontend
            'can_register' => (bool) $settings->user_frontend_registration,
        ]);
    }

    /**
     * Store updated settings
     */
    public function store(Request $request, GeneralSettings $settings)
    {
        $validated = $request->validate([
            'user_frontend_registration' => 'required|boolean',
        ]);

        $settings->user_frontend_registration = $validated['user_frontend_registration'];
        $settings->save();

        return back()->with(
            $this->flashSuccess(__('Impostazioni salvate correttamente'))
        );

    }
}
