<?php

namespace App\Http\Controllers\Permissions;

use App\Enums\Role as RoleEnum;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;

/**
 * Revoca un permesso diretto a un utente.
 *
 * ## Perché ci sono tre guardie e non una
 *
 * Fino alla beta.55 questa rotta non ne aveva **nessuna**: bastava essere autenticati e verificati,
 * quindi un condòmino qualunque poteva togliere permessi a chiunque. Le tre che ci sono adesso
 * rispondono a tre domande diverse, e nessuna copre le altre:
 *
 * 1. **puoi mettere mano agli utenti?** — il permesso `Modifica utenti`;
 * 2. **puoi metterla a *questo* utente?** — chi non può concedere un ruolo privilegiato non deve
 *    poter spogliare chi ce l'ha, altrimenti la regola sui ruoli si aggira di lato;
 * 3. **puoi togliere *questo* permesso?** — solo se lo possiedi. Senza, si crea l'asimmetria
 *    peggiore: puoi toglierlo e non puoi rimetterlo, cioè un danno che nemmeno chi lo ha fatto sa
 *    riparare.
 */
class RevokePermissionFromUserController extends Controller
{
    public function __invoke(User $user, Permission $permission): RedirectResponse
    {
        Gate::authorize('update', User::class);

        $attore = Auth::user();
        $èAmministratore = $attore?->hasRole(RoleEnum::AMMINISTRATORE->value) ?? false;

        if ($user->hasAnyRole(RoleEnum::privilegiati()) && ! $èAmministratore) {
            abort(403, __('policies.edit_users'));
        }

        if (! $attore?->hasPermissionTo($permission->name)) {
            abort(403, __('validation.custom.permissions.not_allowed'));
        }

        $user->revokePermissionTo($permission);

        return back();
    }
}
