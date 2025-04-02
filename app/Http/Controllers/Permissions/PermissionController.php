<?php

namespace App\Http\Controllers\Permissions;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
      /**
     * Display a listing of the resource.
     */
    public function index()
    {
        
        return Inertia::render('permessi/ElencoPermessi', [
            'permissions' => PermissionResource::collection(Permission::all())
        ]);
    }
}
