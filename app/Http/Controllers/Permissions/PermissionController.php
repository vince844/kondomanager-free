<?php

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
