<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class GeneralSettingsPolicy
{
    public function manage(User $user): Response
    {
        return $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.manage_general_settings'));
    }
}
