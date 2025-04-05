<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\User\EditUserResource;
use App\Http\Resources\User\UserResource;
use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Gate;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\UserService;
use Carbon\Carbon;

class UserController extends Controller
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        Gate::authorize('view', User::class);

        return Inertia::render('utenti/ElencoUtenti', [
            'users' => UserResource::collection(User::all())
        ]); 
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('utenti/NuovoUtente',[
            'roles'       => RoleResource::collection(Role::all()),
            'permissions' => PermissionResource::collection(Permission::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
        ]);

    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(CreateUserRequest $request): RedirectResponse
    {

        Gate::authorize('create', User::class);

        try {

            $this->userService->createUser($request->validated());
    
            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "Il nuovo utente è stato creato con successo!",
                ],
            ]);

        } catch (Exception $e) {

            Log::error('Error creating user: ' . $e->getMessage());
    
            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante la creazione del nuovo utente!",
                ]
            ]);
        }
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

        $utenti->load(['roles', 'permissions', 'anagrafica']);

        return Inertia::render('utenti/ModificaUtente', [
            'user'        => new EditUserResource($utenti),
            'roles'       => RoleResource::collection(Role::all()),
            'permissions' => PermissionResource::collection(Permission::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $utenti): RedirectResponse
    {

        Gate::authorize('update', User::class);

        try {

            $this->userService->updateUser($utenti, $request->validated());
    
            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "L'utente è stato aggiornato con successo!"
                ]
            ]);

        } catch (Exception $e) {

            Log::error('Error updating user: ' . $e->getMessage());

            return to_route('utenti.index')->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento dell'utente!"
                ]
            ]);
        
        }

    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $utenti)
    {
        Gate::authorize('delete', User::class);

        $utenti->delete();

        return back()->with([
            'message' => [ 
                'type'    => 'success',
                'message' => "L'utente è stato eliminato con successo"
            ]
        ]);
    }
    
}
