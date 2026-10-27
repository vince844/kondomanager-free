<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Le anagrafiche del pannello (`Anagrafiche\AnagraficaController`): l'elenco, la scheda e i moduli mostrano codici
 * fiscali, telefoni e indirizzi email, e le azioni scrivono l'archivio delle persone. Ogni azione chiede il suo
 * permesso delle anagrafiche (decisione 137): entrare nel pannello non basta.
 *
 * I ruoli di serie (`App\Enums\Role::permissions()`): l'amministratore li ha tutti; il collaboratore ha
 * «Visualizza», «Crea» e «Modifica anagrafiche», non «Elimina anagrafiche».
 *
 * Un permesso che manca dal database vale «no» (`checkPermissionTo`, non `hasPermissionTo`, che solleva
 * un'eccezione): la policy risponde anche dopo una scrittura, quando, registrato un passaggio, il server decide se
 * mandare alla pagina il collegamento al modulo di modifica (`PassaggioController::store`), e lì un errore cadrebbe
 * su un passaggio già registrato.
 */
class AnagraficaPolicy
{
    /**
     * L'elenco: «Visualizza anagrafiche», il permesso con cui il menù mostra la voce.
     */
    public function viewAny(User $user): Response
    {
        return $user->checkPermissionTo(Permission::VIEW_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('anagrafiche.permessi.view'));
    }

    /**
     * La scheda: lo stesso permesso dell'elenco.
     */
    public function view(User $user, Anagrafica $anagrafica): Response
    {
        return $user->checkPermissionTo(Permission::VIEW_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('anagrafiche.permessi.view'));
    }

    /**
     * Il modulo nuovo e il salvataggio: «Crea anagrafiche».
     */
    public function create(User $user): Response
    {
        return $user->checkPermissionTo(Permission::CREATE_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('anagrafiche.permessi.create'));
    }

    /**
     * Il modulo di modifica e il salvataggio: «Modifica anagrafiche».
     */
    public function update(User $user, Anagrafica $anagrafica): Response
    {
        return $user->checkPermissionTo(Permission::EDIT_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('anagrafiche.permessi.update'));
    }

    /**
     * L'eliminazione: «Elimina anagrafiche».
     */
    public function delete(User $user, Anagrafica $anagrafica): Response
    {
        return $user->checkPermissionTo(Permission::DELETE_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('anagrafiche.permessi.delete'));
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Anagrafica $anagrafica): bool
    {
        return false;
    }
}
