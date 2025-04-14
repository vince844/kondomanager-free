<?php

namespace App\Policies;

use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SegnalazionePolicy
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
    public function view(User $user): Response
    {
        return $user->hasPermissionTo('Visualizza segnalazioni')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per visualizzare le segnalazioni!');

    }

    /**
     * Determine whether the user can view the model.
     */
    public function show(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasRole(['amministratore', 'collaboratore'])) {
            return Response::allow();
        } 
    
        $anagrafica = $user->anagrafica;
        $condominioIds = $anagrafica->condomini->pluck('id')->toArray();
    
        $isAssignedToUser = $segnalazione->anagrafiche->contains($anagrafica);
        $isAssignedToCondominio = in_array($segnalazione->condominio_id, $condominioIds)
            && $segnalazione->anagrafiche->isEmpty();
    
        if ($isAssignedToUser || $isAssignedToCondominio) {
            return Response::allow();
        }
    
        return Response::deny('Non ha permessi sufficienti per visualizzare questa segnalazione!');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo('Crea segnalazioni')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per creare una nuova segnalazione!');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo('Modifica segnalazioni')) {
            return Response::allow();
        } 
        
        if ($user->hasPermissionTo('Modifica proprie segnalazioni')) {

            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 
        
        return Response::deny('Non hai permessi sufficienti per modificare questa segnalazione!');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasPermissionTo('Elimina segnalazioni')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per eliminare questa segnalazione!');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Segnalazione $segnalazione): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Segnalazione $segnalazione): bool
    {
        return false;
    }
}
