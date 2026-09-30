<?php

namespace App\Policies;

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Models\Evento;
use App\Models\User;
use App\Traits\PerimetroFuoriPannello;
use Illuminate\Auth\Access\Response;

class EventoPolicy
{
    use PerimetroFuoriPannello;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.view_events'));
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Evento $evento): Response
    {

        return $user->hasPermissionTo(Permission::VIEW_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.view_events'));
               
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_EVENTS->value)  
               ? Response::allow() 
               : Response::deny(__('policies.create_events'));
    }

    /**
     * Determine whether the user can update the model.
     *
     * `EDIT_EVENTS` da solo, senza «Accesso pannello amministratore», vale solo dentro
     * `nelPerimetroFuoriPannello()`: stessa correzione della Coda 185 sulle segnalazioni (giro di
     * sicurezza della 1.11.0-beta.39). Prima rispondeva sì a qualunque evento, e un condòmino con
     * quel permesso concesso da solo nascondeva o spostava la rata di un altro palazzo.
     */
    public function update(User $user, Evento $evento): Response
    {
        if ($user->hasPermissionTo(Permission::EDIT_EVENTS->value)
            && ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) || $this->nelPerimetroFuoriPannello($user, $evento))) {
            return Response::allow();
        }

        if ($user->hasPermissionTo(Permission::EDIT_OWN_EVENTS->value)) {
            if ($evento->created_by === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny(__('policies.edit_events'));
    }

    /**
     * Determine whether the user can report the payment of this evento (rata condominiale).
     *
     * Non è un alias di `view`: `view` risponde solo al permesso e oggi non ha chiamanti (la lista
     * residenziale usa `viewAny`, e `RecurrenceService` restringe già l'elenco all'utente); qui
     * invece la scadenza è di un'altra persona per
     * costruzione — la controparte è l'amministratore, non un vicino. `PaymentReportingController`
     * scrive lo stato dell'evento e crea un compito nell'inbox dell'amministratore: concedere questa
     * ability sul condominio, invece che sulla persona, permetterebbe a un condòmino dello stesso
     * palazzo di segnalare come pagata la rata di un altro. Serve quindi che l'evento sia proprio una
     * rata condominiale (`SCADENZA_RATA_CONDOMINO`, non un compito amministrativo agganciato allo
     * stesso condominio) e che l'utente sia fra le anagrafiche a cui quella rata è intestata —
     * l'appartenenza al condominio (`anagrafica_condominio`) non basta e non serve: un ex titolare
     * scollegato dal palazzo, ma ancora agganciato alla sua rata (`anagrafica_evento`), deve poterla
     * ancora segnalare.
     */
    public function reportPayment(User $user, Evento $evento): Response
    {
        if (! $user->hasPermissionTo(Permission::VIEW_EVENTS->value)) {
            return Response::deny(__('policies.report_payment_events'));
        }

        if ($evento->tipo !== EventoTipo::SCADENZA_RATA_CONDOMINO) {
            return Response::deny(__('policies.report_payment_events'));
        }

        $anagrafica = $user->anagrafica;

        if ($anagrafica === null) {
            return Response::deny(__('policies.report_payment_events'));
        }

        return $evento->anagrafiche()->whereKey($anagrafica->id)->exists()
            ? Response::allow()
            : Response::deny(__('policies.report_payment_events'));
    }

    public function approve(User $user, Evento $evento): Response
    {
        return $user->hasPermissionTo(Permission::APPROVE_EVENTS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.approve_events'));
    }

    /**
     * Determine whether the user can delete the model.
     *
     * Come `update()`: `DELETE_EVENTS` da solo, senza pannello, vale solo dentro
     * `nelPerimetroFuoriPannello()`. Prima cancellava qualunque evento, rate e compiti
     * dell'amministratore compresi.
     */
    public function delete(User $user, Evento $evento): Response
    {
        if ($user->hasPermissionTo(Permission::DELETE_EVENTS->value)
            && ($user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) || $this->nelPerimetroFuoriPannello($user, $evento))) {
            return Response::allow();
        }
        
        if ($user->hasPermissionTo(Permission::DELETE_OWN_EVENTS->value)) {

            if ($evento->created_by === $user->id) {
                return Response::allow();
            }
            
        } 

        return Response::deny(__('policies.delete_events'));
    }

    /**
     * Fin dove arriva un permesso largo (`EDIT_EVENTS`, `DELETE_EVENTS`) concesso fuori dal pannello:
     * mai un evento di sistema. Lo è ogni evento con un `tipo` diverso da AGENDA (rate, compiti di
     * `InboxService`), ma anche quelli che il `tipo` non l'hanno e portano `meta.type`: i compiti di
     * `SyncScadenziarioWithFattura` (pagamento fornitore, convocazione, ratifica dello sforo,
     * sopravvenienza, deficit) e di `SyncF24WithPagamento` (F24). Lo è anche ogni evento con un
     * `eventable`. Gli eventi d'agenda, dell'amministratore e del condòmino, nascono senza `meta.type`
     * e senza `eventable`. Per il resto valgono le regole comuni di `PerimetroFuoriPannello`.
     */
    private function nelPerimetroFuoriPannello(User $user, Evento $evento): bool
    {
        if (! in_array($evento->tipo, [null, EventoTipo::AGENDA], true)
            || data_get($evento->meta, 'type') !== null
            || $evento->eventable_type !== null) {
            return false;
        }

        return $this->nelPerimetroDellUtente($user, $evento);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Evento $evento): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Evento $evento): bool
    {
        return false;
    }

}
