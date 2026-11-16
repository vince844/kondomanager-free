<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PermissionPolicy
{
    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user): Response
    {
        return $user->hasRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])  
        ? Response::allow() 
        : Response::deny(__('policies.view_permissions'));
    }
}
