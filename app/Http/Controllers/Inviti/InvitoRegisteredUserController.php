<?php

namespace App\Http\Controllers\Inviti;

use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Models\Invito;
use App\Services\UserRegistrationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class InvitoRegisteredUserController extends Controller
{
    public function __construct(
        protected UserRegistrationService $registrationService
    ) {}

    /**
     * Show the registration form based on the invite.
     */
    public function show(Request $request): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403);
        }

        $invito = Invito::where('email', $request->query('email'))->first();

        if (! $invito || $invito->isExpired() || $invito->isAccepted()) {
            abort(403);
        }

        return inertia('auth/RegisterFromInvite', [
            'email' => $invito->email,
        ]);
    }

    /**
     * Handle an invite-based registration request.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $invito = Invito::where('email', $validated['email'])->first();

        if (! $invito || $invito->isExpired() || $invito->isAccepted()) {
            abort(403, 'Invito non valido o scaduto.');
        }

        $user = $this->registrationService->register($validated);

        $invito->accept();

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }

}
