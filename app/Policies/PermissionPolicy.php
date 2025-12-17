<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PermissionPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])  
        ? Response::allow() 
        : Response::deny(__('policies.view_permissions'));
    }
}
