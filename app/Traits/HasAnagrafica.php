<?php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use App\Models\Anagrafica;

/**
 * Provides authenticated user's anagrafica retrieval functionality
 *
 * This trait offers a standardized way to access the current
 * authenticated user's associated anagrafica record across
 * the application.
 */
trait HasAnagrafica
{
    /**
     * Retrieves the authenticated user's associated anagrafica
     *
     * Returns the Anagrafica model instance associated with the currently
     * authenticated user. If no user is authenticated or the user has no
     * associated anagrafica, aborts with a 403 Forbidden response.
     *
     * @return Anagrafica The user's associated anagrafica record
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     *         Throws HTTP 403 Forbidden if:
     *         - No user is authenticated (HTTP 403)
     *         - User has no associated anagrafica (HTTP 403)
     *
     * @example
     * // In a controller method:
     * $anagrafica = $this->getUserAnagrafica();
     * $name = $anagrafica->name;
     */
    protected function getUserAnagrafica(): Anagrafica
    {
        $user = Auth::user();

        if (!$user?->anagrafica) {
            abort(403, __('auth.not_authenticated'));
        }

        return $user->anagrafica;
    }
}