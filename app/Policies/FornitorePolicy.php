<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Fornitore;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * I fornitori del pannello (`App\Http\Controllers\Fornitori\*`): l'elenco, la scheda, i moduli, i referenti, i documenti e
 * la situazione debitoria mostrano partita IVA, codice fiscale, telefoni, email, IBAN e i dati delle persone che
 * rappresentano il fornitore; le azioni scrivono l'archivio dei fornitori. Ogni azione chiede il suo permesso, e i
 * permessi sono quelli delle anagrafiche, con cui il menù mostra già la voce «Fornitori» (`AppHeader.vue`, «Visualizza
 * anagrafiche»; Coda 402): entrare nel pannello non basta.
 *
 * Leggere un fornitore e le sue schede chiede «Visualizza anagrafiche»; crearlo «Crea anagrafiche»; modificarlo, e con
 * lui i suoi referenti, i suoi documenti e i suoi debiti pregressi, «Modifica anagrafiche»; eliminarlo «Elimina
 * anagrafiche». I ruoli di serie (`App\Enums\Role::permissions()`): l'amministratore li ha tutti; il collaboratore ha i
 * primi tre e non «Elimina anagrafiche», come per le anagrafiche.
 *
 * Un permesso che manca dal database vale «no» (`checkPermissionTo`, non `hasPermissionTo`, che solleva un'eccezione),
 * come in `AnagraficaPolicy`.
 */
class FornitorePolicy
{
    /**
     * L'elenco: «Visualizza anagrafiche», il permesso con cui il menù mostra la voce.
     */
    public function viewAny(User $user): Response
    {
        return $user->checkPermissionTo(Permission::VIEW_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.permessi.view'));
    }

    /**
     * La scheda e le sue linguette (referenti, documenti, situazione debitoria): lo stesso permesso dell'elenco.
     */
    public function view(User $user, Fornitore $fornitore): Response
    {
        return $user->checkPermissionTo(Permission::VIEW_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.permessi.view'));
    }

    /**
     * Il modulo nuovo e il salvataggio, anche dalla registrazione di una fattura: «Crea anagrafiche».
     */
    public function create(User $user): Response
    {
        return $user->checkPermissionTo(Permission::CREATE_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.permessi.create'));
    }

    /**
     * Il modulo di modifica e il salvataggio, i referenti, i documenti e i debiti pregressi: «Modifica anagrafiche».
     */
    public function update(User $user, Fornitore $fornitore): Response
    {
        return $user->checkPermissionTo(Permission::EDIT_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.permessi.update'));
    }

    /**
     * L'eliminazione: «Elimina anagrafiche».
     */
    public function delete(User $user, Fornitore $fornitore): Response
    {
        return $user->checkPermissionTo(Permission::DELETE_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.permessi.delete'));
    }

    public function restore(User $user, Fornitore $fornitore): bool
    {
        return false;
    }

    public function forceDelete(User $user, Fornitore $fornitore): bool
    {
        return false;
    }
}
