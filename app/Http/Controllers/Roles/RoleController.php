<?php

namespace App\Http\Controllers\Roles;

use App\Enums\Permission as EnumsPermission;
use App\Enums\Role as EnumsRole;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ConfermaPasswordRecente;
use App\Http\Requests\Ruolo\CreateRuoloRequest;
use App\Http\Requests\Ruolo\UpdateRuoloRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Settings\GeneralSettings;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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

        $roles = Role::all()->load('permissions');
        
        // Prendi tutti i conteggi in una singola query
        $userCounts = DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->select('role_id', DB::raw('COUNT(*) as count'))
            ->groupBy('role_id')
            ->pluck('count', 'role_id');
        
        // Assegna i conteggi ai ruoli
        $roles->each(function($role) use ($userCounts) {
            $role->users_count = $userCounts[$role->id] ?? 0;
        });
        
        return Inertia::render('ruoli/ElencoRuoli', [
            'roles' => RoleResource::collection($roles)
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
    public function store(CreateRuoloRequest $request): RedirectResponse|SymfonyResponse
    {
        Gate::authorize('create', Role::class);

        // Coda 261, decisione 134.1: un ruolo nuovo è un pacchetto di poteri, e crearlo chiede la password
        // ridigitata da poco. Dopo la conferma si torna al modulo, che si compila di nuovo.
        if (ConfermaPasswordRecente::scaduta($request)) {
            return ConfermaPasswordRecente::richiedi($request, 'ruoli.create');
        }

        $validated = $request->validated(); 

        try {
            DB::beginTransaction();

            $role = Role::create([
                'name'        => $validated['name'],
                'description' => $validated['description']
            ]);
            
            // 1. Assegna i permessi regolari
            if (!empty($validated['permissions'])) {
                $role->syncPermissions($validated['permissions']);
            }

            // 2. Gestisci il permesso admin separatamente
            if ($validated['accessAdmin']) {
                $role->givePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value); 
            }

            DB::commit();

        } catch (\Exception $e) {
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

        // Exclude the admin panel permission from the list
        $permissions = Permission::whereNotIn('name', [EnumsPermission::ACCESS_ADMIN_PANEL->value])->get();

        return Inertia::render('ruoli/ModificaRuolo', [
            'role'        => new RoleResource($role),
            'permissions' => PermissionResource::collection($permissions) 
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
    public function update(UpdateRuoloRequest $request, Role $ruoli): RedirectResponse|SymfonyResponse
    {
        Gate::authorize('update', Role::class);

        // Check if role is protected
        if ($this->isProtectedRole($ruoli->name)) {
            return to_route('ruoli.index')->with(
                $this->flashInfo(__('ruoli.cannot_edit_default_role', ['role' => $ruoli->name]))
            );
        }

        // Coda 261, decisione 134.1: aggiungere permessi a un ruolo alza tutti quelli che ce l'hanno, e
        // modificarlo chiede la password ridigitata da poco, come concedere un ruolo.
        if (ConfermaPasswordRecente::scaduta($request)) {
            return ConfermaPasswordRecente::richiedi($request, 'ruoli.edit', ['ruoli' => $ruoli->getKey()]);
        }
        
        $validated = $request->validated(); 

        // Il ruolo dei nuovi iscritti è un nome salvato nelle impostazioni generali: se il nome di questo ruolo
        // cambia, il nome salvato lo segue nella stessa transazione, o chi si registra dal sito non riceverebbe più
        // il ruolo scelto dall'amministratore (Coda 258). Gli inviti non c'entrano: un invito dà il ruolo dei nuovi
        // iscritti solo se è «utente» o «fornitore», che sono ruoli di sistema e non si rinominano. Il confronto
        // non guarda le maiuscole, come in `destroy()`.
        $impostazioni = app(GeneralSettings::class);
        $eraIlRuoloDeiNuoviIscritti = $this->isRuoloDeiNuoviIscritti($ruoli->name);

        try {
            DB::beginTransaction();

            $nomeDiPrima = $ruoli->name;

            $ruoli->update([
                'name'        => $validated['name'],
                'description' => $validated['description']
            ]);

            if ($eraIlRuoloDeiNuoviIscritti && $ruoli->name !== $nomeDiPrima) {
                $impostazioni->default_user_role = $ruoli->name;
                $impostazioni->save();
            }

            // 1. Sincronizza i permessi regolari (escluso admin)
            $ruoli->syncPermissions($validated['permissions'] ?? []);

            // 2. Gestisci il permesso admin separatamente
            if ($validated['accessAdmin']) {
                $ruoli->givePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value);
            } else {
                $ruoli->revokePermissionTo(EnumsPermission::ACCESS_ADMIN_PANEL->value);
            }
            
            DB::commit();

        } catch (\Exception $e) {
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

        // Il ruolo dei nuovi iscritti (decisione 136.2) è un nome salvato nelle impostazioni generali: eliminato il
        // ruolo, chi si registra dal sito non riceverebbe più il ruolo scelto. Prima se ne sceglie un altro. Qui
        // arrivano solo i ruoli su misura (quelli di sistema si fermano sopra), che un invito non dà mai. Il
        // confronto non guarda le maiuscole, come il MySQL di serie quando la registrazione cerca il ruolo per nome.
        // L'elenco dei ruoli lo dice già prima dell'invio (`RoleResource`, `is_registration_role`).
        if ($this->isRuoloDeiNuoviIscritti($role->name)) {
            return to_route('ruoli.index')->with(
                $this->flashError(__('ruoli.cannot_delete_registration_role', ['role' => $role->name]))
            );
        }

        $userCount = $role->users()->count();
        $defaultRole = null;

        if ($userCount > 0) {
            $defaultRole = Role::where('name', EnumsRole::UTENTE->value)->first();

            if (!$defaultRole) {
                return back()->with(
                    $this->flashError(__('ruoli.default_role_not_found'))
                );
            }
        }

        // Si toglie solo il ruolo eliminato (Coda 258, decisione 134.1): chi ne ha un altro lo tiene,
        // «amministratore» compreso, e `utente` lo riceve solo chi resterebbe senza ruoli. Così
        // l'eliminazione di un ruolo non lascia l'installazione senza amministratori.
        // Togliere il ruolo agli utenti, dare `utente` a chi resta senza ed eliminare il ruolo stanno in una
        // transazione: se una delle tre cose non va a buon fine, il ruolo, i suoi permessi e i suoi utenti
        // restano com'erano.
        try {
            $riassegnati = DB::transaction(function () use ($role, $defaultRole) {
                $riassegnati = 0;

                foreach ($role->users as $user) {
                    $user->removeRole($role);

                    if ($user->roles()->doesntExist()) {
                        $user->assignRole($defaultRole);
                        $riassegnati++;
                    }
                }

                $role->delete();

                return $riassegnati;
            });
        } catch (\Exception $e) {

            Log::error('Error deleting role: ' . $e->getMessage());
            return back()->with(
                $this->flashError(__('ruoli.error_delete_role'))
            );
        }

        $message = $riassegnati > 0
            ? __('ruoli.success_delete_with_reassign', [
                'count' => $riassegnati,
                'default_role' => EnumsRole::UTENTE->value
            ])
            : __('ruoli.success_delete_role');

        return back()->with($this->flashSuccess($message));
    }

}
