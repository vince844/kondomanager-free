<?php

namespace App\Http\Controllers\Permissions;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ValidaConcessioneRuoli;
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
 * 2. **puoi metterla a *questo* utente?** — chi non è amministratore toglie poteri solo a un account
 *    nella sua portata: i soli ruoli `utente` e `fornitore`, nessun permesso diretto che lui non ha. È la
 *    regola della scheda utenti, di «reinvia», della verifica, della sospensione e dell'eliminazione
 *    (`User::haNellaPortata()`, Coda 258, decisione 134.1), presa dallo stesso posto, così vale uguale da
 *    ogni porta. Il rifiuto dice perché: «non hai i permessi per modificare gli utenti» era falso
 *    per chi li ha;
 * 3. **puoi togliere *questo* permesso?** — solo se lo possiedi. Senza, si crea l'asimmetria
 *    peggiore: puoi toglierlo e non puoi rimetterlo, cioè un danno che nemmeno chi lo ha fatto sa
 *    riparare. Fanno eccezione i permessi sui propri contenuti (`User::permessiSulProprioContenuto()`):
 *    la portata non li conta, perché non danno poteri su nessun altro, e un account nella portata li
 *    perde anche da chi non li ha (Coda 258, decisione 134.1). Il rifiuto parla di revoca, non di
 *    concessione.
 */
class RevokePermissionFromUserController extends Controller
{
    use ValidaConcessioneRuoli;

    public function __invoke(User $user, Permission $permission): RedirectResponse
    {
        Gate::authorize('update', User::class);

        $attore = Auth::user();

        if ($this->bersaglioFuoriPortata($user)) {
            abort(403, __('policies.users_privileged_target'));
        }

        $sulProprioContenuto = in_array($permission->name, User::permessiSulProprioContenuto(), true);

        if (! $sulProprioContenuto && ! $attore?->hasPermissionTo($permission->name)) {
            abort(403, __('policies.users_revoke_not_owned'));
        }

        $user->revokePermissionTo($permission);

        return back();
    }
}
