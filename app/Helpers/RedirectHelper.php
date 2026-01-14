<?php

namespace App\Helpers;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\RedirectResponse;

/**
 * Helper class for managing redirects and navigation flows
 * 
 * Provides centralized redirect logic for user authentication flows,
 * URL remembering, and intelligent fallback redirects.
 * 
 * @package App\Helpers
 * @static
 */
class RedirectHelper
{
    /**
     * Determine the appropriate home route based on user role and profile status
     *
     * This method handles the post-login/registration redirect logic:
     * - Administrators and collaborators are redirected to admin dashboard
     * - Users without an associated profile are redirected to profile creation
     * - Regular users with profiles are redirected to user dashboard
     *
     * @return string The URL for the appropriate route based on user status
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     *
     * @example
     * // Usage in LoginController or RegisterController
     * public function redirectTo(): string
     * {
     *     return RedirectHelper::userHomeRoute();
     * }
     *
     * @see \App\Http\Controllers\Auth\LoginController
     * @see \App\Http\Controllers\Auth\RegisterController
     */
    public static function userHomeRoute(): string
    {
        $user = Auth::user();
        
        $preferences = null;

        try {
            // Tentiamo di accedere alla relazione.
            // Se la tabella 'user_preferences' non esiste ancora (siamo pre-migrazione),
            // Laravel lancerà una QueryException.
            $preferences = $user->userPreferences;
        } catch (\Illuminate\Database\QueryException $e) {
            // Se siamo qui, significa che c'è un errore DB (es. tabella mancante).
            // Ignoriamo l'errore e lasciamo $prefs a null.
            // Questo permette al login di funzionare anche durante l'aggiornamento.
            $preferences = null;
        }

        // Il resto del codice rimane identico, gestendo il caso in cui $prefs è null
        if (
            $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])
            && $preferences // Qui controlliamo se $prefs esiste, quindi non darà errore
            && $preferences->open_condominio_on_login
            && $preferences->default_condominio_id
        ) {
            return route('admin.gestionale.index', [
                'condominio' => $preferences->default_condominio_id,
            ]);
        }

        if (
            $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]) 
            || $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
        ) {
            return route('admin.dashboard');
        }

        if (!$user->anagrafica) {
            return route('user.anagrafiche.create');
        }

        return route('user.dashboard');

    }

    /**
     * Store the current URL as the intended URL for later redirect
     *
     * Useful for remembering the page a user was on before being redirected
     * to authentication or other intermediate pages. Typically used in edit
     * methods to return users to the same page after form submission.
     *
     * @return void
     *
     * @example
     * // In a controller edit method
     * public function edit($id)
     * {
     *     RedirectHelper::rememberUrl();
     *     return view('edit.form');
     * }
     *
     * @see RedirectHelper::backOr()
     * @uses \Illuminate\Routing\Redirector::setIntendedUrl()
     */
    public static function rememberUrl(): void
    {
        redirect()->setIntendedUrl(url()->previous());
    }

    /**
     * Redirect to the intended URL or fallback to a default route
     *
     * This method works in conjunction with rememberUrl() to provide
     * intelligent redirect behavior. It first attempts to redirect to
     * the URL stored by rememberUrl(), then falls back to the provided
     * default route if no intended URL is set.
     *
     * @param string $fallback The fallback route to use if no intended URL is set
     * @param array $with Flash data to include with the redirect
     * @return \Illuminate\Http\RedirectResponse
     *
     * @example
     * // In a controller update method
     * public function update(Request $request, $id)
     * {
     *     // Update logic...
     *     
     *     return RedirectHelper::backOr(
     *         route('items.index'),
     *         ['success' => 'Item updated successfully']
     *     );
     * }
     *
     * @see RedirectHelper::rememberUrl()
     * @uses \Illuminate\Routing\Redirector::intended()
     */
    public static function backOr(string $fallback, array $with = []): RedirectResponse
    {
        return redirect()->intended($fallback)->with($with);
    }
}
