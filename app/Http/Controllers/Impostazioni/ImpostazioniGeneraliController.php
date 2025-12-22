<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateImpostazioniGeneraliRequest;
use App\Models\Condominio;
use App\Settings\GeneralSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Response;

class ImpostazioniGeneraliController extends Controller
{
    use HandleFlashMessages;

    /**
     * Show general settings page
     */
    public function __invoke(GeneralSettings $settings): Response
    {
        Gate::authorize('manage', $settings);

        $user = Auth::user();
        
        return Inertia::render('impostazioni/impostazioniGenerali', [
            'can_register'             => (bool) $settings->user_frontend_registration,
            'language'                 => (string) $settings->language,
            'open_condominio_on_login' => $user->userPreferences->open_condominio_on_login,
            'default_condominio_id'    => $user->userPreferences->default_condominio_id,
            'condomini'                => Condominio::select('id','nome')->get(),
        ]);
    }

    /**
     * Store updated settings
     */
    public function store(CreateImpostazioniGeneraliRequest $request, GeneralSettings $settings): RedirectResponse
    {

        Gate::authorize('manage', $settings);

        try {

            $user = Auth::user();

            $validated = $request->validated();

            $settings->user_frontend_registration = $validated['user_frontend_registration'];
            $settings->language = $validated['language'];
            $settings->save();

            $userPreferences = $user->userPreferences;

            $userPreferences->open_condominio_on_login = $validated['open_condominio_on_login'];
            $userPreferences->default_condominio_id = $validated['open_condominio_on_login']
                ? $validated['default_condominio_id']
                : null;
            
            app()->setLocale($settings->language);

            $userPreferences->save();

         } catch (\Exception $e) {

            Log::error('Error saving general settings: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('impostazioni.error_save_general_settings'))
            );
        }

        return back()->with(
            $this->flashSuccess(__('impostazioni.success_save_general_settings'))
        );

    }
}
