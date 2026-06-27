<?php

namespace App\Policies;

use App\Contracts\Commentable;
use App\Enums\Permission;
use App\Models\Commento;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CommentoPolicy
{
    /**
     * Bypass totale per gli amministratori con accesso al pannello.
     * Gli admin possono sempre moderare, approvare, modificare ed eliminare
     * qualsiasi commento, indipendentemente dagli altri controlli.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) {
            return true;
        }

        return null;
    }

    /**
     * Determina se l'utente può creare un commento su un'entità commentabile.
     *
     * Tre condizioni tutte necessarie:
     * 1. Ha il permesso di commentare segnalazioni
     * 2. Il thread è aperto (commentiAbilitati() === true)
     * 3. Appartiene al condominio dell'entità (multi-tenancy)
     */
    public function create(User $user, Commentable $commentable): bool
    {
        return $user->hasPermissionTo(Permission::COMMENT_SEGNALAZIONI->value)
            && $commentable->commentiAbilitati()
            && $this->appartieneAlCondominio($user, $commentable);
    }

    /**
     * Determina se l'utente può modificare il commento.
     * Solo l'autore può modificare i propri commenti.
     */
    public function update(User $user, Commento $commento): bool
    {
        return $user->hasPermissionTo(Permission::EDIT_OWN_COMMENTS_SEGNALAZIONI->value)
            && $commento->user_id === $user->id;
    }

    /**
     * Determina se l'utente può eliminare il commento (soft delete).
     * Solo l'autore può eliminare i propri commenti.
     */
    public function delete(User $user, Commento $commento): bool
    {
        return $user->hasPermissionTo(Permission::DELETE_OWN_COMMENTS_SEGNALAZIONI->value)
            && $commento->user_id === $user->id;
    }

    /**
     * Determina se l'utente può moderare (nascondere/approvare) un commento.
     * Richiede il permesso di approvazione commenti segnalazioni.
     */
    public function moderate(User $user): bool
    {
        return $user->hasPermissionTo(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value);
    }

    /**
     * Verifica che l'utente appartenga al condominio dell'entità commentata.
     *
     * NOTA MULTI-TENANCY: il permesso COMMENT_SEGNALAZIONI da solo non isola
     * i condomini. Senza questo check, un condomino con permesso potrebbe
     * commentare segnalazioni di condomini a cui non appartiene.
     *
     * Percorso relazionale reale del progetto: user → anagrafica → condomini
     */
    private function appartieneAlCondominio(User $user, Commentable $commentable): bool
    {
        $condominioId = $commentable->condominioId();

        if ($condominioId === null) {
            return false;
        }

        $anagrafica = $user->anagrafica;

        if ($anagrafica === null) {
            return false;
        }

        return $anagrafica->condomini()->whereKey($condominioId)->exists();
    }
}
