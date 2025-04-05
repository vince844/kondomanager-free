<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class RolePolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole(['amministratore', 'collaboratore'])  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per gestire i ruoli utente!');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasRole(['amministratore', 'collaboratore'])  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per creare un nuovo ruolo!'); 
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user): Response
    {
        return $user->hasRole(['amministratore', 'collaboratore'])
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per aggiornare il ruolo!');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user): Response
    {
        return $user->hasRole(['amministratore', 'collaboratore'])
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per eliminare il ruolo!');
    }
}
