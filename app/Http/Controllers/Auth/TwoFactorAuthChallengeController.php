<?php

namespace App\Http\Controllers\Auth;

use App\Actions\TwoFactorAuth\CompleteTwoFactorAuthentication;
use App\Actions\TwoFactorAuth\ProcessRecoveryCode;
use App\Actions\TwoFactorAuth\VerifyTwoFactorCode;
use App\Helpers\RedirectHelper;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTwoFactorChallengeSession;
use App\Models\User;
use App\Support\IndirizzoDeiLimiti;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class TwoFactorAuthChallengeController extends Controller
{
    /** Codici sbagliati ammessi: per utente e indirizzo in un minuto, per utente in cinque minuti, per sessione. */
    private const TENTATIVI = 5;

    /**
     * Il limite per utente, senza l'indirizzo (Coda 259): vale da qualunque indirizzo arrivino i
     * codici, quindi non dipende da come sono configurati i proxy fidati. Il prezzo: quando scatta,
     * anche il titolare aspetta cinque minuti.
     */
    private const SECONDI_PER_UTENTE = 300;

    /**
     * Attempt to authenticate a new session using the two factor authentication code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $request->validate([
            'code' => 'nullable|string',
            'recovery_code' => 'nullable|string',
        ]);

        // If we made it here, user is available via the EnsureTwoFactorChallengeSession middleware
        $user = $request->two_factor_auth_user;

        // Terza porta d'ingresso, e la più facile da dimenticare: la sfida può essere aperta da
        // una sessione iniziata **prima** della sospensione, quindi la guardia del login non la
        // copre. Un codice giusto su un account sospeso resta un accesso negato.
        if ($user->suspended()) {
            $request->session()->forget(EnsureTwoFactorChallengeSession::CHIAVI);

            return to_route('login')->withErrors(['email' => __('auth.suspended')]);
        }

        // Ensure the 2FA challenge is not rate limited
        $this->ensureIsNotRateLimited($user);

        // Handle one-time password (OTP) code
        if ($request->filled('code')) {
            return $this->authenticateUsingCode($request, $user);
        }

        // Handle recovery code
        if ($request->filled('recovery_code')) {
            return $this->authenticateUsingRecoveryCode($request, $user);
        }

        return back()->withErrors(['code' => __('auth.missing_2fa_code')]);
    }

    /**
     * Authenticate using a one-time password (OTP).
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    protected function authenticateUsingCode(Request $request, User $user)
    {
        // Finestra di ±30 secondi e niente riuso: `VerifyTwoFactorCode` (Coda 259).
        $valid = app(VerifyTwoFactorCode::class)($user, $request->code);
        
        if ($valid) {
            app(CompleteTwoFactorAuthentication::class)($user);
            $this->azzeraLimiti($user);
            return redirect()->intended(RedirectHelper::userHomeRoute());
        }
        
        return $this->tentativoFallito($request, $user, ['code' => __('auth.invalid_2fa_code')]);
    }

    /**
     * Authenticate using a recovery code.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\Response
     */
    protected function authenticateUsingRecoveryCode(Request $request, User $user)
    {
        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true);
        
        // Process the recovery code - this handles validation and removing the used code
        $updatedCodes = app(ProcessRecoveryCode::class)($recoveryCodes, $request->recovery_code);
        
        // If ProcessRecoveryCode returns false, the code was invalid
        if ($updatedCodes === false) {
            return $this->tentativoFallito($request, $user, ['recovery_code' => __('auth.invalid_2fa_recovery_code')]);
        }
        
        // Update the user's recovery codes, removing the used code
        $user->two_factor_recovery_codes = encrypt(json_encode($updatedCodes));
        $user->save();
        
        // Complete the authentication process
        app(CompleteTwoFactorAuthentication::class)($user);
        
        // Clear rate limiter after successful authentication
        $this->azzeraLimiti($user);
        
        // Redirect to the intended page
        return redirect()->intended(RedirectHelper::userHomeRoute());
    }
    
    /**
     * Ensure the 2FA challenge is not rate limited.
     *
     * @param  \App\Models\User  $user
     * @return void
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    protected function ensureIsNotRateLimited(User $user): void
    {
        foreach ([$this->throttleKey($user), $this->chiaveUtente($user)] as $chiave) {
            if (! RateLimiter::tooManyAttempts($chiave, self::TENTATIVI)) {
                continue;
            }

            $seconds = RateLimiter::availableIn($chiave);

            throw ValidationException::withMessages([
                'code' => __('auth.too_many_2fa_attempts', ['seconds' => $seconds]),
            ]);
        }
    }

    /**
     * Un codice sbagliato conta sui due limiti e sulla sessione: al quinto lo stato «password giusta»
     * si chiude e si ricomincia dalla password, che ha il suo limite (Coda 259).
     */
    private function tentativoFallito(Request $request, User $user, array $errore)
    {
        RateLimiter::hit($this->throttleKey($user));
        RateLimiter::hit($this->chiaveUtente($user), self::SECONDI_PER_UTENTE);

        $tentativi = (int) $request->session()->get('login.tentativi', 0) + 1;

        if ($tentativi >= self::TENTATIVI) {
            $request->session()->forget(EnsureTwoFactorChallengeSession::CHIAVI);

            // Nel riquadro dei rifiuti del login, come la scadenza (`EnsureTwoFactorChallengeSession`).
            return to_route('login')->with('avviso', __('auth.two_factor_restart'));
        }

        $request->session()->put('login.tentativi', $tentativi);

        return back()->withErrors($errore);
    }

    private function azzeraLimiti(User $user): void
    {
        RateLimiter::clear($this->throttleKey($user));
        RateLimiter::clear($this->chiaveUtente($user));
    }

    /**
     * La chiave del limite per utente, uguale da ogni indirizzo.
     */
    private function chiaveUtente(User $user): string
    {
        return $user->id.'|2fa';
    }

    /**
     * Get the rate limiting throttle key for the given user.
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    protected function throttleKey(User $user): string
    {
        // Un IPv6 conta per la sua rete /64, come in ogni limite per indirizzo (`IndirizzoDeiLimiti`).
        return Str::transliterate($user->id . '|2fa|' . IndirizzoDeiLimiti::di(request()->ip()));
    }
}
