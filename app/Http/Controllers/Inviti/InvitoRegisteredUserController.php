<?php

namespace App\Http\Controllers\Inviti;

use App\Http\Controllers\Controller;
use App\Models\Invito;
use App\Models\User;
use App\Services\UserRegistrationService;
use App\Support\ModuloFirmato;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Inertia\Response;

/**
 * Le due rotte sono firmate (`routes/web.php` e `routes/auth.php`): la firma la verifica il
 * middleware `signed` prima di arrivare qui. L'`id` della query è quindi quello del link dell'invito,
 * e l'invito si carica da lì; l'email dell'account è quella dell'invito, non quella del corpo.
 */
class InvitoRegisteredUserController extends Controller
{
    public function __construct(
        protected UserRegistrationService $registrationService
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $invito = Invito::findOrFail($request->query('id'));

        if ($rifiuto = $this->invitoNonUsabile($invito)) {
            return $rifiuto;
        }

        return inertia('auth/RegisterFromInvite', [
            'email'  => Str::lower($invito->email),
            // Il modulo si manda a questo stesso URL, firma compresa: il `POST` chiede la stessa prova.
            'azione' => ModuloFirmato::azione($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $invito = Invito::findOrFail($request->query('id'));

        if ($rifiuto = $this->invitoNonUsabile($invito)) {
            return $rifiuto;
        }

        // In minuscolo: un invito scritto con le maiuscole non passava la regola `lowercase`, e
        // l'invitato restava fermo davanti a un campo che non può modificare.
        $request->merge(['email' => Str::lower($invito->email)]);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $this->registrationService->register($validated);

        $invito->accept();

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('verification.notice');
    }

    /**
     * Un invito che non si può più usare rimanda al login con un messaggio che dice cosa fare,
     * invece di un 403 muto o di un errore sotto il campo email, che l'invitato non può modificare.
     * L'ordine conta: un invito già usato lo dice per primo, e un account che esiste già vale anche
     * a invito scaduto, perché chi ce l'ha può comunque accedere.
     */
    private function invitoNonUsabile(Invito $invito): ?RedirectResponse
    {
        $messaggio = match (true) {
            $invito->isAccepted() => 'notifications.invite_user.already_used',
            User::where('email', Str::lower($invito->email))->exists() => 'notifications.invite_user.account_exists',
            $invito->isExpired() => 'notifications.invite_user.expired',
            default => null,
        };

        return $messaggio ? redirect()->route('login')->with('avviso', __($messaggio)) : null;
    }
}
