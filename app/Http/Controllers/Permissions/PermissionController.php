<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Permissions;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
      /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', Permission::class);

        return Inertia::render('permessi/ElencoPermessi', [
            'permissions' => PermissionResource::collection(Permission::all())
        ]);
    }
}
