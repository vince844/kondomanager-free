<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Repositories\UserRepository;
use App\Notifications\NewUserEmailNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use Spatie\Permission\Models\Permission;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * This method contain all the logic fto create a new user
     * 1. create the user
     * 2. assign roles and permissions
     * 3. link anagrafica to user
     * 4. Send email to the user so they can create their new password
     * 
     */
    public function createUser(array $validatedData)
    {
        return DB::transaction(function () use ($validatedData) {

            $user = $this->userRepository->create([
                'name'     => $validatedData['name'],
                'email'    => $validatedData['email'],
                'password' => null,
            ]);
    
            $user->assignRole($validatedData['roles']);
            $user->givePermissionTo($validatedData['permissions']);

            if (!empty($validatedData['anagrafica'])) {
                $this->userRepository->linkAnagrafica($user, $validatedData['anagrafica']);
            }

            return $user;
        });
    }

    /**
     * This method contain all the logic to update the existing user
     * 1. update the user
     * 2. update roles and permissions
     * 3. update the anagrafica linked to the user
     * 
     */
    public function updateUser(User $user, array $validatedData)
    {
    
        return DB::transaction(function () use ($user, $validatedData) {

            $this->userRepository->update($user, $validatedData);

            $user->syncRoles($validatedData['roles']);
            $user->syncPermissions($this->permessiDaSincronizzare($user, $validatedData['permissions'] ?? []));

            if (!empty($validatedData['anagrafica'])) {
                $this->dissociateAnagrafica($user);
                $this->associateAnagrafica($user, $validatedData['anagrafica']); 
            } else {
                $this->dissociateAnagrafica($user);
            }

            return $user;

        });
    }

    /**
     * I permessi diretti da scrivere: quelli mandati dal modulo, più quelli che chi sta
     * salvando **non poteva vedere**.
     *
     * `syncPermissions()` sostituisce l'intero insieme, e dalla beta.55 l'elenco proposto è
     * filtrato su ciò che l'attore possiede. Senza questa unione, un collaboratore che corregge
     * l'email di un amministratore gli **toglierebbe in silenzio** i permessi che lui stesso non
     * ha — un salvataggio che non riguardava i permessi, e che li perde.
     *
     * @param  array<int, mixed>  $mandati
     * @return array<int, string> Nomi dei permessi da sincronizzare.
     */
    private function permessiDaSincronizzare(User $user, array $mandati): array
    {
        $attore = Auth::user();

        $conservati = $user->getDirectPermissions()
            ->reject(fn ($permesso) => $attore?->hasPermissionTo($permesso->name))
            ->pluck('name');

        $nomiMandati = Permission::query()
            ->when(true, function ($query) use ($mandati) {
                $ids   = array_filter($mandati, 'is_numeric');
                $nomi  = array_filter($mandati, fn ($v) => is_string($v) && ! is_numeric($v));

                $query->whereIn('id', $ids)->orWhereIn('name', $nomi);
            })
            ->pluck('name');

        return $nomiMandati->merge($conservati)->unique()->values()->all();
    }

    /**
     * Dissociate the user from any existing anagrafica.
     */
    public function dissociateAnagrafica(User $user)
    {
        $currentAnagrafica = Anagrafica::where('user_id', $user->id)->first();

        if ($currentAnagrafica) {
            $currentAnagrafica->update(['user_id' => null]);
        }
    }

    /**
     * Associate the user with a new anagrafica.
     */
    public function associateAnagrafica(User $user, $anagraficaId)
    {
        $anagrafica = Anagrafica::where('id', $anagraficaId)->first();

        if ($anagrafica) {
            $anagrafica->update(['user_id' => $user->id]);
        }
    }
    
}
