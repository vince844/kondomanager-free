<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return Inertia::render('users/UsersList', [

            'users' => UserResource::collection(User::all())
            
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {

        return Inertia::render('users/UsersNew',[
            'roles'       => RoleResource::collection(Role::all()),
            'permissions' => PermissionResource::collection(Permission::all()),
        ]);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateUserRequest $request): RedirectResponse
    {

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

        return to_route('utenti.index');

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
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $utenti)
    {
        
        $utenti->delete();

        return back()->with(['message' => [ 'type'    => 'success',
                                            'message' => "L'utente è stato eliminato con successo"]]);
    }
}
