<?php

namespace App\Policies;

use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Lang;

class ComunicazionePolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Comunicazione $comunicazione): Response
    {
        return $user->hasPermissionTo('Visualizza comunicazioni')  
        ? Response::allow() 
        : Response::deny(Lang::get('policies.view_communications'));
    }

    /**
     * Determine whether the given user can view the specified comunicazione.
     *
     * This method applies layered access control:
     * - First, it checks if the user has the required permission ("Visualizza comunicazioni").
     * - Then, if the user has one of the privileged roles ("amministratore", "collaboratore"), access is granted.
     * - Otherwise, it checks if the comunicazione is directly assigned to the user's anagrafica.
     * - If not, it checks if the comunicazione is assigned to any of the condomini to which the user's anagrafica belongs,
     *   and if it's a global comunicazione (not directed to specific anagrafiche).
     *
     * @param  \App\Models\User  $user  The user requesting access.
     * @param  \App\Models\Comunicazione  $comunicazione  The comunicazione being accessed.
     * @return \Illuminate\Auth\Access\Response
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function show(User $user, Comunicazione $comunicazione): Response
    {
        // Check if the user has permission to view comunicazioni
        if (!$user->hasPermissionTo('Visualizza comunicazioni')) {
            return Response::deny(Lang::get('policies.view_communications'));
        }

        // Admins and collaborators can always access
        if ($user->hasRole(['amministratore', 'collaboratore'])) {
            return Response::allow();
        }

        $anagrafica = $user->anagrafica;
        $condominioIds = $anagrafica->condomini->pluck('id')->toArray();

        // Check if the comunicazione is directly assigned to the user
        $isAssignedToUser = $comunicazione->anagrafiche->contains($anagrafica->id);

        // Check if the comunicazione is assigned to any of the user's condomini
        // and NOT to any specific anagrafiche (global to the condominio)
        $isAssignedToCondominio = $comunicazione->anagrafiche->isEmpty()
            && $comunicazione->condomini->pluck('id')->intersect($condominioIds)->isNotEmpty();

        if ($isAssignedToUser || $isAssignedToCondominio) {
            return Response::allow();
        }

        return Response::deny(Lang::get('policies.view_communication_denied'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo('Crea comunicazioni')  
            ? Response::allow() 
            : Response::deny(Lang::get('policies.create_communication'));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Comunicazione $comunicazione): Response
    {
         // User can edit any comunicazione
        if ($user->hasPermissionTo('Modifica comunicazioni')) {
            return Response::allow();
        }

        // User can edit only their own comunicazioni
        if ($user->hasPermissionTo('Modifica proprie comunicazioni')) {
            if ($comunicazione->created_by === $user->id) {
                return Response::allow();
            }

            // Has limited permission but not the owner
            return Response::deny(Lang::get('policies.edit_owns_communications'));
        }

        // No permission at all
        return Response::deny(Lang::get('policies.edit_communications'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Comunicazione $comunicazione): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Comunicazione $comunicazione): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Comunicazione $comunicazione): bool
    {
        return false;
    }
}
