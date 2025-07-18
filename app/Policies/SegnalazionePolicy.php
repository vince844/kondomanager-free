<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SegnalazionePolicy
{

    /**
     * Determine whether the user can view the specified segnalazione.
     *
     * Grants access if the user has the general permission to view all segnalazioni,
     * or if they are the creator and have permission to view their own segnalazioni.
     * Denies access otherwise.
     *
     * @param  \App\Models\User $user The user attempting to view the segnalazione.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione being viewed.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function view(User $user, Segnalazione $segnalazione): Response
    {

        if ($user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value)) {
            return Response::allow();
        } 

        if ($user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)) {
            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.view_tickets'));

    }

    /**
     * Verifica se l'utente ha i permessi per visualizzare una segnalazione specifica.
     *
     * La funzione gestisce i permessi in base ai seguenti criteri:
     * - Gli utenti con il permesso "Accesso pannello amministratore" hanno accesso completo.
     * - Gli utenti con il permesso "Visualizza segnalazioni" possono vedere le segnalazioni a loro assegnate
     *   o quelle assegnate al loro condominio (se non ci sono assegnazioni dirette).
     * - Gli utenti con il permesso "Visualizza proprie segnalazioni" possono vedere solo le segnalazioni che hanno creato.
     * - Se nessuna di queste condizioni è soddisfatta, l'accesso viene negato.
     *
     * @param  \App\Models\User $user L'utente che sta tentando di visualizzare la segnalazione
     * @param  \App\Models\Segnalazione  $segnalazione  La segnalazione da visualizzare
     * @return \Illuminate\Auth\Access\Response La risposta che consente o nega l'accesso
     */
    public function show(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) {
            return Response::allow();
        } 

        if ($user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value)) {
            $anagrafica = $user->anagrafica;
            $condominioIds = $anagrafica->condomini->pluck('id')->toArray();
        
            $isAssignedToUser = $segnalazione->anagrafiche->contains($anagrafica);
            $isAssignedToCondominio = in_array($segnalazione->condominio_id, $condominioIds)
                && $segnalazione->anagrafiche->isEmpty();
        
            if ($isAssignedToUser || $isAssignedToCondominio) {
                return Response::allow();
            }
        } 

        if ($user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)) {
            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            } 
        }
 
        return Response::deny(__('policies.view_ticket'));
    }

    /**
     * Determine whether the user can create a new segnalazione.
     *
     * This method checks if the user has the 'Crea segnalazioni' permission.
     * If so, the action is allowed; otherwise, it is denied.
     *
     * @param  \App\Models\User $user The user attempting to create a segnalazione.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_SEGNALAZIONI->value)  
               ? Response::allow() 
               : Response::deny(__('policies.create_ticket'));
    }

    /**
     * Determine whether the user can update the specified segnalazione.
     *
     * This method allows access if the user has permission to update any segnalazione,
     * or if they have permission to update their own segnalazioni and they are the creator.
     * If none of these conditions are satisfied, the action is denied.
     *
     * @param  \App\Models\User $user The user attempting to update the segnalazione.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione being updated.
     * @return \Illuminate\Auth\Access\Response  Authorization response.
     */
    public function update(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::EDIT_SEGNALAZIONI->value)) {
            return Response::allow();
        } 
        
        if ($user->hasPermissionTo(Permission::EDIT_OWN_SEGNALAZIONI->value)) {

            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 
        
        return Response::deny(__('policies.edit_tickets'));
    }

    /**
     * Determine whether the user can delete the specified segnalazione.
     *
     * The method checks if the user has the general permission to delete any segnalazione,
     * or if they have permission to delete only their own segnalazioni and are the creator of it.
     * If neither condition is met, access is denied.
     *
     * @param  \App\Models\User  $user The user performing the action.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione to be deleted.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_SEGNALAZIONI->value)) {
            return Response::allow();
        }
        
        if ($user->hasPermissionTo(Permission::DELETE_OWN_SEGNALAZIONI->value)) {

            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_tickets'));

    }

    /**
     * Determine if the given user can approve the specified segnalazione.
     *
     * Checks if the user has the 'APPROVE_SEGNALAZIONI' permission and
     * returns an authorization response accordingly.
     *
     * @param \App\Models\User $user The user requesting to approve.
     * @param \App\Models\Segnalazione $segnalazione The ticket to be approved.
     *
     * @return \Illuminate\Auth\Access\Response Authorization response (allow or deny).
     */
    public function approve(User $user, Segnalazione $segnalazione): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_SEGNALAZIONI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_ticket'));
    }
}
