<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\CreateImpostazioniGeneraliRequest;
use App\Models\Condominio;
use App\Settings\GeneralSettings;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
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

        $roles = Role::select('id', 'name')->get();
        
        return Inertia::render('impostazioni/impostazioniGenerali', [
            'can_register'             => (bool) $settings->user_frontend_registration,
            'language'                 => (string) $settings->language,
            'app_name'                 => (string) $settings->app_name,
            'open_condominio_on_login' => $user->userPreferences->open_condominio_on_login,
            'default_condominio_id'    => $user->userPreferences->default_condominio_id,
            'condomini'                => Condominio::select('id','nome')->get(),
            'default_user_role'        => (string) $settings->default_user_role,
            'force_comment_moderation' => (bool) $settings->force_comment_moderation,
            'default_per_page'         => (int) $settings->default_per_page,
            // Il 100 resta fuori: il portale condòmino non ha un selettore per le righe, quindi un
            // valore globale così alto gli darebbe cento schede da scorrere e nessun comando per
            // ridurle. Chi lavora sul gestionale può comunque sceglierlo tabella per tabella.
            'per_page_disponibili'     => array_values(array_filter(
                config('pagination.consentite'),
                fn (int $v) => $v <= 50,
            )),
            'roles'                    => $roles,
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
            $settings->app_name = $validated['app_name'];
            $settings->default_user_role = $validated['default_user_role'];
            $settings->force_comment_moderation = $validated['force_comment_moderation'];
            $settings->default_per_page = $validated['default_per_page'];
            $settings->save();

            $userPreferences = $user->userPreferences;

            $userPreferences->open_condominio_on_login = $validated['open_condominio_on_login'];
            $userPreferences->default_condominio_id = $validated['open_condominio_on_login']
                ? $validated['default_condominio_id']
                : null;

            app()->setLocale($settings->language);
            config(['app.name' => $settings->app_name]);

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
