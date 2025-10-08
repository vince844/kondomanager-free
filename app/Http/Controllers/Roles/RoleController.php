<?php

namespace App\Http\Controllers\Roles;

use App\Enums\Permission as EnumsPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ruolo\CreateRuoloRequest;
use App\Http\Requests\Ruolo\UpdateRuoloRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Traits\HandleFlashMessages;
use App\Traits\HasProtectedRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    use AuthorizesRequests, HandleFlashMessages, HasProtectedRoles;

    /**
     * Display a listing of all roles.
     * 
     * @return \Inertia\Response
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function index()
    {
        Gate::authorize('view', Role::class);
        
        return Inertia::render('ruoli/ElencoRuoli', [
            'roles' => RoleResource::collection(Role::all())
        ]);
    }

    /**
     * Show the form for creating a new role.
     * 
     * @return \Inertia\Response
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        // Exclude specific permissions (e.g., 'Accesso pannello amministratore')
        $permissions = Permission::whereNotIn('name', [EnumsPermission::ACCESS_ADMIN_PANEL->value])->get();

        // Transform the permissions using PermissionResource
        $permissions = PermissionResource::collection($permissions);

        // Render the page with the filtered permissions
        return Inertia::render('ruoli/NuovoRuolo', compact('permissions'));
    }

    /**
     * Store a newly created role in storage.
     * 
     * @param \App\Http\Requests\Ruolo\CreateRuoloRequest $request
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Exception
     */
    public function store(CreateRuoloRequest $request): RedirectResponse
    {
        Gate::authorize('create', Role::class);

        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $role = Role::create([
                'name'        => $validated['name'],
                'description' => $validated['description']
            ]);
            
            if (!empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            if ($validated['accessAdmin']) {
                $role->givePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value); 
            }

            DB::commit();

        }catch (\Exception $e) {

            DB::rollback();

            Log::error('Error creating role: ' . $e->getMessage());
        
            return to_route('ruoli.index')->with(
                $this->flashError(__('ruoli.error_create_role'))
            );

        }

        return to_route('ruoli.index')->with(
            $this->flashSuccess(__('ruoli.success_create_role'))
        );

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified role.
     * 
     * @param string $id
     * @return \Illuminate\Http\RedirectResponse|\Inertia\Response
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function edit(string $id)
    {
        Gate::authorize('update', Role::class);

        $role = Role::findOrFail($id);

        if ($this->isProtectedRole($role->name)) {

            return to_route('ruoli.index')->with(
                $this->flashInfo(__('ruoli.cannot_edit_default_role', ['role' => $role->name]))
            );
  
        }

       $role->load('permissions');

       return Inertia::render('ruoli/ModificaRuolo', [
        'role'        => new RoleResource($role),
        'permissions' => PermissionResource::collection(Permission::all())
       ]);
    }

    /**
     * Update the specified role in storage.
     * 
     * @param \App\Http\Requests\Ruolo\UpdateRuoloRequest $request
     * @param \Spatie\Permission\Models\Role $ruoli
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Exception
     */
    public function update(UpdateRuoloRequest $request, Role $ruoli): RedirectResponse
    {
        Gate::authorize('update', Role::class);

        // Check if role is protected
        if ($this->isProtectedRole($ruoli->name)) {
            return to_route('ruoli.index')->with(
                $this->flashInfo(__('ruoli.cannot_edit_default_role', ['role' => $ruoli->name]))
            );
        }
        
        $validated = $request->validated(); 

        try {

            DB::beginTransaction();

            $ruoli->update([
                'name'        => $validated['name'],
                'description' => $validated['description']
            ]);  

            $ruoli->syncPermissions($validated['permissions']);

            if ($validated['accessAdmin']) {
                $ruoli->givePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value);
            } else {
                $ruoli->revokePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value);
            }
        
        }catch (\Exception $e) {

            DB::rollback();

            Log::error('Error updating role: ' . $e->getMessage());
        
            return to_route('ruoli.index')->with(
                $this->flashError(__('ruoli.error_update_role'))
            );

        }

        return to_route('ruoli.index')->with(
            $this->flashSuccess(__('ruoli.success_update_role'))
        );

    }

    /**
     * Remove the specified role from storage.
     * 
     * @param string $id
     * @return \Illuminate\Http\RedirectResponse
     * 
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function destroy(string $id): RedirectResponse
    {
        Gate::authorize('delete', Role::class);

        $role = Role::findOrFail($id);

        if ($this->isProtectedRole($role->name)) {

            return to_route('ruoli.index')->with(
                $this->flashInfo(__('ruoli.cannot_delete_default_role', ['role' => $role->name]))
            );
  
        }

        try {

            $role->delete();

            return back()->with(
                $this->flashSuccess(__('ruoli.success_delete_role'))
            );
            
        } catch (\Exception $e) {
            
            Log::error('Error deleting role: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('ruoli.error_delete_role'))
            );
        }

    }
}
