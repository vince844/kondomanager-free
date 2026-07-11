<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Policy for managing backups.
 *
 * Ensures that only users with the appropriate permissions can create,
 * download or delete full application backups. Backups contain the entire
 * database and the .env file, so this is the most sensitive surface of the
 * application.
 */
class BackupSettingsPolicy
{
    /**
     * Determine whether the user can manage backups.
     *
     * @param  User  $user  The authenticated user.
     */
    public function manage(User $user): Response
    {
        return $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value)
        ? Response::allow()
        : Response::deny(__('policies.manage_general_settings'));
    }
}
