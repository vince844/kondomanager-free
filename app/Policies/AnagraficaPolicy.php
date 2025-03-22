<?php

namespace App\Policies;

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AnagraficaPolicy
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
    public function view(User $user, Anagrafica $anagrafica): Response
    {
        return $user->hasPermissionTo('Visualizza utenti')  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per visualizzare le anagrafiche registrate!');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }
}
