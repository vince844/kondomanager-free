<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\User\EditUserResource;
use App\Http\Resources\User\UserResource;
use App\Models\Building;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', User::class);

        return Inertia::render('users/UsersList', [
            'users' => UserResource::collection(User::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/UsersNew',[
            'roles'       => RoleResource::collection(Role::all()),
            'permissions' => PermissionResource::collection(Permission::all()),
            'buildings'   => BuildingResource::collection(Building::all())
        ]);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateUserRequest $request): RedirectResponse
    {

        Gate::authorize('create', User::class);
      
        $validated = $request->validated(); 

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $user->syncRoles($request->input('roles'));
        $user->syncPermissions($request->input('permissions'));

        if(empty($request->input('roles'))){
            $user->assignRole('utente');
        }

        $user->buildings()->attach($validated['buildings']);

        return to_route('utenti.index')->with(['message' => [ 'type'    => 'success',
                                                              'message' => "Il nuovo utente è stato creato con successo!"]]);

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
    public function edit(User $utenti): Response
    {
        Gate::authorize('update', User::class);

        $utenti->load(['roles', 'permissions', 'buildings']);

       return Inertia::render('users/UsersEdit', [
        'user'        => new EditUserResource($utenti),
        'roles'       => RoleResource::collection(Role::all()),
        'permissions' => PermissionResource::collection(Permission::all()),
        'buildings'   => BuildingResource::collection(Building::all())
       ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $utenti): RedirectResponse
    {

        Gate::authorize('update', User::class);

        $validated = $request->validated(); 

        $utenti->update([
            'name'  => $validated['name'],
            'email' => $validated['email'],
        ]);

        $utenti->syncRoles($validated['roles']);
        $utenti->syncPermissions($validated['permissions']);
        $buildingIds = collect($validated['buildings'])->pluck('id');
        $utenti->buildings()->sync($buildingIds);

        return to_route('utenti.index')->with(['message' => [ 'type'    => 'success',
                                                              'message' => "L'utente è stato aggiornato con successo"]]);

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $utenti)
    {
        Gate::authorize('delete', User::class);

        $utenti->delete();

        return back()->with(['message' => [ 'type'    => 'success',
                                            'message' => "L'utente è stato eliminato con successo"]]);
    }
}
