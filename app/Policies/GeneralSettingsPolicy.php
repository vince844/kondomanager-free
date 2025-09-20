<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GeneralSettingsPolicy
{
    public function manage(User $user): Response
    {
        return $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.manage_general_settings'));
    }
}
