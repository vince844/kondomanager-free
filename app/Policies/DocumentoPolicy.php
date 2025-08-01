<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DocumentoPolicy
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
    public function view(User $user, Documento $documento): Response
    {

        if (
            $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) &&
            $user->hasPermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value)
        ) {
            return Response::allow();
        }

        if (
            $user->hasPermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value) && 
            $this->isAssignedToUserOrCondominio($user, $documento)
        ) {
            return Response::allow();
        }

        return Response::deny(__('policies.view_archive_documents'));
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        if ($user->hasPermissionTo(Permission::CREATE_ARCHIVE_DOCUMENTS->value)) {
            return Response::allow();
        } 

        return Response::deny(__('policies.create_archive_documents'));
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Documento $documento): Response
    {
        if ($user->hasPermissionTo(Permission::EDIT_ARCHIVE_DOCUMENTS->value)) {
            return Response::allow();
        }

        if ($user->hasPermissionTo(Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value)) {
            if ($documento->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.edit_archive_documents'));
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Documento $documento): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_ARCHIVE_DOCUMENTS->value)) {
            return Response::allow();
        }
        
        if ($user->hasPermissionTo(Permission::DELETE_OWN_ARCHIVE_DOCUMENTS->value)) {

            if ($documento->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_archive_documents'));
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Documento $documento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Documento $documento): bool
    {
        return false;
    }

    public function approve(User $user, Documento $documento): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_ARCHIVE_DOCUMENTS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_archive_documents'));
    }

    /**
     * Verifica se l'utente è assegnato al documento o al suo condominio.
     *
     * @param \App\Models\User $user
     * @param \App\Models\Documento $documento
     * @return bool
     */
    private function isAssignedToUserOrCondominio(User $user, Documento $documento): bool
    {
        $anagrafica = $user->anagrafica;

        if (!$anagrafica) {
            return false;
        }

        // Assigned directly to the user
        if ($documento->anagrafiche->contains($anagrafica->id)) {
            return true;
        }

        // If it is assigned to any anagrafiche, do NOT allow access via condominio
        if ($documento->anagrafiche->isNotEmpty()) {
            return false;
        }

        // Otherwise, allow via matching condominio
        $condominioIds = $anagrafica->condomini->pluck('id');

        return $documento->condomini->pluck('id')->intersect($condominioIds)->isNotEmpty();
    }
}
