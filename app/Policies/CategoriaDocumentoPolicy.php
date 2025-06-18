<?php

namespace App\Policies;

use App\Models\CategoriaDocumento;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use App\Enums\Permission;

class CategoriaDocumentoPolicy
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
    public function view(User $user, CategoriaDocumento $categoriaDocumento): Response
    {
        if ($user->hasPermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value)) {
            return Response::allow();
        } 

        return Response::deny(__('policies.view_archive_documents'));
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
    public function update(User $user, CategoriaDocumento $categoriaDocumento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CategoriaDocumento $categoriaDocumento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CategoriaDocumento $categoriaDocumento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CategoriaDocumento $categoriaDocumento): bool
    {
        return false;
    }
}
