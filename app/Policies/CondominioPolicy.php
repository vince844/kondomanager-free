<?php

namespace App\Policies;

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CondominioPolicy
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
        return $user->hasPermissionTo('Visualizza condomini')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per visualizzare i condomini registrati!');
        
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo('Crea condomini')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per creare un nuovo condominio!'); 
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user): Response
    {
        return $user->hasPermissionTo('Modifica condomini')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per modificare il condominio!');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasPermissionTo('Elimina condomini')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per eliminare il condominio!');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Condominio $condominio): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Condominio $condominio): bool
    {
        return false;
        
    }
}
