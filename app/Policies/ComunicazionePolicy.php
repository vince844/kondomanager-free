<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Comunicazione;
use App\Models\User;
use App\Traits\PerimetroFuoriPannello;
use Illuminate\Auth\Access\Response;

class ComunicazionePolicy
{
    use PerimetroFuoriPannello;

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
     * L'elenco del portale (decisione 134.4): si apre a chi ha «Visualizza comunicazioni» o
     * «Visualizza proprie comunicazioni», e mostra ciò che la regola del portale gli concede
     * (`Comunicazione::scopeVisibiliNelPortale()`). Gemella di `SegnalazionePolicy::elencoNelPortale()`:
     * `view()` guarda solo il primo permesso, e a chi vede solo le proprie rifiutava l'elenco intero.
     */
    public function elencoNelPortale(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_COMUNICAZIONI->value)
            || $user->hasPermissionTo(Permission::VIEW_OWN_COMUNICAZIONI->value)
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

        // Fuori dal pannello vale la regola sola di `Comunicazione::scopeVisibiliNelPortale()`, la
        // stessa dell'elenco: pubblicata e approvata (o propria), e indirizzata all'utente; una
        // privata solo all'autore e alle persone collegate (Code 198 e 304). Il palazzo da solo non
        // basta: una bozza o una comunicazione in moderazione la apre solo il suo autore, anche
        // chiesta per id.
        //
        // La regola si chiede al database e non alle relazioni del modello: così le relazioni
        // (`anagrafiche`, `condomini`) non si caricano qui e non arrivano alla pagina con il modello
        // (Coda 260).
        if (
            $user->hasPermissionTo(Permission::VIEW_COMUNICAZIONI->value) &&
            $comunicazione->visibileNelPortaleA($user)
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

        // Fuori dal pannello il permesso largo vale solo nel perimetro dell'utente (giro di sicurezza
        // della 1.11.0-beta.39, Coda 185 allargata): vedi PerimetroFuoriPannello.
        if ($user->hasPermissionTo(Permission::EDIT_COMUNICAZIONI->value)
            && ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) || $this->nelPerimetroDellUtente($user, $comunicazione))) {
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
        if ($user->hasPermissionTo(Permission::DELETE_COMUNICAZIONI->value)
            && ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) || $this->nelPerimetroDellUtente($user, $comunicazione))) {
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

}
