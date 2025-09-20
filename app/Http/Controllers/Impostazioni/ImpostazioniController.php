<?php

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class ImpostazioniController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        Gate::allowIf(
            fn (User $user) => $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value),
            __('errors.403_message')
        );

        return Inertia::render('impostazioni/impostazioni');
    }
}
