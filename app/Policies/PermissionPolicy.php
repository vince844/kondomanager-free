<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class PermissionPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole(['amministratore', 'collaboratore'])  
        ? Response::allow() 
        : Response::deny('Non hai permessi sufficienti per gestire i permessi utente!');
    }
}
