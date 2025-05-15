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
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user): Response
    {
        return $user->hasPermissionTo(Permission::DELETE_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.delete_users'));
    }

}
