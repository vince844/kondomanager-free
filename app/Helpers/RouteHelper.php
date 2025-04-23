<?php

namespace App\Helpers;

use App\Models\User;

class RouteHelper
{ 
    /**
     * Determines the route prefix based on the notifiable user's role and permission.
     *
     * This method checks if the given notifiable is an instance of the User model
     * and whether they have the role of 'amministratore' or 'collaboratore' or permission Accesso pannello amministratore.
     * If so, it returns 'admin' as the route prefix. Otherwise, it defaults to 'user'.
     *
     * @param mixed $notifiable The object to evaluate, typically a User.
     * @return string The appropriate route prefix ('admin' or 'user').
     */
    public static function getRoutePrefixForUser(mixed $notifiable): string
    {
        if ($notifiable instanceof User && (
            $notifiable->hasRole(['amministratore', 'collaboratore']) ||
            $notifiable->hasPermissionTo('Accesso pannello amministratore') // Check if the user has the permission
        )) {
            return 'admin';
        }
    
        return 'user';
    }
}