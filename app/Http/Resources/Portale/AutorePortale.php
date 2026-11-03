<?php

namespace App\Http\Resources\Portale;

use App\Models\User;

/**
 * L'autore di una segnalazione, una comunicazione, un documento o un evento, come lo riceve il
 * portale del condòmino (Coda 260): l'id, che le pagine confrontano con l'utente collegato per
 * offrire «Modifica» ed «Elimina», e il nome.
 *
 * Il nome è quello dell'anagrafica, con il ripiego sull'utente, come nei commenti
 * (`CommentoResource`): è il nome con cui la persona compare nel condominio. Niente email, ruoli,
 * permessi, codice fiscale, telefoni, indirizzo: le pagine del portale leggono solo il nome, e
 * `UserResource` e `AnagraficaResource` complete restano al pannello.
 */
final class AutorePortale
{
    /**
     * Senza utente (account cancellato) la forma resta la stessa, con i valori vuoti: gli elenchi del
     * portale leggono `created_by.user.name` senza controllare che ci sia.
     *
     * @return array{user: array{id: int|null, name: string|null}}
     */
    public static function di(?User $user): array
    {
        if ($user === null) {
            return ['user' => ['id' => null, 'name' => null]];
        }

        return [
            'user' => [
                'id'   => $user->id,
                'name' => $user->anagrafica?->nome ?: $user->name,
            ],
        ];
    }
}
