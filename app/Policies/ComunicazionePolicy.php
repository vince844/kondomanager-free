<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Comunicazione;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ComunicazionePolicy
{
    /**
     * Determine whether the user can view the specified comunicazione.
     *
     * This method grants access if the user has permission to view comunicazioni ('Visualizza comunicazioni').
     * If the user doesn't have this permission, access is denied with an appropriate message.
     *
     * @param  \App\Models\User $user The user attempting to view the comunicazione.
     * @param  \App\Models\Comunicazione $comunicazione  The comunicazione being viewed.
     * @return \Illuminate\Auth\Access\Response Authorization response, either allow or deny.
     */
    public function view(User $user, Comunicazione $comunicazione): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_COMUNICAZIONI->value)  
               ? Response::allow() 
               : Response::deny(__('policies.view_communications'));
    }

    /**
     * Determine whether the user can view the specified comunicazione.
     *
     * This method checks the user's permissions and determines whether they can view a specific 
     * 'Comunicazione'. It evaluates the following conditions:
     * - Users with the 'Accesso pannello amministratore' permission are allowed to view all comunicazioni.
     * - Users with the 'Visualizza comunicazioni' permission can view comunicazioni assigned to them 
     *   or their condominio.
     * - Users with the 'Visualizza proprie comunicazioni' permission can view only the comunicazioni 
     *   they have created.
     * 
     * If none of these conditions are met, access is denied with a localized message.
     *
     * @param  \App\Models\User $user The user attempting to view the comunicazione.
     * @param  \App\Models\Comunicazione $comunicazione  The comunicazione being viewed.
     * @return \Illuminate\Auth\Access\Response Authorization response, either allow or deny.
     */
    public function show(User $user, Comunicazione $comunicazione): Response
    {
        // Grant access for admin panel access
        if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) {
            return Response::allow();
        }

        // Grant access if the user can view comunicazioni and is assigned to it or their condominio
        if (
            $user->hasPermissionTo(Permission::VIEW_COMUNICAZIONI->value) && 
            $this->isAssignedToUserOrCondominio($user, $comunicazione)
        ) {
            return Response::allow();
        }

        // Grant access if the user can view their own comunicazioni
        if ($user->hasPermissionTo(Permission::VIEW_OWN_COMUNICAZIONI->value)) {
            if ($comunicazione->created_by === $user->id) {
                return Response::allow();
            }
        }

        // Deny access if none of the conditions are met
        return Response::deny(__('policies.view_communication'));
    }

    /**
     * Determine whether the user can create a new comunicazione.
     *
     * This method checks if the user has the 'Crea comunicazioni' permission.
     * If granted, the action is allowed; otherwise, access is denied with a localized message.
     *
     * @param  \App\Models\User  $user  The user attempting to create a comunicazione.
     * @return \Illuminate\Auth\Access\Response  Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_COMUNICAZIONI->value)  
               ? Response::allow() 
               : Response::deny(__('policies.create_communication'));
    }

    /**
     * Determine whether the user can update the specified comunicazione.
     *
     * This method grants access to update the comunicazione if the user has:
     * 1. Permission to modify all comunicazioni ('Modifica comunicazioni'),
     * 2. Permission to modify only their own comunicazioni ('Modifica proprie comunicazioni') and the comunicazione was created by the user.
     *
     * If the user doesn't meet either of these conditions, access is denied with an appropriate message.
     *
     * @param  \App\Models\User $user The user attempting to update the comunicazione.
     * @param  \App\Models\Comunicazione $comunicazione  The comunicazione to be updated.
     * @return \Illuminate\Auth\Access\Response Authorization response, either allow or deny.
     */
    public function update(User $user, Comunicazione $comunicazione): Response
    {

        if ($user->hasPermissionTo(Permission::EDIT_COMUNICAZIONI->value)) {
            return Response::allow();
        }

        if ($user->hasPermissionTo(Permission::EDIT_OWN_COMUNICAZIONI->value)) {
            if ($comunicazione->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.edit_communications'));
    }

    /**
     * Determine whether the user can delete the specified comunicazione.
     *
     * This method grants access to delete the comunicazione if the user has:
     * 1. Permission to delete all comunicazioni ('Elimina comunicazioni'),
     * 2. Permission to delete only their own comunicazioni ('Elimina proprie comunicazioni') and the comunicazione was created by the user.
     *
     * If the user doesn't meet either of these conditions, access is denied with an appropriate message.
     *
     * @param  \App\Models\User $user The user attempting to delete the comunicazione.
     * @param  \App\Models\Comunicazione $comunicazione  The comunicazione to be deleted.
     * @return \Illuminate\Auth\Access\Response Authorization response, either allow or deny.
     */
    public function delete(User $user, Comunicazione $comunicazione): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_COMUNICAZIONI->value)) {
            return Response::allow();
        }
        
        if ($user->hasPermissionTo(Permission::DELETE_OWN_COMUNICAZIONI->value)) {

            if ($comunicazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_communications'));
    }

    public function approve(User $user, Comunicazione $comunicazione): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_COMUNICAZIONI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_communication'));
    }

    /**
     * Verifica se l'utente è assegnato alla comunicazione o al suo condominio.
     *
     * @param \App\Models\User $user
     * @param \App\Models\Comunicazione $comunicazione
     * @return bool
     */
    private function isAssignedToUserOrCondominio(User $user, Comunicazione $comunicazione): bool
    {
        $anagrafica = $user->anagrafica;

        if (!$anagrafica) {
            return false;
        }

        // Assigned directly to the user
        if ($comunicazione->anagrafiche->contains($anagrafica->id)) {
            return true;
        }

        // If it is assigned to any anagrafiche, do NOT allow access via condominio
        if ($comunicazione->anagrafiche->isNotEmpty()) {
            return false;
        }

        // Otherwise, allow via matching condominio
        $condominioIds = $anagrafica->condomini->pluck('id');

        return $comunicazione->condomini->pluck('id')->intersect($condominioIds)->isNotEmpty();
    }

}
