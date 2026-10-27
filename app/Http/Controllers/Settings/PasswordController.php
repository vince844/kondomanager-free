<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Notifiche\AvvisiDiSicurezza;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    /**
     * Show the user's password settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Password', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => [
                'required', 
                Password::defaults(), 
                'confirmed', 
                'different:current_password' // Validazione nativa
            ],
        ], [
            // Messaggio personalizzato per migliorare la UX
            'password.different' => 'La nuova password deve essere diversa dalla password attuale.',
        ]);

        // Coda 261: il token «ricordami» si rinnova, come nel reset, e le altre sessioni si chiudono.
        // `logoutOtherDevices` riscrive la password (la stessa) e il cookie «ricordami» di questo
        // dispositivo, se c'è; le altre sessioni le chiude `AuthenticateSession` alla loro richiesta
        // dopo, perché portano la password di prima. Questa sessione resta dentro.
        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        Auth::logoutOtherDevices($validated['password']);

        // Coda 267: il titolare lo sa anche se il cambio l'ha fatto qualcun altro dalla sua sessione.
        app(AvvisiDiSicurezza::class)->avvisa($request->user(), AvvisiDiSicurezza::PASSWORD_CAMBIATA);

        return back();
    }
}
