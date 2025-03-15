<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
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
        return $user->hasPermissionTo('Visualizza utenti')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per visualizzare gli utenti registrati!');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo('Crea utenti')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per creare un nuovo utente!');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user): Response
    {
        return $user->hasPermissionTo('Modifica utenti')  
        ? Response::allow() 
        : Response::deny("Non hai permessi sufficienti per modificare l'utente!");
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasPermissionTo('Elimina utenti')  
        ? Response::allow() 
        : Response::deny("Non hai permessi sufficienti per eliminare l'utente!");
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }
}
