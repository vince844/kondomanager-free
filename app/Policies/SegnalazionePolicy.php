<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Segnalazione;
use App\Models\User;
use App\Rules\CondominioDellUtente;
use Illuminate\Auth\Access\Response;

class SegnalazionePolicy
{

    /**
     * Determine whether the user can view the specified segnalazione.
     *
     * Grants access if the user has the general permission to view all segnalazioni,
     * or if they are the creator and have permission to view their own segnalazioni.
     * Denies access otherwise.
     *
     * @param  \App\Models\User $user The user attempting to view the segnalazione.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione being viewed.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function view(User $user, Segnalazione $segnalazione): Response
    {

        if ($user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value)) {
            return Response::allow();
        } 

        if ($user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)) {
            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.view_tickets'));

    }

    /**
     * L'elenco del portale (decisione 134.4): si apre a chi ha «Visualizza segnalazioni» o «Visualizza
     * proprie segnalazioni», e mostra ciò che la regola del portale gli concede
     * (`Segnalazione::scopeVisibiliNelPortale()`, che guarda quale dei due permessi ha).
     *
     * Non è `view()`: quella, chiesta con il modello vuoto dell'elenco, per chi vede solo le proprie
     * confrontava l'autore di nessuna segnalazione con l'utente e rifiutava l'elenco intero, mentre la
     * scheda gli apre le sue. L'elenco del pannello resta su `view()`: mostra tutte le segnalazioni.
     */
    public function elencoNelPortale(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value)
            || $user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)
            ? Response::allow()
            : Response::deny(__('policies.view_tickets'));
    }

    /**
     * Verifica se l'utente ha i permessi per visualizzare una segnalazione specifica.
     *
     * La funzione gestisce i permessi in base ai seguenti criteri:
     * - Gli utenti con il permesso "Accesso pannello amministratore" hanno accesso completo.
     * - Gli utenti con il permesso "Visualizza segnalazioni" vedono quelle che la regola del portale
     *   mostra loro (`Segnalazione::scopeVisibiliNelPortale()`: pubblicate e approvate o proprie, e
     *   indirizzate a loro; le private solo all'autore e alle persone collegate).
     * - Gli utenti con il permesso "Visualizza proprie segnalazioni" possono vedere solo le segnalazioni che hanno creato.
     * - Se nessuna di queste condizioni è soddisfatta, l'accesso viene negato.
     *
     * @param  \App\Models\User $user L'utente che sta tentando di visualizzare la segnalazione
     * @param  \App\Models\Segnalazione  $segnalazione  La segnalazione da visualizzare
     * @return \Illuminate\Auth\Access\Response La risposta che consente o nega l'accesso
     */
    public function show(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)) {
            return Response::allow();
        } 

        // Fuori dal pannello vale la regola sola di `Segnalazione::scopeVisibiliNelPortale()`, la
        // stessa dell'elenco: pubblicata e approvata (o propria), e indirizzata all'utente; una
        // privata solo all'autore e alle persone collegate (Code 263 e 304). Il palazzo da solo non
        // basta: una segnalazione in moderazione o una bozza la apre solo il suo autore, e una
        // privata senza residenti resta sua, anche chieste per id. Per un utente senza anagrafica
        // la regola risponde lo stesso, senza errori.
        if ($user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value)
            && $segnalazione->visibileNelPortaleA($user)) {
            return Response::allow();
        }

        if ($user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)) {
            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            } 
        }
 
        return Response::deny(__('policies.view_ticket'));
    }

    /**
     * Determine whether the user can create a new segnalazione.
     *
     * This method checks if the user has the 'Crea segnalazioni' permission.
     * If so, the action is allowed; otherwise, it is denied.
     *
     * @param  \App\Models\User $user The user attempting to create a segnalazione.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_SEGNALAZIONI->value)  
               ? Response::allow() 
               : Response::deny(__('policies.create_ticket'));
    }

    /**
     * Determine whether the user can update the specified segnalazione.
     *
     * This method allows access if the user has permission to update any segnalazione,
     * or if they have permission to update their own segnalazioni and they are the creator.
     * If none of these conditions are satisfied, the action is denied.
     *
     * @param  \App\Models\User $user The user attempting to update the segnalazione.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione being updated.
     * @return \Illuminate\Auth\Access\Response  Authorization response.
     */
    public function update(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::EDIT_SEGNALAZIONI->value)) {
            // Giro di sicurezza della 1.11.0-beta.39, Coda 185: EDIT_SEGNALAZIONI da solo non
            // guardava il condominio. Chi ha accesso al pannello (amministratore, collaboratore)
            // gestisce ogni palazzo dell'installazione, com'è sempre stato; chi ha ricevuto il
            // permesso senza l'accesso al pannello — un ruolo personalizzato — resta limitato ai
            // soli condomìni della propria anagrafica, e mai sulla segnalazione indirizzata ad altre
            // persone (la privata di un vicino): vedi nelPerimetroFuoriPannello().
            if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
                || $this->nelPerimetroFuoriPannello($user, $segnalazione)) {
                return Response::allow();
            }
        }

        if ($user->hasPermissionTo(Permission::EDIT_OWN_SEGNALAZIONI->value)) {

            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 
        
        return Response::deny(__('policies.edit_tickets'));
    }

    /**
     * Determine whether the user can delete the specified segnalazione.
     *
     * The method checks if the user has the general permission to delete any segnalazione,
     * or if they have permission to delete only their own segnalazioni and are the creator of it.
     * If neither condition is met, access is denied.
     *
     * @param  \App\Models\User  $user The user performing the action.
     * @param  \App\Models\Segnalazione $segnalazione  The segnalazione to be deleted.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user, Segnalazione $segnalazione): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_SEGNALAZIONI->value)) {
            // Stessa guardia di update() qui sopra, stessa Coda 185.
            if ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
                || $this->nelPerimetroFuoriPannello($user, $segnalazione)) {
                return Response::allow();
            }
        }

        if ($user->hasPermissionTo(Permission::DELETE_OWN_SEGNALAZIONI->value)) {

            if ($segnalazione->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_tickets'));

    }

    /**
     * Determine if the given user can approve the specified segnalazione.
     *
     * Checks if the user has the 'APPROVE_SEGNALAZIONI' permission and
     * returns an authorization response accordingly.
     *
     * @param \App\Models\User $user The user requesting to approve.
     * @param \App\Models\Segnalazione $segnalazione The ticket to be approved.
     *
     * @return \Illuminate\Auth\Access\Response Authorization response (allow or deny).
     */
    public function approve(User $user, Segnalazione $segnalazione): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_SEGNALAZIONI->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_ticket'));
    }

    /**
     * «Rendi pubblica» una segnalazione privata (Coda 304, decisione 127.4).
     *
     * Lo dà chi può approvare le segnalazioni: è il permesso che decide già quando una segnalazione
     * diventa visibile al condominio (l'interruttore di approvazione la pubblica). `PUBLISH_*` dice
     * un'altra cosa — che le proprie escono senza moderazione — e il collaboratore di serie, che ce
     * l'ha senza `APPROVE_SEGNALAZIONI`, non rende pubblica la privata di un condòmino.
     */
    public function rendiPubblica(User $user, Segnalazione $segnalazione): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_SEGNALAZIONI->value)
            ? Response::allow()
            : Response::deny(__('policies.approve_ticket'));
    }

    /**
     * Fin dove arriva un permesso largo (`EDIT_SEGNALAZIONI`, `DELETE_SEGNALAZIONI`) concesso fuori
     * dal pannello. Stessa semantica di `App\Traits\PerimetroFuoriPannello`, che qui non si riusa
     * perché la segnalazione ha un solo `condominio_id` e non una relazione `condomini()`: il
     * palazzo deve essere dell'utente, e la segnalazione non deve essere indirizzata ad altre
     * persone (la privata di un vicino ha agganciata la sua anagrafica: il condòmino non la vede in
     * elenco, ma la raggiungerebbe per id).
     */
    private function nelPerimetroFuoriPannello(User $user, Segnalazione $segnalazione): bool
    {
        $anagraficaId = $user->anagrafica?->id;

        if ($anagraficaId === null) {
            return false;
        }

        if ($segnalazione->anagrafiche()->where('anagrafiche.id', '!=', $anagraficaId)->exists()) {
            return false;
        }

        return CondominioDellUtente::appartiene($user, $segnalazione->condominio_id);
    }
}
