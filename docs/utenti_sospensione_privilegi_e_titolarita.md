# Utenti: sospensione, privilegi e titolarità — accertamento e progetto

<!-- verifica-documentazione -->
> **Stato: ⚠️ IN GRAN PARTE IMPLEMENTATO — la beta.55 ha chiuso §1, §2, §3, §3-bis, §4 e §4.5, più
> il §6 (`last_login_at`). Restano progetto il §5 (trasferimento amministratore) e il registro degli
> accessi con ip, rimandato alla v1.19.**
>
> *Accertamenti verificati sul codice il 16/08/2026 su `1.10.0-beta.53` (commit `43eadd1a`) e chiusi
> nella `1.10.0-beta.55` dello stesso giorno.* Le sezioni che descrivono i difetti restano al passato
> di proposito: raccontano **come ci si è arrivati**, e quella storia vale più della versione pulita
> — è la regola già usata per le rettifiche di `architettura_saldi_iniziali`.
>
> Il documento è diviso in due metà che vanno lette con occhi diversi:
>
> - **§1–§3 sono accertamenti.** Ogni riga è stata verificata sul codice alla data di cui sopra, con
>   file e numero di riga. Se il codice cambia, queste sezioni vanno riverificate — non riscritte a
>   memoria.
> - **§4–§8 erano progetto quando sono stati scritti**, e la beta.55 ne ha implementata la maggior
>   parte nello stesso giorno: quando leggi «non esiste», leggilo come «non esisteva la mattina del
>   16/08/2026». Fa eccezione il **§4.5, che era già un accertamento** (l'aggiornamento non
>   esegue il seeder dei permessi, e cosa questo rompe oggi). Del resto non esiste niente: non
>   esistono il permesso `Sospendi utenti`, le invarianti sull'ultimo amministratore, la schermata di
>   trasferimento titolarità, la colonna `last_login_at`, la tabella di log degli accessi. I nomi
>   usati sono proposte, non riferimenti.
>
> **Origine.** Segnalazione di Vincenzo del 16/08/2026: «sospendo un utente amministratore e continua
> ad accedere». L'indagine ha confermato il difetto e ne ha trovati altri tre nello stesso terreno,
> uno dei quali (§2) più grave di quello di partenza.
>
> **Ampliato lo stesso giorno**, su due domande successive di Vincenzo: «siamo sicuri che il seeder
> non giri in aggiornamento?» (→ §4.4, quattro riscontri indipendenti) e «come abbiamo fatto a
> dimenticarlo, c'era un motivo?» (→ §4.5, indagine sulle cause e su cosa è rotto oggi).
> **Il §4.5.3 corregge un'affermazione sbagliata della prima stesura** di questo stesso documento.
>
> **Suite completa verde** alla data dell'accertamento: 1306 test passati, 2 saltati (§7.3).
>
> **Esito.** La beta.55 — «Le Porte Che Nessuno Aveva Contato» — ha portato in codice tutto ciò che
> qui era progetto tranne il §5. Otto punti di autorizzazione senza guardia in totale: i tre di §2 e
> §3 più i cinque trovati dalla revisione (§3-bis). Suite alla chiusura: **1362 test PHP** (2
> saltati) e **156 vitest**, contro i 1306 di partenza.
<!-- /verifica-documentazione -->

---

## Sommario

| § | Cosa | Natura | Gravità |
| :--- | :--- | :--- | :--- |
| §1 | La sospensione non ha alcun effetto, per nessun ruolo | accertamento | alta |
| §2 | Chi ha «Modifica utenti» può promuoversi amministratore | accertamento | **critica** |
| §3 | Due rotte del gruppo utenti senza autorizzazione | accertamento | alta |
| §3-bis | Il giro completo: altri cinque punti senza guardia, e cosa è risultato pulito | accertamento | alta |
| §4 | Permesso dedicato alla sospensione + due invarianti | progetto | — |
| §4.5 | L'aggiornamento non esegue il seeder dei permessi: perché, e cosa è rotto oggi | accertamento | alta |
| §5 | Trasferimento amministratore | progetto | — |
| §6 | Ultimo accesso di ogni utente | progetto | — |
| §7 | Ordine di esecuzione e vincoli di rilascio | progetto | — |

---

## 1. Accertamento: la sospensione non sospende

### 1.1 Il ruolo amministratore non c'entra

Il sospetto iniziale era che il difetto riguardasse gli utenti con ruolo `amministratore`. Non è
così: **la sospensione non è applicata a nessuno**. Un condòmino sospeso entra esattamente come
l'amministratore sospeso. La controprova è immediata — sospendere un utente con ruolo `utente` e
riprovare l'accesso.

### 1.2 Il middleware esiste, è scritto bene, e non è agganciato a niente

[`CheckSuspendedUser.php`](../app/Http/Middleware/CheckSuspendedUser.php) fa la cosa giusta: verifica
`Auth::check()` e `$user->suspended()` (`:25`), esegue il logout, invalida la sessione, rigenera il
token CSRF e restituisce 403 con il messaggio `errors.403.account_suspended`.

È registrato come alias in [`bootstrap/app.php:58`](../bootstrap/app.php:58).

**Non compare in nessuna rotta.** Misurato sull'elenco reale, non sui file:

```bash
php artisan route:list --json | grep -c "CheckSuspendedUser"
```

Restituisce `0`, su 439 rotte registrate.

**Come ci si è arrivati.** Il middleware nasce nel commit `54509a47` (05/04/2025, «Modifiche alla
gestione degli utenti») e viene applicato a **una sola rotta**, `dashboard`:

```php
Route::get('dashboard', fn () => Inertia::render('Dashboard'))
    ->middleware(['auth', 'verified', 'CheckSuspendedUser'])->name('dashboard');
```

Nel commit `817de2f0` quella rotta viene commentata, perché sostituita da `admin.dashboard` e
`user.dashboard`. Il middleware resta orfano. I gruppi che hanno preso il posto della vecchia rotta —
[`routes/admin.php:30`](../routes/admin.php:30), [`routes/user.php:15`](../routes/user.php:15),
[`routes/gestionale.php:53`](../routes/gestionale.php:53) e i gruppi di
[`routes/web.php`](../routes/web.php) — montano `auth`, `verified`, `role_or_permission`, ma non lui.

Non è stata una rimozione: è una perdita per spostamento. È il tipo di regressione che solo un test
può fermare, e infatti (§7) test non ce n'erano.

### 1.3 Nemmeno il login guarda `suspended_at`

Tre percorsi portano dentro, e nessuno dei tre controlla lo stato dell'utente:

| Percorso | Punto | Cosa fa |
| :--- | :--- | :--- |
| Login normale | [`LoginRequest.php:44`](../app/Http/Requests/Auth/LoginRequest.php:44) | `Auth::attempt()` sulle sole credenziali |
| Login normale | [`AuthenticatedSessionController.php:51`](../app/Http/Controllers/Auth/AuthenticatedSessionController.php:51) | chiama `authenticate()` e rigenera la sessione, senza verifiche |
| 2FA | [`TwoFactorAuthChallengeController.php:63`](../app/Http/Controllers/Auth/TwoFactorAuthChallengeController.php:63) e `:97` | `CompleteTwoFactorAuthentication` → `Auth::login()` |

Verificato inoltre che **non esiste** nessun'altra difesa nascosta: nessun listener sugli eventi
`Attempting`/`Login`, nessun user provider personalizzato (`config/auth.php` usa il provider Eloquent
standard), nessun controllo nelle policy.

> ⚠️ Il ramo 2FA **salta interamente** `LoginRequest::authenticate()`. Un controllo messo solo lì
> lascerebbe scoperti tutti gli utenti con la doppia autenticazione attiva — cioè, prevedibilmente,
> proprio gli amministratori.

Conclusione: oggi `suspended_at` è **soltanto un'etichetta rossa** nell'elenco utenti
([`resources/js/components/users/columns.ts:198`](../resources/js/components/users/columns.ts:198)) e l'interruttore che cambia la
voce del menu contestuale ([`resources/js/components/users/DataTableRowActions.vue:93`](../resources/js/components/users/DataTableRowActions.vue:93)).

### 1.4 Il secondo strato: sospendere non chiude le sessioni aperte

[`UserStatusController.php:32`](../app/Http/Controllers/Users/UserStatusController.php:32) scrive la
colonna e nient'altro.

Anche una volta chiuso il login, **l'utente già collegato resterebbe dentro** fino alla scadenza della
sessione (`SESSION_LIFETIME=120` minuti). Ed è il caso più frequente nella pratica: si sospende
qualcuno *mentre* sta lavorando, non prima che arrivi.

Servono quindi **due** cose, non una:

1. il rifiuto all'accesso (messaggio chiaro a chi tenta di entrare);
2. il controllo a ogni richiesta autenticata, che chiude le sessioni già aperte.

Il punto 2 è esattamente ciò che `CheckSuspendedUser` già fa — logout più invalidazione, non un
semplice diniego. Il codice giusto c'è: manca l'aggancio.

**Dove agganciarlo.** Rotta per rotta è ciò che ha prodotto la perdita del 2025. Il punto unico è
`$middleware->web(append: [...])` in [`bootstrap/app.php:49`](../bootstrap/app.php:49): la guardia
`Auth::check()` interna lo rende innocuo sulle rotte per ospiti e sull'installer.

**Un'avvertenza sull'interfaccia.** Il middleware restituisce una view HTML 403. Su una richiesta
Inertia (XHR) questo produce il modale d'errore a tutta pagina, non un ritorno pulito al login. Un
`redirect()->route('login')` con messaggio flash si comporta meglio nel contesto Inertia — è una
modifica al middleware, non allo schema.

---

## 2. Accertamento: chi può modificare gli utenti può farsi amministratore

**Questa è la voce più grave del documento, ed è emersa mentre si guardava altro.**

I fatti, in fila:

| Punto | Cosa |
| :--- | :--- |
| [`Role.php:48`](../app/Enums/Role.php:48) | `COLLABORATORE` possiede `CREATE_USERS`, `EDIT_USERS`, `VIEW_USERS` |
| [`UpdateUserRequest.php:30`](../app/Http/Requests/User/UpdateUserRequest.php:30) | `'roles' => ['required']` — nessun `Rule::in`, nessun controllo su chi concede cosa |
| [`UserService.php:64-65`](../app/Services/UserService.php:64) | `syncRoles()` e `syncPermissions()` prendono i valori direttamente dalla richiesta |
| [`UserController.php:229-230`](../app/Http/Controllers/Users/UserController.php:229) | la pagina di modifica riceve **tutti** i ruoli e **tutti** i permessi esistenti |
| [`resources/js/pages/utenti/ModificaUtente.vue:176`](../resources/js/pages/utenti/ModificaUtente.vue:176) | `:options="roles"`, senza filtri |

Un collaboratore apre la propria scheda utente, sceglie «amministratore» dalla tendina, salva. **Tre
clic nell'interfaccia**, nessuna richiesta costruita a mano. In alternativa può assegnarsi i singoli
permessi: il filtro `selectablePermissions` ([`resources/js/pages/utenti/ModificaUtente.vue:64`](../resources/js/pages/utenti/ModificaUtente.vue:64))
toglie dall'elenco solo i permessi già ereditati o già selezionati, non quelli che l'attore non
potrebbe concedere.

La stessa forma vale in creazione: `CREATE_USERS` più ruolo libero in
[`CreateUserRequest.php:31`](../app/Http/Requests/User/CreateUserRequest.php:31), identico
(`'roles' => ['required']`).

> ➕ **Terza porta, trovata il 16/08/2026 durante la beta.55, da una domanda di Vincenzo** — *«forse
> è troppo restrittivo? abbiamo comunque i permessi che si possono usare»*. Chiudere l'assegnazione
> dei **ruoli privilegiati** e la concessione dei **permessi diretti** non basta: `RolePolicy`
> lascia **creare ruoli** anche al collaboratore, e un ruolo creato a mano non è privilegiato per
> definizione. Il giro completo era: creo il ruolo «su misura» con dentro `Elimina utenti`, me lo
> assegno — non è nell'elenco dei privilegiati, quindi passa — e mi ritrovo il permesso che la
> regola mi negava. Verificato con due test che prima fallivano. **Chiudere due porte su tre non
> chiude niente:** la stessa regola «non concedi ciò che non hai» va applicata anche alla
> composizione del ruolo, in `CreateRuoloRequest` e `UpdateRuoloRequest`.

**Perché viene prima di tutto il resto.** Qualunque permesso fine — compreso il `Sospendi utenti` del
§4 — è aggirabile finché questo buco è aperto: chi volesse sospendere l'amministratore non
attaccherebbe la rotta `suspend`, si assegnerebbe il ruolo. Chiudere prima i permessi fini e poi
l'assegnazione dei ruoli significa costruire una porta blindata accanto a una finestra aperta.

---

## 3. Accertamento: due rotte del gruppo utenti senza autorizzazione

Stack effettivo, letto da `php artisan route:list --path=utenti`:

```
POST   utenti/reinvite/{email}   | web
PUT    utenti/{user}/suspend     | web, Authenticate, EnsureEmailIsVerified
PUT    utenti/{user}/unsuspend   | web, Authenticate, EnsureEmailIsVerified
```

**(a) `suspend` / `unsuspend` non autorizzano.**
[`routes/web.php:37-43`](../routes/web.php:37) monta solo `auth` e `verified`, e
[`UserStatusController`](../app/Http/Controllers/Users/UserStatusController.php) non contiene alcun
`Gate::authorize`. È l'unico controller della famiglia a non averlo:
[`UserController`](../app/Http/Controllers/Users/UserController.php:85) autorizza su tutte e cinque
le azioni, [`UserVerifyController:23`](../app/Http/Controllers/Users/UserVerifyController.php:23)
autorizza.

Conseguenza: **qualsiasi utente autenticato e con email verificata** — un condòmino qualunque — può
sospendere chiunque, amministratori inclusi, con una `PUT /utenti/{id}/suspend`. Oggi non ha
conseguenze perché la sospensione non fa nulla (§1). **Il giorno in cui §1 viene chiuso, questa
diventa il modo per buttare fuori l'amministratore**: le due correzioni vanno insieme o l'ordine
sbagliato peggiora la situazione invece di migliorarla.

**(b) `utenti/reinvite/{email}` è fuori da `auth`.**
[`routes/web.php:45-46`](../routes/web.php:45) non monta nessun middleware oltre al gruppo `web`. E
[`UserReinviteController.php:38`](../app/Http/Controllers/Users/UserReinviteController.php:38) esegue
`$user->update(['password' => null])` prima di inviare l'email.

Chiunque conosca un'email registrata può quindi azzerare la password di quell'account e generare un
invio. **Non consente di entrare** (`Hash::check` contro `null` fallisce), ma disattiva l'account e
permette l'invio ripetuto di email a un indirizzo scelto. Il token CSRF impedisce l'attacco
cross-site, non uno script che prima si prende il token dalla pagina di login.

---

## 3-bis. Accertamento: il giro completo sulla superficie dei poteri

*Fatto il 16/08/2026 durante la beta.55, su domanda di Vincenzo — «ci sono altri buchi da
trovare?». Il metodo: invece di cercare ancora modi di **prendersi** poteri, rovesciare la domanda
in «e per **toglierli**?», e poi passare in rassegna ogni rotta che tocca utenti, ruoli, permessi,
inviti e doppia autenticazione. Tre reperti in un'ora, più uno nel giro precedente.*

| Superficie | Verdetto |
| :--- | :--- |
| `RevokePermissionFromRoleController` | ❌ **nessuna autorizzazione** — un condòmino poteva svuotare il ruolo `amministratore` un permesso alla volta |
| `RevokePermissionFromUserController` | ❌ **nessuna autorizzazione** — chiunque poteva togliere permessi diretti a chiunque |
| `UserVerifyController` | ❌ un collaboratore poteva togliere la verifica email a un amministratore, che con il middleware `verified` equivale a chiuderlo fuori dal programma. In più il `Gate::authorize` stava **dentro** il `try/catch` |
| `CreateRuoloRequest` / `UpdateRuoloRequest` | ❌ si poteva comporre un ruolo su misura con permessi non posseduti, e poi indossarlo |
| `InvitoController` (index, create, store, destroy) | ❌ **nessuna autorizzazione** — leggere gli indirizzi degli invitati, spedire inviti con il nostro dominio, cancellare inviti altrui |
| `TwoFactorAuthController` | ✅ **pulito** — tutti i metodi operano su `$request->user()`, e nessuna rotta accetta un utente diverso: la doppia autenticazione di un altro non è raggiungibile |
| Registrazione via invito | ✅ il ruolo è quello predefinito delle impostazioni; la tabella `inviti` non ha una colonna per il ruolo, quindi l'invito non può portarne uno |
| `default_user_role` nelle impostazioni | ✅ modificabile solo con `MANAGE_GENERAL_SETTINGS`, che ha il solo amministratore |

**La forma che accomuna i cinque reperti**, e che vale oltre questa superficie: *un'azione di
governo scritta in un controller dedicato, fuori dal controller principale della sua area, eredita
le rotte ma non le guardie.* `RoleController` protegge i ruoli di sistema; il controller che revoca
un permesso a un ruolo — file diverso, stessa materia — non ne sapeva niente. È il difetto della
beta.44 (la stessa domanda con due risposte in due posti) applicato all'autorizzazione invece che
a una soglia.

**Il corollario per la revisione**: quando si mette una guardia su un'azione, cercare **tutti i
punti che compiono quell'azione**, non solo quello da cui si è arrivati. Il grep non è sul nome del
controller ma sul verbo: `revokePermissionTo`, `syncPermissions`, `assignRole`, `email_verified_at`.

---

## 4. Progetto: permesso dedicato e le due invarianti

> Nulla di questa sezione esiste.

### 4.1 Il permesso

Un permesso nuovo nell'enum, sul modello degli altri:

```php
case SUSPEND_USERS = 'Sospendi utenti';
```

assegnato in `Role::permissions()` al solo `AMMINISTRATORE`, più il `Gate::authorize` oggi assente in
`UserStatusController`.

**Perché non riusare `EDIT_USERS`.** Perché il collaboratore ce l'ha per mestiere — creare e
correggere le schede dei condòmini è lavoro suo — e agganciarci la sospensione gli darebbe il potere
di sospendere l'amministratore. La sospensione non è una modifica anagrafica: è un atto di
governo dell'installazione.

### 4.2 Le due invarianti — che non sono permessi

Un permesso risponde a «questa persona può fare questa cosa?». Qui servono due risposte che nessun
permesso sa dare:

1. **nessuno può sospendere, eliminare o degradare sé stesso**;
2. **non si può sospendere, eliminare o degradare l'ultimo amministratore attivo**.

La seconda oggi non esiste da nessuna parte:
[`UserController.php:296`](../app/Http/Controllers/Users/UserController.php:296) cancella chiunque
senza guardare chi resta, e `syncRoles` può togliere il ruolo all'unico amministratore. **Uno studio
può ritrovarsi con un'installazione priva di amministratori**, non recuperabile dall'interfaccia:
si esce solo da `tinker` o da SQL. È un difetto presente **oggi**, indipendente dalla sospensione.

Il precedente da imitare è già in casa:
[`HasProtectedRoles.php`](../app/Traits/HasProtectedRoles.php) impedisce di rinominare o cancellare i
quattro ruoli di sistema, ed espone `is_protected` alla risorsa perché il frontend possa spiegare il
divieto invece di limitarsi a fallire ([`DataTableRowActions.vue:25`](../resources/js/components/roles/DataTableRowActions.vue:25)).
Stesso schema, portato al livello dell'utente.

### 4.3 Il lavoro strutturale: le policy non conoscono il bersaglio

[`UserPolicy`](../app/Policies/UserPolicy.php) riceve **solo l'attore**: tutte le firme sono
`view(User $user)`, `update(User $user)`, `delete(User $user)`, e le chiamate sono
`Gate::authorize('update', User::class)` — sulla **classe**, non sull'istanza.

È il motivo tecnico per cui le invarianti non possono esistere: la policy non ha modo di dire «questo
utente sì, quell'altro no». Il passaggio a policy per istanza —
`update(User $actor, User $target)` — ricade su una decina di punti di chiamata in
`UserController`, `UserStatusController`, `UserVerifyController`. Non è enorme, ma non è una riga, e
va messo in conto quando si stima la voce.

### 4.4 Vincolo di rilascio: il seeder non gira in aggiornamento

*(Sezione riverificata il 16/08/2026 su richiesta di Vincenzo — «siamo sicuri?». Sì, e la prova più
solida non è nel codice ma nel changelog: vedi il punto 3.)*

**I fatti, in quattro riscontri indipendenti.**

1. **`db:seed` compare in un solo punto di tutto il progetto**: l'installer
   ([`InstallerWizard.php:276`](../app/Livewire/Installer/InstallerWizard.php:276) e `:283`). Fuori
   da lì, nessuna chiamata — né in `app/`, né nei file di deploy.
2. **Il percorso di aggiornamento non semina.** `SystemUpgradeController::run()`
   ([`:230`](../app/Http/Controllers/System/SystemUpgradeController.php:230)) chiama
   `SystemFinalizer::finalize()`, che fa esattamente quattro cose
   ([`SystemFinalizer.php:32-44`](../app/Services/System/SystemFinalizer.php:32)): migrazioni,
   riallineamento della versione, pulizia cache, `storage:link`. Lo stesso vale per il ripristino da
   backup ([`RestoreManager.php:409`](../app/Services/Restore/RestoreManager.php:409)).
3. **Il changelog lo dice già, e da tempo.** Nella `1.9.1-beta.8`, sotto «Importante»:
   > *«Dopo aver aggiornato il codice all'ultima versione e aver eseguito le migrazioni, è necessario
   > lanciare il comando `php artisan db:seed --class=RolesAndPermissionsSeeder` per generare a
   > database i nuovi permessi inseriti a sistema.»*

   Non è quindi una deduzione: è un problema **già incontrato**, e la soluzione adottata allora è
   stata una nota manuale nelle note di rilascio.
4. **Nessuna migrazione ha mai inserito un permesso.** Le uniche che toccano quelle tabelle sono le
   due di Spatie (`create_permission_tables`, `add_description_to_permissions_tables`) e
   `add_description_to_roles_table`.

**Perché stavolta la nota manuale non basta.** Due ragioni, la seconda è quella pesante.

- **Il pubblico.** L'aggiornamento in-app esiste proprio per chi non ha una shell: hosting condiviso,
  pannello, aggiornamento con un pulsante. A quell'utente la riga «lancia questo comando» non è
  applicabile, e il permesso resta assente.
- **Un permesso assente non nega: fa esplodere la pagina.** `hasPermissionTo()` con un nome che non
  esiste a database **non restituisce `false`**, lancia `PermissionDoesNotExist`. Verificato
  empiricamente sull'installazione corrente:

  ```
  Spatie\Permission\Exceptions\PermissionDoesNotExist ::
  There is no permission named `Permesso Che Non Esiste` for guard `web`.
  ```

  L'eccezione arriva da `Permission::findByName()` (`:109` del modello di Spatie) e **non è gestita**:
  il blocco `withExceptions` di [`bootstrap/app.php:80`](../bootstrap/app.php:80) mappa solo
  `InvalidSignatureException`. Risultato per chi non ha lanciato il comando: errore 500 sulla pagina
  utenti, non una funzione mancante. Ed è un difetto che si manifesta **dopo** l'aggiornamento, su
  installazioni altrui, cioè nel posto peggiore.

**Quindi:** migrazione idempotente, non nota nel changelog. Deve:

- creare il permesso se non esiste (`firstOrCreate` sul nome, con la descrizione);
- agganciarlo al ruolo `amministratore` se non già agganciato;
- invalidare la cache di Spatie (`PermissionRegistrar::forgetCachedPermissions()`);

con la riga nel dataset di `tests/Feature/System/UpgradeMigrationsRerunTest.php` e **la dichiarazione
in apertura del changelog**, perché tocca il database.

> 💡 **Ricaduta oltre questo documento.** Il difetto è generale e più largo di quanto sembri:
> riguarda **qualunque** modifica all'enum dei permessi *e* alla mappa `Role::permissions()`. È il
> tema del §4.5, che lo tratta a parte perché non appartiene più alla sospensione.

---

## 4.5 Accertamento a parte: il seeder mancante nell'aggiornamento

*(Indagine del 16/08/2026, nata dalla domanda di Vincenzo: «come abbiamo fatto a dimenticarlo? C'era
un motivo o è stata una dimenticanza?». Vale per tutto il prodotto, non per la sola sospensione.)*

### 4.5.1 Non è mai stato tolto: non c'è mai stato

`git log -S "db:seed" -- app/` restituisce due soli commit, entrambi sull'**installer** (`158e025a`
«Fix installer setup», `fc3d4164` «Fix installer to get ready for 1.9.0»). `git log -S
"RolesAndPermissionsSeeder" -- app/` non restituisce **nulla**: quel nome non è mai comparso nel
codice applicativo.

Non c'è stata quindi una rimozione consapevole da rimpiangere. Il percorso di aggiornamento è nato
come «scarica, scompatta, migra» e il seeder non è mai entrato nella lista.

### 4.5.2 Un motivo per non lanciare `db:seed` **c'è**, ed è buono

[`DatabaseSeeder`](../database/seeders/DatabaseSeeder.php) non contiene solo ruoli e permessi:

| Passo | Cosa fa in aggiornamento |
| :--- | :--- |
| `RolesAndPermissionsSeeder` | riallinea permessi e mappa ruoli → **è ciò che serve** |
| `UserSeeder` | protetto: solo se `run_installer` è falso **e** non esiste alcun amministratore |
| `CategoriaDocumentoSeeder`, `CategoriaEventoSeeder`, `TipologieImmobiliSeeder`, `CategoriaFornitoreSeeder` | `firstOrCreate` sul nome → **fanno risorgere le categorie che l'utente ha cancellato** |

L'ultima riga è il motivo vero: lanciare `db:seed` intero a ogni aggiornamento rimetterebbe in piedi
le tipologie e le categorie che l'amministratore ha eliminato di proposito. È un difetto peggiore di
quello che risolve, e la migrazione
`2026_08_15_090000_correggi_categoria_ufficio.php` esiste proprio perché un `firstOrCreate` in un
seeder **non** corregge i database già esistenti.

**Ma questo è un argomento contro `db:seed`, non contro `db:seed --class=RolesAndPermissionsSeeder`.**

### 4.5.3 L'obiezione che avevo scritto era sbagliata

Nella prima stesura di questo documento avevo annotato che eseguire il seeder in aggiornamento
avrebbe cancellato le personalizzazioni dei quattro ruoli di sistema, perché
`syncPermissions()` li riallinea all'enum. **È falso, e va corretto:** quei ruoli **non sono
modificabili dall'interfaccia**. [`RoleController::edit`](../app/Http/Controllers/Roles/RoleController.php:152)
e [`::update`](../app/Http/Controllers/Roles/RoleController.php:184) rimbalzano entrambi i ruoli
protetti con `cannot_edit_default_role`, e `::destroy` (`:241`) impedisce di cancellarli.

Non esiste quindi una personalizzazione da perdere: `syncPermissions` sui quattro ruoli di sistema
riallinea un dato che **solo il codice** può aver scritto. I permessi assegnati direttamente ai
singoli utenti e i ruoli creati a mano non vengono toccati dal seeder, che itera esclusivamente sui
casi dell'enum.

**Con l'obiezione caduta, resta un solo motivo per non farlo: nessuno l'ha messo in lista.**

### 4.5.4 Cosa è rotto oggi, davvero

La nota nel changelog della `1.9.1-beta.8` è indirizzata a **chi installa e aggiorna a mano** — è
scritta apposta per loro. Il buco è che l'aggiornamento dal pannello esiste proprio per chi *non* fa
quelle operazioni a mano, e a quel pubblico la nota non è applicabile.

Il contenuto di quella beta è più insidioso di quanto la nota lasci intendere. Il commit `f21ee063`
(06/06/2026) **non ha aggiunto permessi**: ha cambiato la **mappa dei ruoli**, aggiungendo tre
assegnazioni a `FORNITORE` e tre a `UTENTE`:

```
PUBLISH_COMMENTS_SEGNALAZIONI
EDIT_OWN_COMMENTS_SEGNALAZIONI
DELETE_OWN_COMMENTS_SEGNALAZIONI
```

`app/Enums/Permission.php` è fermo al 31/08/2025: quei permessi esistevano già a database. Quindi su
un'installazione aggiornata dal pannello e mai seminata **non c'è nessun errore 500** — c'è qualcosa
di peggio, il silenzio:

- i commenti di condòmini e fornitori sulle segnalazioni **vanno in moderazione invece di comparire
  subito** ([`CommentoService.php:35`](../app/Services/CommentoService.php:35));
- non possono **modificare né eliminare i propri commenti**
  ([`CommentoPolicy.php:48`](../app/Policies/CommentoPolicy.php:48)).

Cioè esattamente le tre righe che le note di rilascio della beta.8 annunciano come funzione nuova. Chi
ha aggiornato dal pannello ha letto la novità e non l'ha mai vista funzionare, senza un messaggio
d'errore che glielo spiegasse.

> **Nota di sollievo, e di scadenza.** L'enum dei permessi non cambia dal 31/08/2025: nessuna
> installazione ha oggi un permesso *mancante a database*, e quindi lo scenario del 500 del §4.4 non
> si è mai verificato. `SUSPEND_USERS` sarebbe **il primo permesso nuovo dopo un anno** — e il primo
> a poter innescare quello scenario.

### 4.5.5 Verdetto e rimedio

Non è una dimenticanza pura — il problema era noto e nel giugno 2026 è stato affrontato con una nota
di rilascio. Non è nemmeno una scelta motivata: la motivazione esiste per `db:seed` intero, non per la
chiamata mirata, e nessuno l'ha mai scritta da nessuna parte. **È un rimedio corretto ma indirizzato a
un pubblico più stretto di quello che ne aveva bisogno**, rimasto tale perché niente lo ha mai
rimesso in discussione.

Il rimedio ha due metà, e conviene farle entrambe:

1. **`SystemFinalizer::finalize()` esegue `db:seed --class=RolesAndPermissionsSeeder`** subito dopo le
   migrazioni — mirato, mai `db:seed` intero (§4.5.2), con la stessa tolleranza agli errori già usata
   per le migrazioni. Chiude il problema per tutte le modifiche future all'enum **e** alla mappa dei
   ruoli, che la migrazione mirata da sola non coprirebbe. Da valutare se estenderlo anche a
   `RestoreManager` ([`:409`](../app/Services/Restore/RestoreManager.php:409)), che chiama lo stesso
   finalizer.
2. **La migrazione mirata del §4.4 resta comunque**, per il permesso nuovo: è la cintura oltre alle
   bretelle, e vale per chi arriva da un aggiornamento manuale a metà.

Se si fa (1), va deciso anche se **sanare l'esistente**: le installazioni ferme alla mappa
pre-beta.8 si riallineano da sole al primo aggiornamento successivo, ma solo dopo che (1) è uscito.
Nel frattempo la nota nel changelog resta l'unico rimedio per chi aggiorna a mano — e va tenuta.

> 📌 **Voce di roadmap: «Coda ㉖ — L'aggiornamento non semina permessi e ruoli»**, scritta in
> [`roadmap.md`](roadmap.md) il 16/08/2026. La collocazione in versione è lasciata aperta con una
> raccomandazione (1.10). **Se una decisione cambia, si cambia qui e si aggiorna là.** Le altre tre
> voci di questo documento non sono ancora in roadmap: si collocano dopo la chiusura della beta.54.

---

## 5. Progetto: trasferimento amministratore

> Nulla di questa sezione esiste.

### 5.1 Sotto lo stesso nome ci sono due cose diverse

**(A) Il condominio cambia studio** — i dati devono uscire e andare all'amministratore entrante. È il
**fascicolo di consegna**, già progettato e collocato: apre la 1.10.1
([`roadmap.md:295`](roadmap.md:295), progetto in `import_migrazione_dati.md` §22), motivato
sull'art. 1129 comma 8 c.c. **Qui non c'è niente da riprogettare.**

**(B) Dentro l'installazione, la titolarità passa da un amministratore a un altro** — questo non
esiste ed è il tema nuovo.

> ⚠️ Da non confondere con [`subentro_e_competenza_temporale.md`](subentro_e_competenza_temporale.md),
> che tratta il subentro **nella proprietà dell'unità** (chi vende il box a metà esercizio). Stessa
> parola, dominio completamente diverso.

### 5.2 Stato di fatto (accertato il 16/08/2026)

- I ruoli sono **globali**: Spatie senza `teams`. **Non esiste alcun legame amministratore ↔
  condominio**: la tabella `condomini` non ha un `amministratore_id`, e nessuna query filtra per
  amministratore. L'installazione *è* lo studio.
- **Non esiste un concetto di titolare.** Il primo amministratore lo crea l'installer
  ([`CreateAdmin.php:102`](../app/Livewire/Installer/CreateAdmin.php:102)); da lì in poi è un utente
  come gli altri, cancellabile e degradabile come gli altri.
- Il trasferimento oggi si fa a mano in due gesti separati (promuovi il nuovo, degrada o sospendi il
  vecchio) che possono fallire a metà e che, nell'ordine sbagliato, lasciano l'installazione senza
  amministratore (§4.2).

### 5.3 Tre livelli, in ordine di costo

**1. La rete** — l'invariante «almeno un amministratore attivo» del §4.2. Non è una funzione: è ciò
che impedisce di chiudersi fuori casa. Va **con** la sospensione, non dopo.

**2. Il gesto esplicito** — una schermata «Trasferisci amministrazione» che in **una sola
transazione**: promuove il destinatario, degrada il cedente, registra l'evento, notifica entrambi via
email. Con:

- conferma della password del cedente (l'infrastruttura c'è già:
  [`ConfirmablePasswordController`](../app/Http/Controllers/Auth/ConfirmablePasswordController.php));
- il vincolo che il destinatario sia un utente **esistente, con email verificata e non sospeso**;
- il rifiuto se il cedente è l'unico amministratore **e** il destinatario non è valido.

Non tocca il modello dei dati: rende atomico e tracciato ciò che oggi si fa in due passi fragili.

**3. `condominio.amministratore_id`** — cioè più studi nella stessa installazione.
**Sconsigliato ora.** È il salto al multi-tenant: senza lo scoping delle query su tutte le rotte del
gestionale e senza policy per condominio, quella colonna non protegge nulla e dà l'illusione
contraria. Ed è esattamente il problema che il SaaS risolve a livello di **istanza**, non di riga.
Introdurla oggi significherebbe pagare una migrazione su tabelle vive per una funzione che non
funziona.

### 5.4 Collocazione proposta

Livello 1 nella beta che sistema la sospensione. Livello 2 in **1.10.1**, accanto al fascicolo di
consegna: sono la stessa storia dai due lati — chi se ne va porta via i dati, chi resta prende le
chiavi — e la 1.10 ha già la sua ragione per dimagrire.

---

## 6. Progetto: ultimo accesso di ogni utente

> Nulla di questa sezione esiste. Verificato con `grep` su tutto il progetto: nessuna occorrenza di
> `last_login`, `ultimo_accesso`, `last_seen`.

### 6.1 Cosa c'è già

`SESSION_DRIVER=database` e la tabella `sessions` esiste, con le colonne `id`, `user_id`,
`ip_address`, `user_agent`, `payload`, `last_activity`.

Risponde a «chi è collegato **adesso**», non a «quando è entrato l'ultima volta»: le righe le ripulisce
il garbage collector delle sessioni, e `last_activity` è l'ultima **attività**, non l'ultimo
**accesso**. Utile per una futura vista «sessioni attive», non come storico.

### 6.2 Due livelli

**(a) `users.last_login_at`** (più eventualmente `last_login_ip`), scritta da un listener sull'evento
`Illuminate\Auth\Events\Login`.

Un solo listener copre **tutti e tre** i percorsi di autenticazione, perché tutti passano da
`SessionGuard`: login normale (`Auth::attempt`), 2FA
([`CompleteTwoFactorAuthentication`](../app/Actions/TwoFactorAuth/CompleteTwoFactorAuthentication.php)
chiama `Auth::login()`) e ripristino da cookie *remember me*. L'evento **non** scatta a ogni
richiesta: registra l'accesso, che è ciò che serve.

> **Accortezza.** Nel listener non usare `$user->update()` così com'è: `updated_at` diventerebbe la
> data dell'ultimo login e smetterebbe di raccontare le modifiche alla scheda. Scrittura diretta con
> i timestamp disattivati.

**(b) Tabella di log degli accessi** — `user_id`, esito (riuscito/fallito), ip, user agent, momento.
Il precedente in casa c'è: `MassPrunable` su [`Evento.php:12`](../app/Models/Evento.php:12) e
`model:prune` che gira ogni notte ([`routes/console.php:25`](../routes/console.php:25)). Aggiunge i
**tentativi falliti**, che sono la metà interessante dal lato sicurezza.

### 6.3 Raccomandazione

**(a) subito, (b) quando serve un registro.**

La (a) costa una colonna e un listener, e risponde alla domanda che l'amministratore si fa davvero:
*questo condòmino ha mai aperto il portale?* Se non l'ha mai aperto, la convocazione gliela mandi
cartacea. Ed è, non secondariamente, **la colonna che dice quali utenti sospendere**: si sostiene con
il §4 invece di essere una funzione a sé.

### 6.4 Privacy

IP e user agent sono dati personali: se si registrano servono una conservazione a termine dichiarata
(la potatura di `model:prune` è l'attrezzo, la scelta del periodo è una decisione) e una riga
nell'informativa. `last_login_at` da solo è molto più leggero da giustificare. Nel passaggio al SaaS
questa differenza pesa di più, non di meno.

### 6.5 Interfaccia

Colonna nell'elenco utenti accanto allo stato, ordinabile, formato relativo («3 giorni fa») con la
data esatta nel tooltip. **Il valore più utile della colonna è «mai».**

---

## 7. Ordine di esecuzione e vincoli di rilascio

### 7.1 L'ordine

1. **Chi può concedere cosa** (§2). La falla più grave, **nessuna migrazione**. Senza questa, il
   resto è decorativo. Dentro ci sta anche la ripulitura delle rotte utenti del §3 — `reinvite` sotto
   `auth`, `suspend`/`unsuspend` autorizzate.
2. **La sospensione che sospende** (§1 e §4). Login **e** middleware — entrambi, per il motivo del
   §1.4 — più le due invarianti e il permesso dedicato. *Una migrazione* (il permesso).
3. **`last_login_at`** (§6a). *Una migrazione*, e conviene farla viaggiare **nella stessa beta** del
   punto 2: il salto di database si paga una volta sola.
4. **Il seeder nell'aggiornamento** (§4.5.5, roadmap: **coda ㉖**). Indipendente dalle altre tre voci
   e utile a tutto il prodotto, ma **prerequisito di fatto** del punto 2: senza, il permesso nuovo
   arriva a database solo per chi aggiorna a mano. *Nessuna migrazione*, è una riga in
   `SystemFinalizer` più i test.
5. **Trasferimento titolarità** (§5.3, livello 2) — in 1.10.1, accanto al fascicolo di consegna.

I punti 1 e 2 non sono separabili nel tempo per il motivo del §3(a): chiudere la sospensione lasciando
la rotta senza autorizzazione **peggiora** la situazione, perché trasforma una rotta inerte in un
modo per estromettere l'amministratore.

### 7.2 Vincoli

- **Changelog.** I punti 2 e 3 toccano il database: va dichiarato in apertura, ed è la regola scritta
  in `CLAUDE.md`.
- **Migrazioni idempotenti** con guardie separate per colonna e per foreign key, e riga nel dataset
  di `tests/Feature/System/UpgradeMigrationsRerunTest.php`.
- **Il permesso nuovo va creato dalla migrazione**, non dal seeder — §4.4.

### 7.3 La base di partenza è verde

Suite completa eseguita il 16/08/2026 su `1.10.0-beta.53`, prima di qualunque modifica:

```
Tests: 2 skipped, 1306 passed (4895 assertions)   Duration: 45.51s
```

Serve come riferimento: da qui in avanti, un rosso è nostro.

### 7.4 Il test che non c'era

```bash
grep -rl "suspend" tests/
```

Restituisce **zero file**. Nessun test copre quest'area, ed è precisamente il motivo per cui il
middleware ha perso la sua unica rotta nell'aprile 2025 e nessuno se n'è accorto per sedici mesi.

I quattro test minimi da scrivere insieme al codice:

1. utente sospeso che tenta il login → respinto, con il messaggio giusto;
2. utente sospeso **con 2FA attiva** che supera la sfida → respinto (il ramo che salta `LoginRequest`);
3. utente già collegato che viene sospeso → alla richiesta successiva è fuori, sessione invalidata;
4. l'ultimo amministratore non può essere sospeso, eliminato né degradato — da sé stesso né da altri.

---

## 8. Domande aperte, da decidere prima di scrivere codice

1. **La sospensione è un permesso o un ruolo?** Il §4 propone un permesso dedicato al solo
   amministratore. L'alternativa è non renderlo delegabile affatto (controllo sul ruolo, come fanno
   [`RolePolicy`](../app/Policies/RolePolicy.php) e
   [`PermissionPolicy`](../app/Policies/PermissionPolicy.php)). Il permesso è più coerente col resto
   del sistema; il ruolo è più difficile da sbagliare.
2. **Chi può assegnare il ruolo amministratore?** La correzione del §2 richiede una regola esplicita.
   La più semplice: solo chi ha già quel ruolo può concederlo. Va scritta, non lasciata implicita.
3. **Il cedente, dopo il trasferimento (§5.3), viene degradato a collaboratore o sospeso?** Sono due
   risposte diverse a due situazioni diverse (l'amministratore che lascia lo studio / il socio che
   resta). Probabilmente va scelto al momento, nella schermata.
4. **Retention del log accessi (§6b)**, se e quando si fa.
5. **La sospensione deve valere anche per i percorsi non di sessione** — link firmati delle
   comunicazioni, conferma email, reset password? Oggi la domanda non si pone perché non vale per
   nulla; una volta chiusa, va risposta.
6. **L'aggiornamento deve seminare i permessi da sé?** (§4.5.) L'unica obiezione che avevo sollevato
   è caduta (§4.5.3): i ruoli di sistema non sono modificabili dall'interfaccia, quindi non c'è
   personalizzazione da perdere. Resta da decidere **solo** se la chiamata è mirata al solo
   `RolesAndPermissionsSeeder` — raccomandato — o se si vuole rivedere `DatabaseSeeder` perché
   `db:seed` diventi sicuro in aggiornamento (più lavoro, meno urgente).
7. **Si sana l'esistente della beta.8?** (§4.5.4.) Le installazioni aggiornate dal pannello dal
   06/06/2026 in poi hanno la mappa ruoli vecchia: condòmini e fornitori non pubblicano né
   modificano i propri commenti. Con il §4.5.5 si riallineano al primo aggiornamento utile — ma va
   detto nel changelog, perché per quegli amministratori è un **cambio di comportamento**, non una
   funzione nuova.

---

## Appendice: comandi per rifare gli accertamenti

```bash
php artisan route:list --json | grep -c "CheckSuspendedUser"
```

```bash
php artisan route:list --path=utenti
```

```bash
grep -rn "suspended" app/ --include='*.php'
```

```bash
git log --oneline -S "CheckSuspendedUser" -- .
```
