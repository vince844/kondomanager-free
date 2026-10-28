<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CategoriaFornitore;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Le categorie dei fornitori (`Fornitori\Categorie\CategoriaFornitoreController`): l'elenco mostra ogni categoria con i
 * fornitori che la usano; creare, rinominare ed eliminare una categoria cambia la classificazione dell'archivio dei
 * fornitori. I permessi sono quelli delle anagrafiche, come per i fornitori (`FornitorePolicy`, Coda 402): l'elenco
 * «Visualizza anagrafiche»; creare una categoria «Crea anagrafiche» o «Modifica anagrafiche»; rinominarla ed eliminarla
 * «Modifica anagrafiche». Creare basta «Crea anagrafiche» perché il «+» accanto alla tendina «Categoria fornitore» sta
 * anche nel modulo «Nuovo fornitore», che si apre con «Crea anagrafiche». L'amministratore e il collaboratore di serie
 * hanno tutti e tre i permessi.
 */
class CategoriaFornitorePolicy
{
    /**
     * L'elenco delle categorie: «Visualizza anagrafiche», come l'elenco dei fornitori.
     */
    public function viewAny(User $user): Response
    {
        return $user->checkPermissionTo(Permission::VIEW_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.categorie.permessi.view'));
    }

    /**
     * Una categoria nuova, dalla pagina delle categorie o dal «+» delle schede del fornitore: «Crea anagrafiche» (il
     * modulo «Nuovo fornitore» si apre con quello) o «Modifica anagrafiche» (il modulo di modifica).
     */
    public function create(User $user): Response
    {
        return $user->checkPermissionTo(Permission::CREATE_ANAGRAFICHE->value) || $user->checkPermissionTo(Permission::EDIT_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.categorie.permessi.create'));
    }

    /**
     * Rinominare una categoria: «Modifica anagrafiche».
     */
    public function update(User $user, CategoriaFornitore $categoria): Response
    {
        return $this->gestisce($user);
    }

    /**
     * Eliminare una categoria che nessun fornitore usa: «Modifica anagrafiche».
     */
    public function delete(User $user, CategoriaFornitore $categoria): Response
    {
        return $this->gestisce($user);
    }

    /**
     * Rinominare ed eliminare: il rifiuto dice queste due azioni, anche a chi può creare una categoria.
     */
    private function gestisce(User $user): Response
    {
        return $user->checkPermissionTo(Permission::EDIT_ANAGRAFICHE->value)
            ? Response::allow()
            : Response::deny(__('fornitori.categorie.permessi.update'));
    }
}
