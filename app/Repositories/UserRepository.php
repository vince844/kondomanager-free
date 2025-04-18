<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Anagrafica;

class UserRepository
{
    public function create(array $data)
    {
        return User::create($data);
    }

    public function update(User $user, array $validatedData)
    {
        return $user->update([
            'name'  => $validatedData['name'],
            'email' => $validatedData['email'],
        ]);
    }

    public function linkAnagrafica(User $user, $anagraficaId)
    {
        $anagrafica = Anagrafica::find($anagraficaId);

        if ($anagrafica) {
            $anagrafica->user()->associate($user);
            $anagrafica->save();
        }
    }
}
