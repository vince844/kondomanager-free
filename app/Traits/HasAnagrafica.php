<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;

trait HasAnagrafica
{
    protected function getUserAnagrafica(): \App\Models\Anagrafica
    {
        $user = Auth::user();

        if (!$user?->anagrafica) {
            abort(403, __('auth.not_authenticated'));
        }

        return $user->anagrafica;
    }
}
