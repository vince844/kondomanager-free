<?php

namespace App\Helpers;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class RouteHelper
{
    /**
     * Get the route prefix for a given user based on their role or permissions.
     *
     * If the user has the role of 'amministratore' or 'collaboratore', or has the
     * 'Accesso pannello amministratore' permission, the method returns 'admin'.
     * Otherwise, it defaults to 'user'.
     *
     * @param mixed $notifiable Typically a User instance.
     * @return string Either 'admin' or 'user' as the route prefix.
     */
    public static function getRoutePrefixForUser(mixed $notifiable): string
    {
        if (
            $notifiable instanceof User &&
            (
                $notifiable->hasRole([
                    Role::AMMINISTRATORE->value,
                    Role::COLLABORATORE->value
                ]) ||
                $notifiable->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
            )
        ) {
            return 'admin';
        }

        return 'user';
    }
}
