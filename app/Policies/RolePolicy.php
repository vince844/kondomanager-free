<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Le pagine dei ruoli sono dell'amministratore (Coda 258, decisione 134.1).
 *
 * Un ruolo su misura è un pacchetto di poteri: chi lo crea, lo modifica o lo elimina decide che cosa
 * possono fare tutti quelli che ce l'hanno. Il collaboratore gestisce le utenze dei condòmini e dei
 * fornitori, non i poteri dell'installazione: queste pagine non le apre, e non toglie permessi a un
 * ruolo né lo elimina, perché toglierebbe poteri a chi ne ha più di lui. Le quattro risposte valgono
 * anche per la revoca di un permesso da un ruolo
 * (`RevokePermissionFromRoleController`, che chiede `update`).
 */
class RolePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole(Role::AMMINISTRATORE->value)
        ? Response::allow()
        : Response::deny(__('policies.view_roles'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasRole(Role::AMMINISTRATORE->value)
        ? Response::allow()
        : Response::deny(__('policies.create_roles'));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user): Response
    {
        return $user->hasRole(Role::AMMINISTRATORE->value)
        ? Response::allow()
        : Response::deny(__('policies.edit_roles'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasRole(Role::AMMINISTRATORE->value)
        ? Response::allow()
        : Response::deny(__('policies.delete_roles'));
    }
}
