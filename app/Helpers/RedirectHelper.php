<?php

namespace App\Helpers;

use Illuminate\Support\Facades\Auth;

class RedirectHelper
{
    public static function userHomeRoute(): string
    {
        $user = Auth::user();

        if ($user->hasRole(['amministratore', 'collaboratore'])) {
            return route('admin.dashboard');
        }

        if (!$user->anagrafica) {
            return route('user.anagrafiche.create');
        }

        return route('user.dashboard');
    }
}
