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
        $user->fill([
            'name'  => $validatedData['name'],
            'email' => $validatedData['email'],
        ]);

        // Un indirizzo nuovo non è verificato, chiunque l'abbia scritto: come dal profilo
        // (`ProfileController::update`). L'utente ritrova la pagina della verifica, e il link parte
        // verso la casella nuova (Coda 258).
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        return $user->save();
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
