<?php

namespace App\Traits;

use App\Enums\Role as RoleEnum;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Chi può concedere quale ruolo, e quale permesso; e chi può toccare chi.
 *
 * ## Perché esiste
 *
 * Fino alla beta.55 `roles` era validato con un `['required']` nudo e la pagina di modifica
 * riceveva l'elenco **completo** dei ruoli: chi aveva `EDIT_USERS` — cioè ogni collaboratore —
 * si assegnava «amministratore» scegliendolo dalla tendina. Tre clic, nessuna richiesta costruita
 * a mano.
 *
 * Le regole, decise il 16/08/2026 e semplificate con la decisione 134.1 (Coda 258):
 *
 * 1. chi non è amministratore concede solo i ruoli `utente` e `fornitore`, che è il suo mestiere:
 *    creare le utenze dei condòmini e dei fornitori. Ogni altro ruolo — `amministratore`,
 *    `collaboratore`, un ruolo su misura — lo concede solo un amministratore;
 * 2. **nessuno concede un permesso che non possiede**. Vale anche per l'amministratore, che li ha
 *    tutti e quindi non se ne accorge;
 * 3. chi non è amministratore tocca solo gli account nella sua portata (`User::haNellaPortata()`).
 *
 * Le regole stanno qui e non nei due controller perché la stessa forma si ripete in creazione e in
 * modifica: correggerne una sola avrebbe lasciato viva l'altra — è la seconda lezione della
 * beta.54, la correzione va nel punto condiviso.
 */
trait ValidaConcessioneRuoli
{
    /**
     * Risolve un ruolo dal valore che arriva dal modulo.
     *
     * ⚠️ **Il modulo manda un id, ma `syncRoles()` accetta anche i nomi.** Una regola che
     * risolvesse solo per id lascerebbe passare la stringa «amministratore» — la validazione non
     * troverebbe nulla da vietare e Spatie, subito dopo, la onorerebbe. È il reperto della
     * revisione avversariale di questa beta, ed è la stessa lezione della beta.53: una regola su
     * un dato che ha due forme va scritta su **entrambe**.
     *
     * Risolve un valore solo; un elenco lo scompone `ruoliMandati()`, e qui non è un ruolo.
     */
    protected function risolviRuolo($valore): ?Role
    {
        if ($valore === null || $valore === '' || is_array($valore)) {
            return null;
        }

        return is_numeric($valore)
            ? Role::find((int) $valore)
            : Role::where('name', $valore)->first();
    }

    /**
     * I ruoli mandati dal modulo, uno per uno, risolti; `null` per un valore che non è un ruolo.
     *
     * La pagina manda un id solo, ma `assignRole()` e `syncRoles()` accettano anche un elenco, e prima
     * di assegnarlo lo **appiattiscono** (`collect($ruoli)->flatten()`, in `HasRoles::collectRoles`):
     * ogni valore, a qualunque profondità, diventa un ruolo assegnato. Le regole lo scompongono allo
     * stesso modo e li guardano tutti (Coda 258, decisione 130.1): ogni ruolo assegnato passa dalla
     * regola.
     *
     * @return array<int, Role|null>
     */
    protected function ruoliMandati($valore): array
    {
        return collect(is_array($valore) ? $valore : [$valore])
            ->flatten()
            ->map(fn ($singolo) => $this->risolviRuolo($singolo))
            ->values()
            ->all();
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
     * Regola sul campo `roles`: chi non è amministratore concede solo `utente` e `fornitore`.
     *
     * Vieta di **concedere** un altro ruolo, non di conservarlo: se il ruolo è quello che il bersaglio
     * ha già, salvare la scheda non concede niente e deve funzionare — altrimenti un collaboratore non
     * potrebbe più correggere il nome di un altro collaboratore. È la lezione della beta.41: la
     * condizione non è «lo stato è X» ma «la scelta è cambiata».
     *
     * Vale per **ogni** ruolo mandato (`ruoliMandati()`): con un elenco si assegnano tutti.
     */
    protected function regolaRuoloConcedibile(?User $bersaglio = null): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            $attore = Auth::user();

            if (! $attore instanceof User) {
                return $fail(__('validation.custom.roles.not_allowed'));
            }

            $ruoli = $this->ruoliMandati($value);

            // Un ruolo che non esiste non è «niente da vietare»: senza questo, `syncRoles()`
            // solleverebbe `RoleDoesNotExist` e il controller lo trasformerebbe in un messaggio
            // di errore generico — un fallimento travestito da risposta. Un elenco vuoto toglierebbe
            // ogni ruolo.
            if ($ruoli === [] || in_array(null, $ruoli, true)) {
                return $fail(__('validation.custom.roles.not_allowed'));
            }

            foreach ($ruoli as $ruolo) {
                if ($bersaglio instanceof User && $bersaglio->hasRole($ruolo->name)) {
                    continue; // non si sta concedendo niente: il ruolo è già suo
                }

                $delPortale = in_array($ruolo->name, RoleEnum::concedibiliDaTutti(), true);

                if (! $delPortale && ! $attore->hasRole(RoleEnum::AMMINISTRATORE->value)) {
                    return $fail(__('validation.custom.roles.not_allowed'));
                }
            }
        };
    }

    /**
     * Regola sul campo `roles` in modifica: l'ultimo amministratore attivo non si degrada.
     *
     * È la terza via per restare senza amministratori, dopo la sospensione e l'eliminazione, e
     * l'unica che non passa da una policy: il ruolo nuovo sta nel payload, non nell'istanza.
     *
     * Con un elenco conta l'elenco intero: resta amministratore se «amministratore» è fra i ruoli
     * mandati, in qualunque posizione. I valori che non sono ruoli li rifiuta `regolaRuoloConcedibile`.
     */
    protected function regolaUltimoAmministratoreNonDegradabile(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $bersaglio instanceof User || ! $bersaglio->isUltimoAmministratoreAttivo()) {
                return;
            }

            $ruoli = array_filter($this->ruoliMandati($value));

            $restaAmministratore = collect($ruoli)
                ->contains(fn (Role $ruolo) => $ruolo->name === RoleEnum::AMMINISTRATORE->value);

            if ($ruoli !== [] && ! $restaAmministratore) {
                $fail(__('validation.custom.roles.last_admin'));
            }
        };
    }

    /**
     * Il bersaglio è fuori dalla portata di chi agisce?
     *
     * La regola è una sola, `User::haNellaPortata()` (Coda 258, decisione 134.1): l'amministratore agisce
     * su tutti; chi non è amministratore solo su un account con i soli ruoli `utente` e `fornitore` e
     * senza permessi diretti che lui non ha. La usano tutte le porte che toccano un altro account: le
     * regole qui sotto sulla scheda (email, ruoli, permessi diretti), «reinvia» (`UserReinviteController`),
     * la verifica dell'email (`UserVerifyController`), la revoca di un permesso
     * (`RevokePermissionFromUserController`), sospendere, riattivare ed eliminare (`UserPolicy`).
     *
     * Chi agisce è l'utente collegato; la policy lo passa, perché riceve l'utente da autorizzare come
     * argomento. Senza nessuno che agisce, il bersaglio è fuori portata.
     */
    protected function bersaglioFuoriPortata(?User $bersaglio, ?User $attore = null): bool
    {
        $attore ??= Auth::user();

        if (! $bersaglio instanceof User) {
            return false;
        }

        return ! ($attore instanceof User && $attore->haNellaPortata($bersaglio));
    }

    /**
     * La richiesta concede un ruolo diverso da `utente` e `fornitore`, cioè un ruolo che il bersaglio non ha
     * ancora? Con `$bersaglio` nullo (un utente nuovo) ogni ruolo è concesso.
     *
     * È l'azione che chiede la password ridigitata da poco (Coda 261, decisione 134.1): concedere un ruolo
     * che dà poteri sull'installazione. Conservare un ruolo, o toglierlo, non concede niente. I ruoli si
     * risolvono come li risolve `syncRoles()`, uno o un elenco (`ruoliMandati()`).
     */
    protected function concedeUnRuoloOltreIlPortale(?User $bersaglio, $valore): bool
    {
        return collect($this->ruoliMandati($valore))
            ->filter()
            ->contains(fn (Role $ruolo) => ! in_array($ruolo->name, RoleEnum::concedibiliDaTutti(), true)
                && ! ($bersaglio instanceof User && $bersaglio->hasRole($ruolo->name)));
    }

    /**
     * La richiesta concede un permesso aggiuntivo che il bersaglio non ha già come permesso diretto, fuori da quelli
     * sui propri contenuti? Con `$bersaglio` nullo (un utente nuovo) ogni permesso mandato è concesso.
     *
     * Chiede la password ridigitata da poco come concedere un ruolo (Coda 261): con «Accesso pannello
     * amministratore», «Modifica utenti» o «Gestisci impostazioni generali» dati a un condòmino si ottiene ciò che
     * dà un ruolo, senza il ruolo. Rimandare i permessi che ha, o toglierne, non concede niente; quelli sui propri
     * contenuti (`User::permessiSulProprioContenuto()`) non danno poteri su nessun altro. L'elenco si scompone come
     * nelle regole (`regolaPermessiConcedibili`): un elemento con id e nome conta per tutti e due.
     */
    protected function concedeUnPermessoAggiuntivo(?User $bersaglio, $valore): bool
    {
        $giaSuoi = $bersaglio instanceof User
            ? $bersaglio->getDirectPermissions()->pluck('name')->all()
            : [];

        return collect((array) $valore)
            ->flatten()
            ->map(fn ($singolo) => $this->risolviPermesso($singolo)?->name)
            ->filter()
            ->contains(fn (string $nome) => ! in_array($nome, $giaSuoi, true)
                && ! in_array($nome, User::permessiSulProprioContenuto(), true));
    }

    /**
     * La richiesta cambia l'email di un account che non ha soltanto i ruoli `utente` e `fornitore`, o che ha un
     * permesso aggiuntivo fuori da quelli sui propri contenuti?
     *
     * Chiede la password ridigitata da poco (Coda 261): l'email è la chiave dell'account (riceve i link di «password
     * dimenticata»), e l'account porta i poteri che il suo ruolo o i suoi permessi aggiuntivi gli danno. È la stessa
     * linea della concessione (`concedeUnPermessoAggiuntivo()`): un condòmino con «Accesso pannello amministratore»
     * o «Modifica utenti» ha i poteri di un ruolo. L'email di un condòmino o di un fornitore senza altri permessi
     * (o con i soli permessi sui propri contenuti) si corregge senza password, come la loro scheda. Si guardano il
     * ruolo e i permessi di adesso, non quelli mandati.
     */
    protected function cambiaLEmailDiChiHaPoteri(User $bersaglio, $email): bool
    {
        if ($email === null || $email === $bersaglio->email) {
            return false;
        }

        return $bersaglio->getRoleNames()->diff(RoleEnum::concedibiliDaTutti())->isNotEmpty()
            || $bersaglio->getDirectPermissions()->pluck('name')->diff(User::permessiSulProprioContenuto())->isNotEmpty();
    }

    /**
     * Regola sul campo `email` in modifica: l'email di un account fuori portata la cambia solo un
     * amministratore.
     *
     * La condizione è «l'email cambia», non «il bersaglio è fuori portata»: la pagina manda la scheda
     * intera, e un collaboratore deve poter correggere il nome di un collega rimandando la sua email
     * com'è (la lezione della beta.41, la stessa di `regolaRuoloConcedibile`).
     */
    protected function regolaEmailFuoriPortata(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $this->bersaglioFuoriPortata($bersaglio) || $value === $bersaglio->email) {
                return;
            }

            $fail(__('validation.custom.email.privileged_target'));
        };
    }

    /**
     * Regola sul campo `email` in modifica: sulla scheda di sé stessi l'email non si cambia, per nessuno.
     *
     * La propria email si cambia dal profilo, che chiede la password attuale (Coda 261): l'email è la
     * chiave dell'account, e chi ha soltanto la sessione non la sposta su una casella sua. La scheda
     * utenti segue la stessa regola, per l'amministratore e per chi ha «Modifica utenti» da un ruolo su
     * misura: lì la propria email non si cambia. Il resto della propria scheda si salva com'è: la condizione è
     * «l'email cambia», perché la pagina manda la scheda intera.
     */
    protected function regolaEmailDiSeStessi(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $bersaglio instanceof User || ! $bersaglio->is(Auth::user()) || $value === $bersaglio->email) {
                return;
            }

            $fail(__('validation.custom.email.own_from_profile'));
        };
    }

    /**
     * Regola sul campo `roles` in modifica: i ruoli di un account fuori portata li cambia solo un
     * amministratore — né concederne, né toglierne.
     *
     * `regolaRuoloConcedibile` guarda solo i ruoli concessi, e `regolaUltimoAmministratoreNonDegradabile`
     * solo l'ultimo amministratore: questa guarda anche i ruoli tolti, per ogni account fuori dalla
     * portata di chi salva. Si confronta l'insieme dei ruoli mandati con quello di adesso,
     * risolti come li risolve `syncRoles()` — per id o per nome, uno o un elenco, anche annidato
     * (`ruoliMandati()`) — perché un insieme diverso, in qualunque forma arrivi, è un cambio.
     */
    protected function regolaRuoliFuoriPortata(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $this->bersaglioFuoriPortata($bersaglio)) {
                return;
            }

            $mandati = collect($this->ruoliMandati($value))
                ->map(fn (?Role $ruolo) => $ruolo?->name);

            $attuali = $bersaglio->getRoleNames()->unique()->sort()->values()->all();

            if ($mandati->contains(null) || $mandati->unique()->sort()->values()->all() !== $attuali) {
                $fail(__('validation.custom.roles.privileged_target'));
            }
        };
    }

    /**
     * Regola sul campo `permissions` in modifica: i permessi diretti di un account fuori portata li cambia
     * solo un amministratore — né aggiungerne, né toglierne.
     *
     * La scheda manda l'elenco intero, e `UserService::permessiDaSincronizzare()` lo scrive al posto di
     * quello di adesso, conservando i permessi che chi salva non può vedere: togliere dalla tendina un
     * permesso che anche lui ha è la stessa revoca che `RevokePermissionFromUserController` rifiuta. Si
     * confronta l'insieme che il salvataggio scriverebbe — calcolato dalla stessa funzione che lo scrive —
     * con quello di adesso: la scheda aperta e mandata senza toccare niente passa.
     */
    protected function regolaPermessiFuoriPortata(?User $bersaglio): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) use ($bersaglio) {
            if (! $this->bersaglioFuoriPortata($bersaglio)) {
                return;
            }

            $dopo = collect(app(UserService::class)->permessiDaSincronizzare($bersaglio, (array) $value))
                ->unique()->sort()->values()->all();

            $adesso = $bersaglio->getDirectPermissions()->pluck('name')->unique()->sort()->values()->all();

            if ($dopo !== $adesso) {
                $fail(__('validation.custom.permissions.privileged_target'));
            }
        };
    }

    /**
     * Regola sul campo `permissions`: si guardano i permessi **aggiunti**, non quelli conservati.
     *
     * Stessa ragione del ruolo: se il bersaglio ha già un permesso diretto, rimandarlo non è una
     * concessione. Quelli che l'attore non può gestire non viaggiano nemmeno nel modulo — li
     * conserva `UserService::updateUser`, che non deve lasciarli cadere.
     *
     * L'elenco si appiattisce come lo appiattisce `givePermissionTo()` in creazione: un elemento che
     * porta un id e un nome concede tutti e due, e la regola li guarda tutti e due (Coda 258).
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

            foreach (collect((array) $value)->flatten()->all() as $singolo) {
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
