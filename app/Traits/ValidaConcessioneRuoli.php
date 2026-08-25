<?php

namespace App\Traits;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Chi può concedere quale ruolo, e quale permesso.
 *
 * ## Perché esiste
 *
 * Fino alla beta.55 `roles` era validato con un `['required']` nudo e la pagina di modifica
 * riceveva l'elenco **completo** dei ruoli: chi aveva `EDIT_USERS` — cioè ogni collaboratore —
 * si assegnava «amministratore» scegliendolo dalla tendina. Tre clic, nessuna richiesta costruita
 * a mano.
 *
 * Le due regole, decise il 16/08/2026:
 *
 * 1. i **ruoli privilegiati** (`amministratore`, `collaboratore`) li assegna solo chi è
 *    amministratore. Il collaboratore continua ad assegnare `utente` e `fornitore`, che è il suo
 *    mestiere: creare le utenze dei condòmini;
 * 2. **nessuno concede un permesso che non possiede**. Vale anche per l'amministratore, che li ha
 *    tutti e quindi non se ne accorge.
 *
 * Le regole stanno qui e non nei due controller perché la stessa forma si ripete in creazione e in
 * modifica: correggerne una sola avrebbe lasciato viva l'altra — è la seconda lezione della
 * beta.54, la correzione va nel punto condiviso.
 */
trait ValidaConcessioneRuoli
{
    /**
     * I ruoli che solo un amministratore può concedere.
     *
     * @return array<int, string>
     */
    protected function ruoliPrivilegiati(): array
    {
        return RoleEnum::privilegiati();
    }

    /**
     * Risolve un ruolo dal valore che arriva dal modulo.
     *
     * ⚠️ **Il modulo manda un id, ma `syncRoles()` accetta anche i nomi.** Una regola che
     * risolvesse solo per id lascerebbe passare la stringa «amministratore» — la validazione non
     * troverebbe nulla da vietare e Spatie, subito dopo, la onorerebbe. È il reperto della
     * revisione avversariale di questa beta, ed è la stessa lezione della beta.53: una regola su
     * un dato che ha due forme va scritta su **entrambe**.
     */
    protected function risolviRuolo($valore): ?Role
    {
        $valore = is_array($valore) ? ($valore[0] ?? null) : $valore;

        if ($valore === null || $valore === '') {
            return null;
        }

        return is_numeric($valore)
            ? Role::find((int) $valore)
            : Role::where('name', $valore)->first();
    }

    /**
     * Risolve un permesso dal valore che arriva dal modulo — id o nome, come sopra.
     */
    protected function risolviPermesso($valore): ?Permission
    {
        if ($valore === null || $valore === '') {
            return null;
        }

        if (is_array($valore)) {
            $valore = $valore['id'] ?? $valore['name'] ?? null;
        }

        return is_numeric($valore)
            ? Permission::find((int) $valore)
            : Permission::where('name', $valore)->first();
    }

    /**
     * Regola sul campo `roles`.
     *
     * Vieta di **concedere** un ruolo privilegiato, non di conservarlo: se il ruolo è quello che
     * il bersaglio ha già, salvare la scheda non concede niente e deve funzionare — altrimenti un
     * collaboratore non potrebbe più correggere il nome di un altro collaboratore. È la lezione
     * della beta.41: la condizione non è «lo stato è X» ma «la scelta è cambiata».
     */
    protected function regolaRuoloConcedibile(?User $bersaglio = null): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            $attore = Auth::user();

            if (! $attore instanceof User) {
                return $fail(__('validation.custom.roles.not_allowed'));
            }

            $ruolo = $this->risolviRuolo($value);

            // Un ruolo che non esiste non è «niente da vietare»: senza questo, `syncRoles()`
            // solleverebbe `RoleDoesNotExist` e il controller lo trasformerebbe in un messaggio
            // di errore generico — un fallimento travestito da risposta.
            if (! $ruolo) {
                return $fail(__('validation.custom.roles.not_allowed'));
            }

            if ($bersaglio instanceof User && $bersaglio->hasRole($ruolo->name)) {
                return; // non si sta concedendo niente: il ruolo è già suo
            }

            $privilegiato = in_array($ruolo->name, $this->ruoliPrivilegiati(), true);

            if ($privilegiato && ! $attore->hasRole(RoleEnum::AMMINISTRATORE->value)) {
                $fail(__('validation.custom.roles.not_allowed'));
            }
        };
    }

    /**
     * Regola sul campo `roles` in modifica: l'ultimo amministratore attivo non si degrada.
     *
     * È la terza via per restare senza amministratori, dopo la sospensione e l'eliminazione, e
     * l'unica che non passa da una policy: il ruolo nuovo sta nel payload, non nell'istanza.
     */
    protected function regolaUltimoAmministratoreNonDegradabile(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $bersaglio instanceof User || ! $bersaglio->isUltimoAmministratoreAttivo()) {
                return;
            }

            $ruolo = $this->risolviRuolo($value);

            if ($ruolo && $ruolo->name !== RoleEnum::AMMINISTRATORE->value) {
                $fail(__('validation.custom.roles.last_admin'));
            }
        };
    }

    /**
     * Regola sul campo `permissions`: si guardano i permessi **aggiunti**, non quelli conservati.
     *
     * Stessa ragione del ruolo: se il bersaglio ha già un permesso diretto, rimandarlo non è una
     * concessione. Quelli che l'attore non può gestire non viaggiano nemmeno nel modulo — li
     * conserva `UserService::updateUser`, che non deve lasciarli cadere.
     */
    protected function regolaPermessiConcedibili(?User $bersaglio = null): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            $attore = Auth::user();

            if (! $attore instanceof User) {
                return $fail(__('validation.custom.permissions.not_allowed'));
            }

            $giaSuoi = $bersaglio instanceof User
                ? $bersaglio->getDirectPermissions()->pluck('name')->all()
                : [];

            foreach ((array) $value as $singolo) {
                $permesso = $this->risolviPermesso($singolo);

                if (! $permesso) {
                    return $fail(__('validation.custom.permissions.not_allowed'));
                }

                if (in_array($permesso->name, $giaSuoi, true)) {
                    continue;
                }

                if (! $attore->hasPermissionTo($permesso->name)) {
                    return $fail(__('validation.custom.permissions.not_allowed'));
                }
            }
        };
    }
}
