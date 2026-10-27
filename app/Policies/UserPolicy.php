<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    use ValidaConcessioneRuoli;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view another user.
     *
     * Grants access if the user has the 'Visualizza utenti' permission.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function view(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.view_users'));
    }

    /**
     * Determine whether the user can create a new user.
     *
     * Grants access if the user has the 'Crea utenti' permission.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.create_users'));
    }

    /**
     * Determine whether the user can update another user.
     *
     * Grants access if the user has the 'Modifica utenti' permission.
     *
     * Con il bersaglio (la scheda, `UserController::edit` e `update`) vale anche la portata: la scheda di un
     * account fuori dalla portata di chi non è amministratore è solo dell'amministratore, pagina e salvataggio,
     * nome e anagrafica compresi (Coda 258, decisione 136.1). Anche la propria, per un collaboratore: i propri
     * dati si correggono dal profilo. Senza il bersaglio (`Gate::authorize('update', User::class)`, le chiamate
     * di «reinvia», della verifica e della revoca, che guardano la portata da sé) vale il solo permesso.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user whose card is opened or saved.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function update(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::EDIT_USERS->value)) {
            return Response::deny(__('policies.edit_users'));
        }

        if ($target && $this->bersaglioFuoriPortata($target, $user)) {
            // Sulla propria scheda il rifiuto dice dove si cambiano i propri dati, non solo chi gestisce l'account.
            return Response::deny(__($user->is($target) ? 'policies.users_own_from_profile' : 'policies.users_privileged_target'));
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete another user.
     *
     * Grants access if the user has the 'Elimina utenti' permission.
     *
     * Il secondo parametro è **opzionale** perché le chiamate storiche autorizzano sulla classe
     * (`Gate::authorize('delete', User::class)`) e non sull'istanza. Quando il bersaglio c'è,
     * valgono anche le due invarianti: non sé stessi, non l'ultimo amministratore attivo.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being deleted.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::DELETE_USERS->value)) {
            return Response::deny(__('policies.delete_users'));
        }

        return $target ? $this->guardieDiIntegrita($user, $target) : Response::allow();
    }

    /**
     * Determine whether the user can suspend another user.
     *
     * Il permesso è dedicato — «Sospendi utenti» — e non `EDIT_USERS`: modificare la scheda di un
     * condòmino è il mestiere del collaboratore, estromettere qualcuno dall'installazione no.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being suspended.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function suspend(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::SUSPEND_USERS->value)) {
            return Response::deny(__('policies.suspend_users'));
        }

        return $target ? $this->guardieDiIntegrita($user, $target) : Response::allow();
    }

    /**
     * Determine whether the user can lift a suspension.
     *
     * Riattivare non può chiudere fuori nessuno, quindi non porta le due invarianti di sospensione ed
     * eliminazione. Riapre però la porta a chi l'amministratore ha chiuso fuori: chi non è amministratore
     * riattiva solo un account nella sua portata, la stessa regola di chi può sospenderlo (Coda 258,
     * decisione 134.1).
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being reactivated.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function unsuspend(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::SUSPEND_USERS->value)) {
            return Response::deny(__('policies.suspend_users'));
        }

        if ($target && $this->bersaglioFuoriPortata($target, $user)) {
            return Response::deny(__('policies.users_privileged_target'));
        }

        return Response::allow();
    }

    /**
     * Le guardie comuni a sospensione ed eliminazione.
     *
     * Non sono permessi: un permesso risponde a «questa persona può fare questa cosa?», queste
     * rispondono a «l'installazione resta governabile dopo?» e «questo bersaglio è alla sua portata?».
     *
     * Sospendere ed eliminare sono i due modi più forti di chiudere fuori qualcuno, e i loro permessi
     * l'amministratore li può dare a un ruolo su misura: chi non è amministratore li usa solo su un
     * account nella sua portata (i soli ruoli `utente` e `fornitore`, nessun permesso diretto che lui non
     * ha). È la regola della scheda utenti, di «reinvia» e della verifica (Coda 258, decisione 134.1),
     * presa dallo stesso posto.
     */
    private function guardieDiIntegrita(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::deny(__('policies.users_self_action'));
        }

        if ($target->isUltimoAmministratoreAttivo()) {
            return Response::deny(__('policies.users_last_admin'));
        }

        if ($this->bersaglioFuoriPortata($target, $user)) {
            return Response::deny(__('policies.users_privileged_target'));
        }

        return Response::allow();
    }

}
