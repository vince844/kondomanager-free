<?php

namespace App\Http\Controllers\Users;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Http\Resources\User\EditUserResource;
use App\Http\Resources\User\IndexUserResource;
use App\Http\Resources\User\UserResource;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\NewUserEmailNotification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Gate;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\UserService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use HandleFlashMessages;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\UserService 
     */
    public function __construct(
        private UserService $userService,
    ) {}

    /**
     * Display a paginated list of users, with optional filtering and pagination.
     *
     * This method performs the following steps:
     * - Authorizes the request using the 'view' gate for the User model.
     * - Validates optional query parameters:
     *   - `page`: The page number for pagination (must be >= 1).
     *   - `per_page`: Number of users per page (between 10 and 100).
     *   - `name`: A string used to filter users by name.
     * - Queries the User model applying name filtering if provided.
     * - Paginates the results.
     * - Returns an Inertia view with:
     *   - A collection of users in a transformed resource format.
     *   - Metadata for pagination.
     *   - The applied filters for frontend use.
     *
     * @param  \Illuminate\Http\Request $request The incoming HTTP request containing optional filters and pagination.
     * @return \Inertia\Response The rendered Inertia page with user data and pagination metadata.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to view users.
     * @throws \Illuminate\Validation\ValidationException If the request parameters fail validation.
     */
    public function index(Request $request)
    {
        Gate::authorize('view', User::class);

        $validated = $request->validate([
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'name'     => ['sometimes', 'string', 'max:255'], 
        ]);
    
        $users = User::query()
            ->when($validated['name'] ?? false, function ($query, $name) {
                $query->where('name', 'like', "%{$name}%");
            })
            ->paginate($validated['per_page'] ?? config('pagination.default_per_page'));
    
        return Inertia::render('utenti/ElencoUtenti', [
            'users' => IndexUserResource::collection($users)->response()->getData(true)['data'],
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
            'filters' => $request->only(['name']) 
        ]);
        
    }

    /**
     * Show the form for creating a new user.
     *
     * This method:
     * - Authorizes the current user to create a new user using the 'create' gate.
     * - Retrieves all available roles, permissions, and anagrafiche from the database.
     * - Returns an Inertia view (`utenti/NuovoUtente`) populated with the above data, each transformed
     *   using their respective resource collections.
     *
     * @return \Inertia\Response The rendered Inertia view with required data for the user creation form.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a new user.
     */
    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('utenti/NuovoUtente',[
          /*   'roles'       => RoleResource::collection(Role::all()), */
            'roles'       => RoleResource::collection(Role::with('permissions')->get()),
            
            'permissions' => PermissionResource::collection(Permission::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all()),
        ]);

    }

    /**
     * Handle the incoming request to store a new user.
     *
     * This method:
     * - Authorizes the user to perform the 'create' action on the User model.
     * - Validates the request using the custom `CreateUserRequest`.
     * - Delegates user creation to the `UserService`.
     * - On success, redirects to the users index route with a success flash message.
     * - On failure, logs the error and redirects back with an error flash message.
     *
     * @param  \App\Http\Requests\CreateUserRequest  $request  The validated request containing user data.
     * @return \Illuminate\Http\RedirectResponse Redirects to the user list with a status message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create users.
     */
    public function store(CreateUserRequest $request): RedirectResponse
    {

        Gate::authorize('create', User::class);

        try {

            $user = $this->userService->createUser($request->validated());

        } catch (Exception $e) {

            Log::error('Error creating user: ' . $e->getMessage());
    
            return to_route('utenti.index')->with(
                $this->flashError(__('users.error_create_user'))
            );
        }

        // Send email *outside* DB transaction
        try {

            $user->notify(new NewUserEmailNotification($user));

        } catch (\Throwable $emailError) {
            
            Log::error('Error sending email to user ID ' . $user->id . ': ' . $emailError->getMessage());

            return to_route('utenti.index')
                ->with($this->flashWarning(__('users.error_email_not_sent')));
        }

        return to_route('utenti.index')->with(
            $this->flashSuccess(__('users.success_create_user'))
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
     * Show the form for editing the specified user.
     *
     * This method:
     * - Authorizes the current user to perform the 'update' action on the User model.
     * - Loads related roles, permissions, and anagrafica for the specified user.
     * - Returns an Inertia response rendering the edit user page with all required data.
     *
     * @param  \App\Models\User $utenti The user to be edited.
     * @return \Inertia\Response The rendered Inertia page with user data and supporting collections.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update users.
     */
    public function edit(User $utenti): Response
    {
        Gate::authorize('update', User::class);

        $utenti->load(['roles.permissions', 'permissions', 'anagrafica']);

        return Inertia::render('utenti/ModificaUtente', [
            'user'        => new EditUserResource($utenti),
            'roles'       => RoleResource::collection(Role::with('permissions')->get()),
            'permissions' => PermissionResource::collection(Permission::all()),
            'anagrafiche' => AnagraficaResource::collection(Anagrafica::all())
        ]);
    }

    /**
     * Update the specified user in storage.
     *
     * This method:
     * - Authorizes the current user to perform the 'update' action on the User model.
     * - Validates the incoming update request via `UpdateUserRequest`.
     * - Delegates the update operation to the user service.
     * - Redirects back to the user listing with a success message on success.
     * - Logs the error and redirects with an error message on failure.
     *
     * @param  \App\Http\Requests\UpdateUserRequest  $request  The validated request containing update data.
     * @param  \App\Models\User  $utenti  The user instance to be updated.
     * @return \Illuminate\Http\RedirectResponse Redirect response back to user index with a flash message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update users.
     */
    public function update(UpdateUserRequest $request, User $utenti): RedirectResponse
    {

        Gate::authorize('update', User::class);

        try {

            $this->userService->updateUser($utenti, $request->validated());
    
            return to_route('utenti.index')->with(
                $this->flashSuccess(__('users.success_update_user'))
            );

        } catch (Exception $e) {

            Log::error('Error updating user: ' . $e->getMessage());

            return to_route('utenti.index')->with(
                $this->flashError(__('users.error_update_user'))
            );
        
        }

    }

    /**
     * Remove the specified user from storage.
     *
     * This method:
     * - Authorizes the current user to perform the 'delete' action on the User model.
     * - Attempts to delete the given user instance.
     * - On success, redirects back with a success flash message.
     * - On failure, logs the exception and redirects back with an error flash message.
     *
     * @param  \App\Models\User  $utenti The user instance to be deleted.
     * @return \Illuminate\Http\RedirectResponse Redirects back with a flash message.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete users.
     */
    public function destroy(User $utenti)
    {
        Gate::authorize('delete', User::class);

        try {

            $utenti->delete();

            return back()->with(
                $this->flashSuccess(__('users.success_delete_user'))
            );

        } catch (\Exception $e) {
            
            Log::error('Error deleting user: ' . $e->getMessage());

            return back()->with(
                $this->flashError(__('users.error_delete_user'))
            );
        }

    }
    
}
