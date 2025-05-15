<?php

namespace App\Policies;

use App\Enums\Permission;
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
     * Determine whether the user can view a specific condominio.
     *
     * Grants access if the user has the 'Visualizza condomini' permission.
     *
     * @param  \App\Models\User $user The user making the request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function view(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_CONDOMINI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.view_buildings'));
    }

    /**
     * Determine whether the user can create a new condominio.
     *
     * Grants access if the user has the 'Crea condomini' permission.
     *
     * @param  \App\Models\User $user The user making the request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_CONDOMINI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.create_building')); 
    }

    /**
     * Determine whether the user can update an existing condominio.
     *
     * Grants access if the user has the 'Modifica condomini' permission.
     *
     * @param  \App\Models\User $user The user making the request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function update(User $user): Response
    {
        return $user->hasPermissionTo(Permission::EDIT_CONDOMINI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.edit_building'));
    }

    /**
     * Determine whether the user can delete a condominio.
     *
     * Grants access if the user has the 'Elimina condomini' permission.
     *
     * @param  \App\Models\User $user The user making the request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user): Response
    {
        return $user->hasPermissionTo(Permission::DELETE_CONDOMINI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.delete_building'));
    }

}
