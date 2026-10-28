<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Traits\ValidaConcessioneRuoli;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    use ValidaConcessioneRuoli;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can view another user.
     *
     * Grants access if the user has the 'Visualizza utenti' permission.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function view(User $user): Response
    {
        return $user->hasPermissionTo(Permission::VIEW_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.view_users'));
    }

    /**
     * Determine whether the user can create a new user.
     *
     * Grants access if the user has the 'Crea utenti' permission.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function create(User $user): Response
    {
        return $user->hasPermissionTo(Permission::CREATE_USERS->value)  
        ? Response::allow() 
        : Response::deny(__('policies.create_users'));
    }

    /**
     * Determine whether the user can update another user.
     *
     * Grants access if the user has the 'Modifica utenti' permission.
     *
     * Con il bersaglio (la scheda, `UserController::edit` e `update`) vale anche la portata: la scheda di un
     * account fuori dalla portata di chi non è amministratore è solo dell'amministratore, pagina e salvataggio,
     * nome e anagrafica compresi (Coda 258, decisione 136.1). Anche la propria, per un collaboratore: i propri
     * dati si correggono dal profilo. Senza il bersaglio (`Gate::authorize('update', User::class)`, le chiamate
     * della verifica e della revoca, che guardano la portata da sé) vale il solo permesso. «Reinvia» ha la sua
     * abilità, `reinvite()`.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user whose card is opened or saved.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function update(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::EDIT_USERS->value)) {
            return Response::deny(__('policies.edit_users'));
        }

        if ($target && $this->bersaglioFuoriPortata($target, $user)) {
            // Sulla propria scheda il rifiuto dice dove si cambiano i propri dati, non solo chi gestisce l'account.
            return Response::deny(__($user->is($target) ? 'policies.users_own_from_profile' : 'policies.users_privileged_target'));
        }

        return Response::allow();
    }

    /**
     * Determine whether the user can delete another user.
     *
     * Grants access if the user has the 'Elimina utenti' permission.
     *
     * Il secondo parametro è **opzionale** perché le chiamate storiche autorizzano sulla classe
     * (`Gate::authorize('delete', User::class)`) e non sull'istanza. Quando il bersaglio c'è,
     * valgono anche le due invarianti: non sé stessi, non l'ultimo amministratore attivo.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being deleted.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function delete(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::DELETE_USERS->value)) {
            return Response::deny(__('policies.delete_users'));
        }

        return $target ? $this->guardieDiIntegrita($user, $target) : Response::allow();
    }

    /**
     * Determine whether the user can suspend another user.
     *
     * Il permesso è dedicato — «Sospendi utenti» — e non `EDIT_USERS`: modificare la scheda di un
     * condòmino è il mestiere del collaboratore, estromettere qualcuno dall'installazione no.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being suspended.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function suspend(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::SUSPEND_USERS->value)) {
            return Response::deny(__('policies.suspend_users'));
        }

        return $target ? $this->guardieDiIntegrita($user, $target) : Response::allow();
    }

    /**
     * «Reinvia»: azzera la password del bersaglio e gli manda un nuovo link del primo accesso.
     *
     * Il permesso è quello della scheda, «Modifica utenti» (decisione 136.3). Reinvitare chiude fuori il bersaglio
     * finché non apre il link, come sospendere: valgono le stesse guardie di sospensione ed eliminazione (Coda 399).
     * Non su sé stessi, non sull'ultimo amministratore attivo (l'installazione resta governabile anche se la posta non
     * parte), e chi non è amministratore solo su un account nella sua portata.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being reinvited; without it only the permission counts.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function reinvite(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::EDIT_USERS->value)) {
            return Response::deny(__('policies.edit_users'));
        }

        return $target ? $this->guardieDiIntegrita($user, $target, 'policies.users_self_reinvite') : Response::allow();
    }

    /**
     * Togliere la verifica dell'email: con il middleware `verified` su tutto il programma, l'account non arriva più da
     * nessuna parte finché non apre il link che riceve per posta, come dopo «Reinvia».
     *
     * Il permesso è quello della scheda, «Modifica utenti» (decisione 136.3), e valgono le guardie di sospensione ed
     * eliminazione (Coda 399): non sul proprio account, non sull'ultimo amministratore attivo (l'installazione resta
     * governabile anche se la posta non parte), e chi non è amministratore solo su un account nella sua portata.
     * Rimettere la verifica non chiude fuori nessuno: la chiede `update`.
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User $target The user whose email verification is removed.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function revocaVerifica(User $user, User $target): Response
    {
        if (! $user->hasPermissionTo(Permission::EDIT_USERS->value)) {
            return Response::deny(__('policies.edit_users'));
        }

        return $this->guardieDiIntegrita($user, $target, 'policies.users_self_unverify');
    }

    /**
     * Determine whether the user can lift a suspension.
     *
     * Riattivare non può chiudere fuori nessuno, quindi non porta le due invarianti di sospensione ed
     * eliminazione. Riapre però la porta a chi l'amministratore ha chiuso fuori: chi non è amministratore
     * riattiva solo un account nella sua portata, la stessa regola di chi può sospenderlo (Coda 258,
     * decisione 134.1).
     *
     * @param  \App\Models\User $user The user making the authorization request.
     * @param  \App\Models\User|null $target The user being reactivated.
     * @return \Illuminate\Auth\Access\Response Authorization response.
     */
    public function unsuspend(User $user, ?User $target = null): Response
    {
        if (! $user->hasPermissionTo(Permission::SUSPEND_USERS->value)) {
            return Response::deny(__('policies.suspend_users'));
        }

        if ($target && $this->bersaglioFuoriPortata($target, $user)) {
            return Response::deny(__('policies.users_privileged_target'));
        }

        return Response::allow();
    }

    /**
     * Le guardie comuni a sospensione, eliminazione, «Reinvia» e alla verifica tolta.
     *
     * Sul proprio account il rifiuto nomina l'azione chiesta (`$suSeStessi`): sospendere o eliminare, reinvitare,
     * togliere la verifica.
     *
     * Non sono permessi: un permesso risponde a «questa persona può fare questa cosa?», queste
     * rispondono a «l'installazione resta governabile dopo?» e «questo bersaglio è alla sua portata?».
     *
     * Sospendere ed eliminare sono i due modi più forti di chiudere fuori qualcuno (e «Reinvia», che azzera la
     * password, lo chiude fuori finché non apre il link), e i loro permessi
     * l'amministratore li può dare a un ruolo su misura: chi non è amministratore li usa solo su un
     * account nella sua portata (i soli ruoli `utente` e `fornitore`, nessun permesso diretto che lui non
     * ha). È la regola della scheda utenti, di «reinvia» e della verifica (Coda 258, decisione 134.1),
     * presa dallo stesso posto.
     */
    private function guardieDiIntegrita(User $user, User $target, string $suSeStessi = 'policies.users_self_action'): Response
    {
        if ($user->is($target)) {
            return Response::deny(__($suSeStessi));
        }

        if ($target->isUltimoAmministratoreAttivo()) {
            return Response::deny(__('policies.users_last_admin'));
        }

        if ($this->bersaglioFuoriPortata($target, $user)) {
            return Response::deny(__('policies.users_privileged_target'));
        }

        return Response::allow();
    }

}
