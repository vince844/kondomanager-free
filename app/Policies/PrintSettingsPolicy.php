<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Policy for managing print settings.
 *
 * Ensures that only users with the appropriate permissions can view or modify
 * the global print settings (e.g. footer text, signature).
 */
class PrintSettingsPolicy
{
    /**
     * Determine whether the user can manage print settings.
     *
     * @param \App\Models\User $user The authenticated user.
     * @return \Illuminate\Auth\Access\Response
     */
    public function manage(User $user): Response
    {
        return $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.manage_general_settings'));
    }
}
