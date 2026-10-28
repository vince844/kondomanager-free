<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandleFlashMessages;
use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class UserVerifyController extends Controller
{
    use HandleFlashMessages, ValidaConcessioneRuoli;

    /**
     * Handle the incoming request.
     */
    public function __invoke(User $user): RedirectResponse
    {
        // ⚠️ **Fuori dal `try`.** Il `catch (\Throwable)` qui sotto cattura anche
        // `AuthorizationException`: un divieto scritto dentro tornerebbe all'utente come «errore
        // durante la verifica», cioè un rifiuto travestito da guasto. Stessa forma già pagata
        // nelle beta.43, .48 e .49.
        Gate::authorize('update', User::class);

        // Togliere la verifica **chiude fuori**: il middleware `verified` sta su tutto il
        // programma, quindi un amministratore non verificato non arriva da nessuna parte. Chi non
        // può cambiare l'email e i ruoli di un utente non deve poter spogliarlo: la regola è quella
        // della scheda (`User::haNellaPortata()`, Coda 258, decisione 134.1), e il rifiuto dice perché.
        if ($this->bersaglioFuoriPortata($user)) {
            abort(403, __('policies.users_privileged_target'));
        }

        // Togliere la verifica chiude fuori come sospendere: non sul proprio account, non sull'ultimo amministratore
        // attivo (`UserPolicy::revocaVerifica`, Coda 399). Rimetterla non chiude fuori nessuno.
        if ($user->email_verified_at !== null) {
            Gate::authorize('revocaVerifica', $user);
        }

        try {

            // Toggle verifica
            $user->email_verified_at = $user->email_verified_at ? null : now();
            $user->save();

            $message = $user->email_verified_at
                ? $this->flashSuccess(__('users.success_verify_user'))
                : $this->flashWarning(__('users.success_revoke_verify_user'));

            return back()->with($message);

        } catch (\Throwable $e) {

            Log::error('Errore durante la verifica utente ID ' . $user->id . ': ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('users.error_verify_user'))
            );

        }
    }

}
