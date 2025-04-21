<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;

class RedirectHelper
{
    /**
     * Redirects the user to the appropriate home route based on their role and anagrafica status.
     *
     * - If the user is an "amministratore" or "collaboratore", they are redirected to the admin dashboard.
     * - If the user does not have a anagrafica, they are redirected to create one.
     * - If the user has a anagrafica, they are redirected to the user dashboard.
     *
     * @return string The URL for the appropriate route based on the user's status.
     */
    public static function userHomeRoute(): string
    {
    // Get the currently authenticated user
    $user = Auth::user();

    // Check if the user has one of the specified roles (amministratore or collaboratore)
    if ($user->hasRole(['amministratore', 'collaboratore'])) {
        // If the user is an administrator or collaborator, return the admin dashboard route
        return route('admin.dashboard');
    }

    // Check if the user doesn't have an associated "anagrafica"
    if (!$user->anagrafica) {
        // If no profile is found, return the route for creating an "anagrafica" 
        return route('user.anagrafiche.create');
    }

    // If the user has a profile, return the route for the user's dashboard
    return route('user.dashboard');
    }
}
