<?php

namespace App\Http\Controllers\Users;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HandleFlashMessages;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class UserVerifyController extends Controller
{
    use HandleFlashMessages;
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
        // può concedere un ruolo privilegiato non deve poter spogliare chi ce l'ha.
        if ($user->hasAnyRole(RoleEnum::privilegiati())
            && ! Auth::user()?->hasRole(RoleEnum::AMMINISTRATORE->value)) {
            abort(403, __('policies.edit_users'));
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
