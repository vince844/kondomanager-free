<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])  
        ? Response::allow() 
        : Response::deny(__('policies.view_roles'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])  
        ? Response::allow() 
        : Response::deny(__('policies.create_roles')); 
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])
        ? Response::allow() 
        : Response::deny(__('policies.edit_roles'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])
        ? Response::allow() 
        : Response::deny(__('policies.delete_roles'));
    }
}
