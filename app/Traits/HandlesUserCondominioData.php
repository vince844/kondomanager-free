<?php

namespace App\Traits;

use App\DataTransferObjects\UserCondominioData;
use App\Models\Anagrafica;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Collection;

/**
 * Provides functionality to retrieve authenticated user's anagrafica and condominio data
 * 
 * This trait centralizes access to user-related condominio information for consistent
 * usage across controllers and services.
 */
trait HandlesUserCondominioData
{
    /**
     * Retrieves the authenticated user's anagrafica and associated condominio IDs
     *
     * Returns a DTO containing:
     * - The user's Anagrafica model instance
     * - Collection of condominio IDs related to the anagrafica
     *
     * @return UserCondominioData {
     *    @var Anagrafica $anagrafica The user's associated anagrafica record
     *    @var Collection<int> $condominioIds Collection of condominio IDs
     * }
     *
     * @throws \RuntimeException If no user is authenticated
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If anagrafica doesn't exist
     * @throws \ErrorException If relationships are not properly loaded
     *
     * @example
     * $data = $this->getUserCondominioData();
     * $anagrafica = $data->anagrafica;
     * $condominioIds = $data->condominioIds;
     */
    protected function getUserCondominioData(): UserCondominioData
    {
        $user = Auth::user();
        $anagrafica = $user->anagrafica;
        $condominioIds = $anagrafica->condomini->pluck('id');

        return new UserCondominioData(
            $anagrafica,
            $condominioIds
        );
    }
}