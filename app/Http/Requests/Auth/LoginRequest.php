<?php

namespace App\Http\Requests\Auth;

use App\Support\IndirizzoDeiLimiti;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Tentativi falliti per email e indirizzo, in un minuto. */
    private const TENTATIVI_PER_EMAIL = 5;

    /**
     * Tentativi falliti per indirizzo, su tutte le email insieme, in un minuto (Coda 262).
     *
     * Il limite per email e indirizzo conta ogni email per conto suo, e da solo non pone un tetto ai
     * tentativi di un indirizzo su email diverse. Il tetto per indirizzo è alto di proposito: dietro un
     * proxy senza `TRUSTED_PROXIES` tutti gli utenti arrivano con lo stesso indirizzo (quello del
     * proxy), e un tetto basso chiuderebbe fuori l'intero condominio per gli errori di pochi.
     * Venti errori in un minuto da un indirizzo solo non li fa nessuno che stia digitando.
     */
    private const TENTATIVI_PER_INDIRIZZO = 20;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            $this->contaTentativoFallito();

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // La sospensione si verifica **dopo** le credenziali, non prima: rispondere «sospeso» a
        // chi la password non ce l'ha direbbe a un estraneo quali account esistono e in che stato
        // sono. Chi arriva qui ha già dimostrato di essere il titolare.
        if (Auth::user()->suspended()) {
            Auth::guard('web')->logout();

            $this->session()->invalidate();
            $this->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => trans('auth.suspended'),
            ]);
        }

        // Solo il contatore dell'email: quello dell'indirizzo non si azzera con un accesso riuscito,
        // altrimenti chi ha un account suo ripartirebbe da zero entrando ogni tanto con la sua password.
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Un tentativo fallito conta sui due limiti: email e indirizzo, e indirizzo da solo.
     */
    public function contaTentativoFallito(): void
    {
        RateLimiter::hit($this->throttleKey());
        RateLimiter::hit($this->throttleKeyIndirizzo());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        foreach ([
            $this->throttleKey() => self::TENTATIVI_PER_EMAIL,
            $this->throttleKeyIndirizzo() => self::TENTATIVI_PER_INDIRIZZO,
        ] as $chiave => $massimo) {
            if (! RateLimiter::tooManyAttempts($chiave, $massimo)) {
                continue;
            }

            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($chiave);

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     *
     * L'indirizzo è quello dei limiti: un IPv6 conta per la sua rete /64 (`IndirizzoDeiLimiti`).
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.IndirizzoDeiLimiti::di($this->ip()));
    }

    /**
     * La chiave del tetto per indirizzo, uguale per tutte le email; un IPv6 conta per la sua rete /64.
     */
    public function throttleKeyIndirizzo(): string
    {
        return 'login-indirizzo|'.IndirizzoDeiLimiti::di($this->ip());
    }
}
