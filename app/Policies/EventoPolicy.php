<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Policies;

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\Inbox\CompitoDellaSegnalazione;
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
     *
     * Il compito di una segnalazione si apre e si modifica solo da chi apre la segnalazione ({@see compitoCheVede()}): la pagina di modifica ne mostra il
     * titolo e la descrizione.
     */
    public function update(User $user, Evento $evento): Response
    {
        if (! self::compitoCheVede($user, $evento)) {
            return Response::deny(__('policies.edit_events'));
        }

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

    /**
     * «Rifiuta» e «Completa» della casella delle attività del pannello (`Dashboard\ActionInboxController`, Coda 402): un
     * compito della casella è una scadenza in agenda, e chiuderlo la modifica. Chiede «Modifica scadenza in agenda»;
     * la casella si legge con `viewAny`, come le scadenze della dashboard del pannello. Che cosa è un compito della
     * casella lo decide il controller.
     */
    public function chiudiCompito(User $user): Response
    {
        return $user->hasPermissionTo(Permission::EDIT_EVENTS->value)
            ? Response::allow()
            : Response::deny(__('policies.edit_events'));
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
     * dell'amministratore compresi. Il compito di una segnalazione, come in `update()`, solo da chi apre la segnalazione.
     */
    public function delete(User $user, Evento $evento): Response
    {
        if (! self::compitoCheVede($user, $evento)) {
            return Response::deny(__('policies.delete_events'));
        }

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
     * Il compito di una segnalazione si tocca solo da chi apre la segnalazione, con la regola delle due Inbox ({@see CompitoDellaSegnalazione::chiVede()}):
     * chi ha «Modifica scadenza in agenda» e non apre le segnalazioni non ne legge il titolo dalla pagina di modifica. Gli altri eventi non si leggono.
     */
    private static function compitoCheVede(User $user, Evento $evento): bool
    {
        if (($evento->tipo?->value ?? data_get($evento->meta, 'type')) !== CompitoDellaSegnalazione::TIPO) {
            return true;
        }

        return CompitoDellaSegnalazione::soloQuelliCheVede(Evento::query()->whereKey($evento->getKey()), CompitoDellaSegnalazione::chiVede($user))->exists();
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
