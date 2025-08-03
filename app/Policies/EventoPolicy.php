<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Evento;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EventoPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.view_events'));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Evento $evento): Response
    {

        return $user->hasPermissionTo(Permission::VIEW_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.view_events'));
               
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.create_events'));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Evento $evento): Response
    {
       if ($user->hasPermissionTo(Permission::EDIT_EVENTS->value)) {
            return Response::allow();
        }

        if ($user->hasPermissionTo(Permission::EDIT_OWN_EVENTS->value)) {
            if ($evento->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.edit_events'));
    }

    public function approve(User $user, Evento $evento): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_EVENTS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_events'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Evento $evento): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_EVENTS->value)) {
            return Response::allow();
        }
        
        if ($user->hasPermissionTo(Permission::DELETE_OWN_EVENTS->value)) {

            if ($evento->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_events'));
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Evento $evento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Evento $evento): bool
    {
        return false;
    }

}
