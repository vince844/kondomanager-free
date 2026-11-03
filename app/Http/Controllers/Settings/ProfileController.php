<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $utente = $request->user();
        $vecchia = $utente->email;

        $utente->fill($request->safe()->only(['name', 'email']));

        $emailCambiata = $utente->isDirty('email');

        if ($emailCambiata) {
            $utente->email_verified_at = null;
        }

        $utente->save();

        // Coda 267: l'avviso va al vecchio indirizzo, l'unico che il titolare legge ancora se il
        // cambio non l'ha fatto lui.
        if ($emailCambiata) {
            app(AvvisiDiSicurezza::class)->emailCambiata($utente, $vecchia);
        }

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        // L'installazione non resta senza amministratori: la stessa regola della sospensione,
        // dell'eliminazione e del cambio di ruolo dall'elenco utenti. Da quello stato non si esce
        // dall'interfaccia. L'errore va sotto la password, l'unico campo della finestra; la regola
        // viene prima della password, che così non si prova per un'eliminazione che non può avvenire.
        // Chi non ha il ruolo non è l'ultimo amministratore: la domanda al database la fa solo un
        // amministratore.
        if ($user->hasRole(RoleEnum::AMMINISTRATORE->value) && $user->isUltimoAmministratoreAttivo()) {
            throw ValidationException::withMessages([
                'password' => __('policies.users_last_admin_self'),
            ]);
        }

        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
