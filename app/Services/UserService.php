<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Repositories\UserRepository;
use App\Notifications\NewUserEmailNotification;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\User;

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
                'name' => $validatedData['name'],
                'email' => $validatedData['email'],
                'password' => bcrypt(Str::random(16)),
            ]);

            $this->userRepository->assignRoles($user, $validatedData['roles'] ?? []);
            $this->userRepository->assignPermissions($user, $validatedData['permissions'] ?? []);

            if (empty($validatedData['roles'])) {
                $user->assignRole('utente');
            }

            if (!empty($validatedData['anagrafica'])) {
                $this->userRepository->linkAnagrafica($user, $validatedData['anagrafica']);
            }

            $user->notify(new NewUserEmailNotification($user));

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
            $user->syncPermissions($validatedData['permissions']);

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
