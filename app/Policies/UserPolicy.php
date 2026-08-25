<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
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
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function update(User $user): Response
    {
        return $user->hasPermissionTo(Permission::EDIT_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.edit_users'));
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
     * Riattivare non può chiudere fuori nessuno, quindi non porta le due invarianti: serve solo
     * lo stesso permesso che serve a sospendere.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function unsuspend(User $user): Response
    {
        return $user->hasPermissionTo(Permission::SUSPEND_USERS->value)
        ? Response::allow()
        : Response::deny(__('policies.suspend_users'));
    }

    /**
     * Le due invarianti comuni a sospensione ed eliminazione.
     *
     * Non sono permessi: un permesso risponde a «questa persona può fare questa cosa?», queste
     * rispondono a «l'installazione resta governabile dopo?».
     */
    private function guardieDiIntegrita(User $user, User $target): Response
    {
        if ($user->is($target)) {
            return Response::deny(__('policies.users_self_action'));
        }

        if ($target->isUltimoAmministratoreAttivo()) {
            return Response::deny(__('policies.users_last_admin'));
        }

        return Response::allow();
    }

}
