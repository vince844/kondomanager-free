<?php

namespace App\Http\Controllers\Roles;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ruolo\CreateRuoloRequest;
use App\Http\Requests\Ruolo\UpdateRuoloRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class RoleController extends Controller
{

    use AuthorizesRequests;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', Role::class);
        
        return Inertia::render('ruoli/ElencoRuoli', [
            'roles' => RoleResource::collection(Role::all())
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('ruoli/NuovoRuolo',[
            'permissions' => PermissionResource::collection(Permission::all())
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateRuoloRequest $request): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $validated = $request->validated(); 

        $role = Role::create([
            'name'        => $validated['name'],
            'description' => $validated['description']
        ]);
        
        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

       return to_route('ruoli.index')->with([
            'message' => [
                'type'    => 'success',
                'message' => 'Il nuovo ruolo è stato creato con successo!'
            ]
        ]);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        Gate::authorize('update', Role::class);

        $role = Role::findById($id);

        $protectedRoles = ['amministratore', 'fornitore', 'collaboratore', 'utente'];

        if (in_array($role->name, $protectedRoles)) {
        
            return back()->with([
                'message' => [
                    'type'    => 'info',
                    'message' => 'Non è possibile modificare il ruolo di default "'.$role->name.'"' 
                ]
            ]);
  
        }

       $role->load('permissions');

       return Inertia::render('ruoli/ModificaRuolo', [
        'role'        => new RoleResource($role),
        'permissions' => PermissionResource::collection(Permission::all())
       ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRuoloRequest $request, Role $ruoli): RedirectResponse
    {
        Gate::authorize('update', Role::class);
        
        $validated = $request->validated(); 

        $ruoli->update([
            'name' => $validated['name'],
            'description' => $validated['description']
        ]);  

        $ruoli->syncPermissions($validated['permissions']);

        return to_route('ruoli.index')->with([
            'message' => [
                'type'    => 'success',
                'message' => 'Il ruolo è stato aggiornato con successo!' 
            ]
        ]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): RedirectResponse
    {
        Gate::authorize('delete', Role::class);

        $role = Role::findById($id);

        $protectedRoles = ['amministratore', 'fornitore', 'collaboratore', 'utente'];

        if (in_array($role->name, $protectedRoles)) {

            return back()->with([
                'message' => [
                    'type'    => 'error',
                    'message' => 'Non è possibile eliminare il ruolo "'.$role->name.'" di default!'
                ]
            ]);

        }

        $role->delete();

        return back()->with([
            'message' => [
                'type'    => 'success',
                'message' => 'Il ruolo "'.$role->name.'" è stato eliminato con successo!'
            ]
        ]);

    }
}
