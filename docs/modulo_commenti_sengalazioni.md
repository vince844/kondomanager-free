# Modulo Commenti — Guida Architetturale

**Progetto:** Kondomanager
**Stack:** Laravel 12 · Vue 3 · Inertia · MySQL 8 (SQLite in-memory per test) · Spatie Permissions · Pest
**Scope iniziale:** commenti sulle Segnalazioni Guasto, con architettura polimorfica pronta per Comunicazioni, Delibere, Documenti, ecc.

---

## 1. Obiettivo e principi

Introdurre un livello "social" riutilizzabile: un primitivo unico (`commenti`) attaccabile a qualsiasi entità tramite relazione polimorfica, sul modello "core gratuito e generico, tanti consumatori" già adottato altrove nel progetto.

Principi guida:

- **Un solo primitivo, tanti consumatori.** Nessuna tabella `commenti_segnalazioni` dedicata: una tabella polimorfica.
- **Il modulo non conosce le regole di dominio.** Decisioni come "i commenti sono abilitati?" o "qual è la priorità?" le prende il modello commentabile tramite contratto, non il modulo.
- **Multi-tenancy esplicito.** Ogni commento porta il `condominio_id`; il permesso non basta, serve appartenenza al condominio.
- **Immutabilità/audit.** Niente delete duri: soft delete + stato di moderazione, coerente con la filosofia ledger-centric.
- **Lista piatta.** Conversazione cronologica, nessun threading annidato.
- **Due canali, due target.** Email + `database` per tutti i partecipanti; Admin Inbox (Evento) in aggiunta per l'amministratore.

---

## 2. Decisioni architetturali (confermate)

| Decisione | Scelta |
|---|---|
| Tabella | Polimorfica unica `commenti` |
| Threading | Lista piatta cronologica (no `parent_id`) |
| Autore | `user_id` → `users`; display name via relazione `anagrafica` |
| Flag "commenti abilitati" | Colonna sul modello, esposta via contratto `Commentable` |
| Comportamento alla disabilitazione | Commenti esistenti visibili in sola lettura; si bloccano solo i nuovi |
| Cancellazione | Soft delete + `stato` (`pubblicato`/`nascosto`) |
| Allegati/foto | Gestiti via tabella `documenti` polimorfica esistente, a livello entità |
| Notifiche partecipanti | Mail + `database` per tutti (condomini, admin, partecipanti al thread) |
| Admin Inbox | `Evento` HIDDEN + `requires_action` in **aggiunta** alle notifiche standard; aggregato (1 Evento per commentable, non 1 per commento) |
| Priorità Admin Inbox | Derivata dal modello via `prioritaCommento()` — segnalazione urgente → `alta`, normale → `normale` |

> **Nota threading.** Se un futuro consumatore richiedesse risposte annidate, basta aggiungere `parent_id` nullable: migration non distruttiva.

---

## 3. Schema database

### 3.1 Tabella `commenti`

```php
Schema::create('commenti', function (Blueprint $table) {
    $table->id();
    $table->morphs('commentable'); // commentable_type + commentable_id + indice composto
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('condominio_id')->nullable()->constrained()->cascadeOnDelete();
    $table->text('corpo');
    $table->enum('stato', ['pubblicato', 'nascosto', 'in_attesa'])->default('pubblicato');
    // 'in_attesa': commento postato da non-admin, in coda di approvazione (pre-moderazione).
    $table->foreignId('moderato_da')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('moderato_at')->nullable();
    $table->timestamp('modificato_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['commentable_type', 'commentable_id', 'stato']);
    $table->index('condominio_id');
});
```

### 3.2 Flag sul modello commentabile

```php
Schema::table('segnalazioni_guasti', function (Blueprint $table) {
    $table->boolean('commenti_abilitati')->default(true);
});
```

> Adatta il nome tabella a quello reale.

### 3.3 Allegati — nessuna tabella nuova

I commenti restano **solo testo**. Allegati e foto si attaccano all'entità padre via tabella `documenti` polimorfica già esistente. Se in futuro servisse un allegato su un singolo commento, basta rendere `Commento` documentable — nessuna tabella nuova.

---

## 4. Modelli, contratto e trait

### 4.1 Contratto `Commentable`

Il contratto è il punto di estensione principale: ogni modello commentabile dice al modulo come comportarsi, senza che il modulo conosca le regole di dominio.

```php
namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Commentable
{
    /** Relazione polimorfica (fornita dal trait). */
    public function commenti(): MorphMany;

    /** Il modello decide se accetta nuovi commenti. */
    public function commentiAbilitati(): bool;

    /** Per la denormalizzazione del condominio_id sul commento. */
    public function condominioId(): ?int;

    /**
     * Priorità dell'Evento nell'Admin Inbox quando arriva un commento.
     * Valori: 'alta' | 'normale' | 'bassa'
     * Default nel trait: 'normale'. Override nei modelli specifici.
     * Esempio: SegnalazioneGuasto ritorna 'alta' se urgente.
     */
    public function prioritaCommento(): string;

    /**
     * Titolo per l'Evento Admin Inbox.
     * Default nel trait: "ClassName #id". Override per titoli leggibili.
     */
    public function titoloInbox(): string;

    /**
     * Determina se l'utente ha il diritto di commentare su questa entità.
     * La Policy delega interamente qui — centralizza tutta la logica di accesso
     * specifica del dominio (privata, assegnata, fornitore, collaboratore…).
     * L'admin bypassa sempre tramite Policy::before(), questo metodo non viene
     * mai chiamato per gli amministratori.
     *
     * Default nel trait: qualsiasi membro del condominio.
     * Override in SegnalazioneGuasto per le regole specifiche.
     */
    public function utenteHaAccessoAiCommenti(User $user): bool;
}
```

### 4.2 Trait `HasComments`

```php
namespace App\Concerns;

use App\Models\Commento;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    /** Commenti visibili, ordine cronologico (per la UI). */
    public function commenti(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable')
                    ->where('stato', 'pubblicato')
                    ->oldest();
    }

    /** Tutti i commenti (qualsiasi stato) — per creazione e moderazione. */
    public function tuttiICommenti(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable');
    }

    /** Default: priorità normale. Override nei modelli che hanno un campo urgenza. */
    public function prioritaCommento(): string
    {
        return 'normale';
    }

    /** Default: "NomeClasse #id". Override per titoli leggibili nell'inbox. */
    public function titoloInbox(): string
    {
        return class_basename($this) . ' #' . $this->getKey();
    }

    /** Default: qualsiasi membro del condominio. Override nei modelli con regole specifiche. */
    public function utenteHaAccessoAiCommenti(User $user): bool
    {
        return $user->condomini()->whereKey($this->condominioId())->exists();
    }
}
```

### 4.3 Applicazione al modello

```php
class SegnalazioneGuasto extends Model implements Commentable
{
    use HasComments;

    public function commentiAbilitati(): bool
    {
        return (bool) $this->commenti_abilitati;
    }

    public function condominioId(): ?int
    {
        return $this->condominio_id;
    }

    /**
     * Priorità dinamica: la urgenza della segnalazione si trasferisce
     * direttamente all'Evento nell'Admin Inbox.
     * Adatta il nome campo al tuo schema reale (es. 'urgente', 'priorita' enum).
     */
    public function prioritaCommento(): string
    {
        if ($this->urgente ?? false) {
            return 'alta';
        }
        // Estendi con altri livelli se hai un enum priorita sul modello:
        // return match($this->priorita) {
        //     'critica' => 'alta',
        //     'bassa'   => 'bassa',
        //     default   => 'normale',
        // };
        return 'normale';
    }

    /**
     * Titolo leggibile per l'Admin Inbox.
     * Adatta al campo reale (es. 'oggetto', 'descrizione', 'titolo').
     */
    public function titoloInbox(): string
    {
        return $this->oggetto ?? $this->descrizione ?? "Segnalazione #{$this->id}";
    }

    /**
     * Matrice di accesso ai commenti per SegnalazioneGuasto.
     *
     * | Tipo segnalazione              | Chi può commentare                          |
     * |--------------------------------|---------------------------------------------|
     * | Privata                        | Solo il creatore                            |
     * | Assegnata a utenti specifici   | Creatore + utenti assegnati                 |
     * | Assegnata a fornitori (futuro) | Creatore + fornitori assegnati              |
     * | Assegnata a collaboratori (f.) | Creatore + collaboratori assegnati          |
     * | Nessuna restrizione            | Qualsiasi membro del condominio             |
     *
     * L'amministratore bypassa sempre tramite Policy::before() — non viene
     * mai valutato da questo metodo.
     */
    public function utenteHaAccessoAiCommenti(User $user): bool
    {
        // Privata: solo il creatore della segnalazione.
        // Adatta il nome campo al tuo schema reale ('privata', 'is_private', 'visibilita', ecc.)
        if ($this->privata ?? false) {
            return $this->user_id === $user->id;
        }

        // Assegnata a utenti specifici: creatore + assegnati.
        // Adatta 'assegnatari' alla relazione reale (belongsToMany verso users, ecc.)
        if (method_exists($this, 'assegnatari') && $this->assegnatari()->exists()) {
            return $this->user_id === $user->id
                || $this->assegnatari()->whereKey($user->id)->exists();
        }

        // TODO(v-future): assegnazione a fornitori — solo i fornitori assegnati + creatore.
        // if (method_exists($this, 'fornitoriAssegnati') && $this->fornitoriAssegnati()->exists()) {
        //     return $this->user_id === $user->id
        //         || $this->fornitoriAssegnati()
        //                ->whereHas('user', fn ($q) => $q->whereKey($user->id))
        //                ->exists();
        // }

        // TODO(v-future): assegnazione a collaboratori — delega per studi grandi.
        // if (method_exists($this, 'collaboratoriAssegnati') && $this->collaboratoriAssegnati()->exists()) {
        //     return $this->user_id === $user->id
        //         || $this->collaboratoriAssegnati()->whereKey($user->id)->exists();
        // }

        // Default: qualsiasi membro del condominio.
        return $user->condomini()->whereKey($this->condominio_id)->exists();
    }
}
```

### 4.4 Modello `Commento`

```php
class Commento extends Model
{
    use SoftDeletes;

    protected $table = 'commenti';

    protected $fillable = ['corpo'];

    protected $casts = [
        'modificato_at' => 'datetime',
        'moderato_at'   => 'datetime',
    ];

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function eModificato(): bool
    {
        return $this->modificato_at !== null;
    }
}
```

---

## 5. Permessi (Spatie) e Policy

### 5.1 Permessi

```php
$permessi = [
    'commenti.visualizza',
    'commenti.crea',
    'commenti.modifica',   // solo i propri (enforced in Policy)
    'commenti.elimina',    // solo i propri (enforced in Policy)
    'commenti.modera',     // nasconde/elimina qualsiasi, toggle abilitazione
];
```

Assegnazione tipica:
- **Amministratore:** tutti, incluso `commenti.modera`.
- **Condomino:** `visualizza`, `crea`, `modifica`, `elimina` (sui propri).

### 5.2 Policy

```php
class CommentoPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        return $user->can('commenti.modera') ? true : null;
    }

    public function create(User $user, Commentable $commentable): bool
    {
        return $user->can('commenti.crea')
            && $commentable->commentiAbilitati()
            && $commentable->utenteHaAccessoAiCommenti($user); // tutta la logica di dominio è nel modello
    }

    public function update(User $user, Commento $commento): bool
    {
        return $user->can('commenti.modifica') && $commento->user_id === $user->id;
    }

    public function delete(User $user, Commento $commento): bool
    {
        return $user->can('commenti.elimina') && $commento->user_id === $user->id;
    }

    public function moderate(User $user): bool
    {
        return $user->can('commenti.modera');
    }
}
```

> **Architettura di delega.** La Policy è generica e stabile — non cambia mai quando aggiungi nuovi casi di accesso (fornitori, collaboratori, ecc.). Tutta la logica specifica del dominio vive in `utenteHaAccessoAiCommenti()` sul modello. Ogni nuovo tipo di assegnazione richiede solo di togliere il commento dai TODO in `SegnalazioneGuasto` e implementare la relazione, senza toccare la Policy.

> **Notifiche coerenti con l'accesso.** Il `CommentoNotificationService::destinatari()` usa la stessa regola: filtra i destinatari tramite `utenteHaAccessoAiCommenti()` (vedi sezione 7.3). Se l'accesso cambia, le notifiche si adeguano automaticamente.

---

## 6. Servizio di creazione

```php
class CommentoService
{
    public function crea(Commentable $commentable, User $autore, string $corpo): Commento
    {
        return DB::transaction(function () use ($commentable, $autore, $corpo) {
            $commento = $commentable->tuttiICommenti()->create([
                'user_id'       => $autore->id,
                'condominio_id' => $commentable->condominioId(),
                'corpo'         => $this->sanitizza($corpo),
                // Pre-moderazione: i non-admin attendono approvazione.
                // Gli admin (commenti.modera) pubblicano direttamente.
                'stato'         => $autore->can('commenti.modera') ? 'pubblicato' : 'in_attesa',
            ]);

            event(new CommentoCreato($commento));

            return $commento;
        });
    }

    private function sanitizza(string $corpo): string
    {
        return trim(strip_tags($corpo));
    }
}
```

---

## 7. Notifiche (Mail + Database)

### 7.1 ⚠️ Note di integrazione — verificare prima di implementare

#### Opt-in e GDPR

Le notifiche email in Kondomanager sono **opt-in, disattivate di default** per conformità GDPR. Questo è un requisito dell'intero sistema di notifiche esistente — non una scelta del modulo commenti.

`CommentoNotificationService::destinatari()` deve integrarsi con il meccanismo di preferenze utente esistente prima di finalizzare la lista. Aggiungere il filtro preferenze all'ultima catena:

```php
return $utenti
    ->filter()
    ->unique('id')
    ->reject(fn ($u) => $u->id === $commento->user_id)
    ->filter(fn ($u) => $u->haAbilitatoNotifiche('commento')); // TODO: adatta al metodo reale del sistema preferenze esistente
```

> Verificare il nome del metodo/contratto esistente per le preferenze notifiche e allineare. Se il sistema usa una tabella `preferenze_notifiche`, la query deve includere il join corretto.

#### Toggle notifiche legati ai permessi

Il toggle per le notifiche email dei commenti nel profilo utente è **visibile solo se l'utente ha il permesso `commenti.crea`**. Questo è il comportamento del sistema esistente: non mostrare controlli per notifiche di funzionalità a cui l'utente non ha accesso.

| Toggle nel profilo utente | Visibile solo se |
|---|---|
| "Notificami nuovi commenti" | `commenti.crea` |
| "Notificami approvazione del mio commento" | `commenti.crea` |
| "Commenti in attesa di moderazione" | `commenti.modera` |

Verificare che il componente Vue del profilo (o il controller che costruisce i toggle) applichi il check via sistema permessi esistente — non hardcoded.

---

### 7.2 Catalogo completo notifiche — flusso commenti

Documento di riferimento per l'implementazione progressiva. Alcune notifiche dipendono da una decisione architetturale aperta (pre vs post moderazione — vedi nota sotto).

#### MVP — da implementare subito

| Classe | Destinatari | Trigger |
|---|---|---|
| `NuovoCommentoNotification` | Tutti i partecipanti al thread | Commento pubblicato |

#### Post-MVP — flusso vita del commento

| Classe | Destinatari | Trigger | Dipendenza |
|---|---|---|---|
| `CommentoNascostoNotification` | Autore del commento | Admin nasconde il commento | Nessuna — moderazione post-pubblicazione già presente nello schema |
| `CommentoEliminatoNotification` | Autore del commento | Admin esegue soft delete | Nessuna |
| `ThreadChiusoNotification` | Partecipanti attivi al thread | Admin disabilita `commenti_abilitati` | Nessuna — valutare se aggiunge valore o genera rumore |

#### Post-MVP — flusso pre-moderazione (decisione aperta)

| Classe | Destinatari | Trigger | Dipendenza |
|---|---|---|---|
| `CommentoInAttesaNotification` | Amministratore | Commento postato, in attesa di approvazione | Stato `'in_attesa'` + pre-moderazione |
| `CommentoApprovatoNotification` | Autore del commento | Admin approva e pubblica il commento | Stato `'in_attesa'` + pre-moderazione |
| `CommentoRifiutato Notification` | Autore del commento | Admin rifiuta il commento | Stato `'in_attesa'` + pre-moderazione |

> **Pre-moderazione: implementata in v1.9.1.** `stato = 'in_attesa'` aggiunto all'enum. `CommentoService::crea()` imposta automaticamente `'in_attesa'` per i non-admin e `'pubblicato'` per chi ha `commenti.modera`. `HasComments::commenti()` filtra `stato = 'pubblicato'`, escludendo automaticamente i pending dalla vista pubblica. La coda di approvazione è visibile agli admin tramite `commentiInAttesa` iniettato da `SegnalazioneController::show()` nel render Inertia. Le tre notification (`CommentoInAttesa`, `CommentoApprovato`, `CommentoRifiutato`) sono implementate e rispettano le preferenze opt-in.

---

### 7.3 Implementazione MVP

Tutti i partecipanti ricevono notifica via mail e canale `database` (badge in UI).
L'Admin Inbox è gestita separatamente dalla sezione 8.

```php
class CommentoNotificationService
{
    public function destinatari(Commento $commento): Collection
    {
        $utenti = collect();

        // 1. Autore dell'entità commentata
        $utenti->push($commento->commentable->autore ?? null);

        // 2. Amministratori del condominio
        $utenti = $utenti->merge(
            $this->amministratoriDel($commento->condominio_id)
        );

        // 3. Altri partecipanti al thread
        $utenti = $utenti->merge(
            $this->partecipanti($commento->commentable)
        );

        return $utenti
            ->filter()
            ->unique('id')
            ->reject(fn ($u) => $u->id === $commento->user_id)
            // Rispetta la stessa matrice di accesso della Policy:
            // segnalazioni private/assegnate notificano solo chi ha accesso.
            ->filter(fn ($u) => $commento->commentable->utenteHaAccessoAiCommenti($u)
                || $u->can('commenti.modera')) // gli admin ricevono sempre (modera bypassa)
            // TODO: integrare con il sistema preferenze notifiche esistente (opt-in GDPR)
            // ->filter(fn ($u) => $u->haAbilitatoNotifiche('commento'))
        ;
    }
}
```

```php
class NuovoCommentoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Commento $commento) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $entita = class_basename($this->commento->commentable);
        return (new MailMessage)
            ->subject("Nuovo commento su {$entita}")
            ->line("{$this->commento->autore->name} ha scritto un commento.")
            ->action('Visualizza', $this->urlCommentabile())
            ->line('Ricevi questa email perché partecipi alla discussione.');
    }

    public function toArray($notifiable): array
    {
        return [
            'commento_id'      => $this->commento->id,
            'commentable_type' => $this->commento->commentable_type,
            'commentable_id'   => $this->commento->commentable_id,
            'autore'           => $this->commento->autore->name,
            'anteprima'        => Str::limit($this->commento->corpo, 120),
        ];
    }
}
```

---

## 8. Integrazione Admin Inbox

### 8.1 Architettura a due canali

| Destinatario | Canale | Trigger |
|---|---|---|
| Tutti i partecipanti | Mail + `database` | Ogni nuovo commento (sezione 7) |
| Amministratore del condominio | **Admin Inbox (`Evento`)** | Ogni nuovo commento; aggregato per commentable |

L'Evento Admin Inbox è **aggiuntivo**, non sostituisce le notifiche standard. L'amministratore riceve entrambi: l'email/badge lo raggiunge ovunque, l'Evento centralizza le azioni operative nell'inbox.

### 8.2 Logica di aggregazione

Per evitare il rumore, **un solo Evento aperto per commentable**. Ogni nuovo commento aggiorna l'Evento esistente (anteprima + timestamp + contatore nel metadata) invece di crearne uno nuovo. L'Evento si chiude quando l'amministratore lo marca come letto/completato.

### 8.3 Priorità dall'entità

La priorità dell'Evento rispecchia la gravità del contesto:

```
SegnalazioneGuasto urgente  → Evento priorità 'alta'   → badge rosso in inbox
SegnalazioneGuasto normale  → Evento priorità 'normale' → badge standard
Comunicazione               → Evento priorità 'normale' (default del trait)
```

La logica vive in `prioritaCommento()` sul modello — il modulo commenti non sa nulla di "urgente".

> **Aggiornamento dinamico della priorità.** Se tra un commento e il successivo la segnalazione diventa urgente, l'Evento aggiorna la priorità al valore più alto. Questo garantisce che un thread che inizia normale e poi diventa critico emerga correttamente nell'inbox.

### 8.4 `CommentoEventoService`

```php
class CommentoEventoService
{
    public function sincronizza(Commento $commento): void
    {
        $commentable = $commento->commentable;
        $priorita    = $commentable->prioritaCommento();
        $anteprima   = Str::limit($commento->corpo, 100);
        $autore      = $commento->autore->name;

        // Cerca un Evento aperto per questo thread
        $evento = Evento::query()
            ->where('tipo',             'commento')
            ->where('eventable_type', $commento->commentable_type)
            ->where('eventable_id',   $commento->commentable_id)
            ->where('visibilita',       'hidden')
            ->where('is_completed', false)
            ->first();

        if ($evento) {
            $meta = $evento->meta ?? [];
            $evento->update([
                'priorita'   => $priorita,
                'meta'       => array_merge($meta, [
                    'contatore'        => ($meta['contatore'] ?? 0) + 1,
                    'ultima_anteprima' => $anteprima,
                    'ultimo_autore'    => $autore,
                ]),
                'updated_at' => now(),
            ]);
        } else {
            Evento::create([
                'tipo'             => 'commento',
                'visibilita'       => 'hidden',
                'requires_action'  => true,
                'eventable_type' => $commento->commentable_type,
                'eventable_id'   => $commento->commentable_id,
                'condominio_id'    => $commento->condominio_id,
                'titolo'           => 'Nuovo commento — ' . $commentable->titoloInbox(),
                'priorita'         => $priorita,
                'meta'             => [
                    'contatore'        => 1,
                    'ultima_anteprima' => $anteprima,
                    'ultimo_autore'    => $autore,
                    'commento_id'      => $commento->id,
                ],
            ]);
        }
    }
}
```

> Adatta i nomi colonna (`visibilita`, `is_completed`, `priorita`, `requires_action`, `tipo`, `meta`) allo schema reale del tuo modello `Evento`.

### 8.5 Accessor sul modello `Evento` — punto unico di normalizzazione NULL

La colonna `priorita` è nullable: i record legacy (F24, rate, scadenze) hanno `NULL` perché la priorità non è un concetto applicabile a quei tipi di evento. Tuttavia, qualsiasi codice che legge `$evento->priorita` senza un null-check andrebbe in errore.

La soluzione è un **singolo accessor** sul modello `Evento` che normalizza `NULL → 'normale'` a livello applicativo, senza toccare il database:

```php
// In Evento.php — aggiungere agli accessor esistenti

/**
 * Normalizza NULL a 'normale' in modo che il codice applicativo
 * riceva sempre una stringa valida. Il DB mantiene NULL per gli
 * eventi legacy (semanticamente: priorità non applicabile),
 * ma nessun codice PHP o Vue vede mai null.
 */
public function getPrioritaAttribute(?string $value): string
{
    return $value ?? 'normale';
}
```

Da questo momento in poi, `$evento->priorita` ritorna sempre `'alta'`, `'normale'` o `'bassa'` — ovunque nel codebase, senza null-check distribuiti.

### ⚠️ Nota ENUM e ordinamento — trappola da evitare

`ENUM('alta', 'normale', 'bassa')` in MySQL assegna indici numerici per ordine di dichiarazione:
`alta = 1`, `normale = 2`, `bassa = 3`.

`ORDER BY priorita DESC` ordina per indice decrescente: **`bassa` viene prima di `alta`** — il contrario di quello che si vuole per un inbox per priorità.

**Non usare mai `ORDER BY priorita` direttamente.** Usare sempre `FIELD()` con `COALESCE` per gestire i NULL:

```php
// Ordinamento corretto per l'inbox: alta prima, normale, bassa, legacy (null) in fondo
Evento::where('visibilita', 'hidden')
    ->where('is_completed', false)
    ->orderByRaw("FIELD(COALESCE(priorita, 'normale'), 'alta', 'normale', 'bassa')")
    ->get();
```

Questo garantisce: `alta → normale → bassa → NULL` (dove NULL, normalizzato a 'normale' dalla COALESCE, si posiziona dopo 'alta').



### 8.6 Listener aggiornato

Il listener orchestra entrambi i canali:

```php
class InviaNotificheCommento
{
    public function __construct(
        private CommentoNotificationService $notifiche,
        private CommentoEventoService       $inbox,
    ) {}

    public function handle(CommentoCreato $evento): void
    {
        $commento    = $evento->commento;
        $destinatari = $this->notifiche->destinatari($commento);

        // Canale 1: Mail + database per tutti i partecipanti
        Notification::send($destinatari, new NuovoCommentoNotification($commento));

        // Canale 2: Admin Inbox (Evento aggregato) per l'amministratore
        $this->inbox->sincronizza($commento);
    }
}
```

### 8.7 Chiusura dell'Evento

L'Evento viene marcato come completato quando l'amministratore visita la segnalazione o clicca sull'elemento inbox. Sono valorizzati sia `is_completed` (boolean, per query veloci) che `completed_at` (timestamp, per audit e analytics sui tempi di risposta).

```php
// In SegnalazioneController::show() — adatta al nome controller reale
Evento::query()
    ->where('tipo',           EventoTipo::COMMENTO->value)
    ->where('eventable_type', Segnalazione::class) // adatta al nome modello reale
    ->where('eventable_id',   $segnalazione->id)
    ->where('is_completed',   false)
    ->update([
        'is_completed' => true,
        'completed_at' => now(),
    ]);
```

### 8.8 ⚠️ Retrocompatibilità — regole per non rompere la logica esistente

La tabella `eventi` è già usata da altri moduli (scadenziario F24, pagamenti, rate). Qualsiasi modifica allo schema deve essere **strettamente additiva e nullable**: i record esistenti non vengono toccati e le query esistenti continuano a funzionare invariate.

#### Principi obbligatori

1. **Solo `ALTER TABLE ADD COLUMN`** — mai modificare o rimuovere colonne esistenti.
2. **Ogni colonna nuova: nullable o con default sicuro** — i record esistenti avranno `NULL` o il default; nessun `NOT NULL` senza default.
3. **`Schema::hasColumn()` come guardia** — se la colonna esiste già (installazione aggiornata da versione precedente), la migration non la ricrea e non va in errore.
4. **`tipo` come chiave di isolamento** — tutte le query del modulo commenti filtrano `where('tipo', 'commento')`. I record esistenti hanno `tipo = NULL` o un valore diverso: non vengono mai restituiti dalle query del modulo commenti, e le query esistenti (che non filtrano per `tipo`) non vengono alterate.

#### Migration defensiva

```php
Schema::table('eventi', function (Blueprint $table) {

    // Tipo evento — isola i commenti dagli altri eventi esistenti.
    // Nullable: i record esistenti ricevono NULL, non interferiscono con le query
    // del modulo commenti che filtrano esplicitamente where('tipo', 'commento').
    if (!Schema::hasColumn('eventi', 'tipo')) {
        $table->string('tipo', 60)->nullable()->index()->after('id');
    }

    // Relazione polimorfica verso l'entità commentata.
    // Nullable: gli eventi esistenti (F24, rate, scadenze) non hanno un eventable.
    if (!Schema::hasColumn('eventi', 'eventable_type')) {
        $table->string('eventable_type')->nullable()->after('tipo');
    }
    if (!Schema::hasColumn('eventi', 'eventable_id')) {
        $table->unsignedBigInteger('eventable_id')->nullable()->after('eventable_type');
    }

    // Priorità visiva nell'inbox.
    // NULLABLE senza default: i record esistenti (F24, rate, scadenze) ricevono NULL,
    // che significa "priorità non applicabile a questo tipo di evento".
    // CommentoEventoService imposta sempre il valore esplicitamente sui nuovi record,
    // quindi gli eventi commento non avranno mai NULL.
    // La UI tratta NULL e 'normale' identicamente (nessun badge), ma la distinzione
    // semantica è importante per l'estensione futura ad altri tipi di evento.
    if (!Schema::hasColumn('eventi', 'priorita')) {
        $table->enum('priorita', ['alta', 'normale', 'bassa'])
              ->nullable()   // NO default — record esistenti → NULL, nuovi commenti → valore esplicito
              ->after('eventable_id');
    }

    // Metadati JSON (contatore, anteprima, autore…).
    // Se esiste già come campo generico, verificare che il cast sia array/json
    // nel modello Evento prima di usare array_merge().
    if (!Schema::hasColumn('eventi', 'meta')) {
        $table->json('meta')->nullable()->after('priorita');
    }

    // Timestamp di completamento (dismiss inbox).
    // Nullable: NULL = aperto, valorizzato = chiuso. Compatibile con logica esistente.
    if (!Schema::hasColumn('eventi', 'is_completed')) {
        $table->boolean('is_completed')->default(false);
        // Nota: i record esistenti ricevono false (= non completati) — comportamento corretto.
    }
});
```

> **SQLite (test):** `enum` diventa `varchar` — nessun problema. Le guard `Schema::hasColumn()` funzionano identiche in SQLite e MySQL.

#### Perché le query esistenti non si rompono

```
Record esistenti:  tipo = NULL,  eventable_type = NULL  → ignorati da where('tipo','commento')
Nuovi commenti:    tipo = 'commento',  eventable_type = 'App\...'  → ignorati da query senza filtro tipo
```

Le query del modulo commenti sono auto-isolate. Le query esistenti dell'inbox (`where('visibilita','hidden')`, `where('requires_action', true)`) inizieranno a restituire anche gli eventi commento — **questo è il comportamento corretto** (i commenti devono apparire nell'inbox). La UI dovrà aggiungere un case di rendering per `tipo = 'commento'`, ma questo è lavoro di frontend, non un rischio di regressione sui dati.

#### Checklist prima del deploy su installazioni esistenti

- [ ] Verificare che il modello `Evento` gestisca `priorita = NULL` nel rendering dell'inbox (NULL = nessun badge, comportamento identico a 'normale' visivamente — ma semanticamente distinto).
- [ ] Verificare i nomi colonna reali del modello `Evento` corrente
- [ ] Verificare che il cast `meta` nel modello `Evento` sia `'array'` o `AsArrayObject` prima di usare `array_merge()` in `CommentoEventoService`.
- [ ] Aggiungere il case `tipo === 'commento'` nel componente Vue dell'Admin Inbox prima di rilasciare, così i nuovi eventi non appaiono senza icona/CTA.
- [ ] Eseguire la migration su un dump di produzione in staging e verificare che gli eventi esistenti (F24, rate, scadenze) continuino a renderizzarsi correttamente.

---

## 9. Moderazione

- **Nascondere:** `stato = 'nascosto'` + `moderato_da` / `moderato_at`. In UI compare "Commento rimosso da un amministratore".
- **Eliminare:** soft delete (`deleted_at`). Stesso placeholder.
- **Chiudere thread:** toggle `commenti_abilitati` sul modello. Commenti esistenti visibili in sola lettura.

```php
public function modera(Commento $commento, User $moderatore): void
{
    $commento->update([
        'stato'       => 'nascosto',
        'moderato_da' => $moderatore->id,
        'moderato_at' => now(),
    ]);
}
```

---

## 10. Controller e rotte

```php
Route::post('segnalazioni/{segnalazione}/commenti',         [CommentoController::class, 'store']);
Route::patch('commenti/{commento}',                         [CommentoController::class, 'update']);
Route::delete('commenti/{commento}',                        [CommentoController::class, 'destroy']);
Route::post('commenti/{commento}/modera',                   [CommentoController::class, 'modera']);
Route::patch('segnalazioni/{segnalazione}/commenti/toggle', [CommentoController::class, 'toggle']);
```

Nel `store`: `$this->authorize('create', [Commento::class, $segnalazione]);` prima di `CommentoService`.

Eager load nella view segnalazione:

```php
$segnalazione->load(['commenti.autore']);
```

Badge contatore nella lista:

```php
SegnalazioneGuasto::withCount('commenti')->get();
```

---

## 11. Frontend (Vue/Inertia) — note rapide

- **Mai `v-html`** sul corpo: rendering come testo, Vue fa l'escape automatico.
- Componenti: `ListaCommenti.vue` (stream cronologico), `Commento.vue`, `FormCommento.vue`.
- Mostra badge "urgente" accanto al form commenti se `segnalazione.urgente` — contestualizza visivamente la priorità.
- Mostra "modificato" se `modificato_at !== null`.
- Form disabilitato se `commenti_abilitati === false`, con i commenti in sola lettura.
- Pannello moderazione solo con permesso `commenti.modera` (flag dal backend, mai dal frontend).
- **Admin Inbox:** il titolo dell'Evento include `titoloInbox()` + contatore commenti non letti dal metadata. CTA diretta alla segnalazione con anchor `#commenti`.

---

## 12. Estensioni future (solo annotate)

- **Menzioni `@utente`**: parsing del corpo, notifica dedicata, tabella `commento_menzioni`. Risolvono il targeting senza threading.
- **Reazioni**: tabella polimorfica `reazioni` (stesso pattern morph).
- **Letti/non letti**: pivot `commento_letture(user_id, commento_id, letto_at)`.
- **Rate limiting** sulla creazione (anti-spam).
- **Allegati per-commento**: rendere `Commento` documentable — nessuna tabella nuova.
- **Threading**: `parent_id` nullable, solo se richiesto da un consumatore specifico.
- **Priorità granulare**: estendere `prioritaCommento()` con enum multi-livello.

**Estensioni accesso commenti — rimuovere i TODO in `SegnalazioneGuasto::utenteHaAccessoAiCommenti()`:**

- **Fornitori assegnati**: `fornitoriAssegnati()` belongsToMany verso `fornitori` con pivot `user_id`. Solo i fornitori assegnati alla segnalazione possono commentare (non tutti i fornitori del condominio). La relazione fornitore→utente deve essere risolta per il check `$user->id`.
- **Collaboratori assegnati**: `collaboratoriAssegnati()` belongsToMany verso `users` con ruolo `collaboratore`. L'admin delega a uno o più collaboratori dello studio — solo loro vedono e commentano la segnalazione assegnata. Utile per studi di grandi dimensioni.
- **Pre-moderazione**: aggiungere stato `'in_attesa'` all'enum `stato` di `commenti` e implementare la coda di approvazione con le tre notifiche corrispondenti (vedi sezione 7.2).
- **Visibilità storico al cambio stato**: se una segnalazione passa da pubblica a privata dopo che ci sono commenti, decidere se lo storico dei commenti rimane visibile ai partecipanti precedenti o viene oscurato. Definire prima di implementare il cambio stato.

**Evento agenda collegato a segnalazione:**

L'amministratore può appuntarsi un promemoria in agenda per una segnalazione specifica. Usa il modello `Evento` già presente con `tipo = 'agenda_segnalazione'`, `eventable_type/id` che punta alla segnalazione, e un campo data/ora. Non è un commento — è un Evento separato visibile solo all'amministratore che lo ha creato. Si integra con l'Admin Inbox e il calendario esistente senza nuove tabelle. Da implementare quando il calendario/agenda è stabile.

---

## 13. Test (Pest)

Convenzioni: `GestionaleTestHelpers.php` con `require_once`, SQLite in-memory, `Event::fake()` / `Notification::fake()`.

Casi da coprire:

**Permessi e multi-tenancy**
- Condomino del condominio crea commento sulla propria segnalazione → ✅
- Condomino crea commento su segnalazione di un altro condominio → ❌ 403
- Con `commenti_abilitati = false` la creazione è negata; commenti esistenti visibili
- Solo autore o moderatore modifica/elimina

**Notifiche (Notification::fake())**
- Alla creazione, tutti i destinatari corretti ricevono `NuovoCommentoNotification`
- L'autore del commento NON riceve notifica

**Admin Inbox → `tests/Feature/ModuloCommenti/AdminInboxTest.php`**

File Pest dedicato. Casi coperti per gruppo:

*Creazione e aggregazione*: primo commento crea Evento con tutti i campi corretti; secondo aggrega senza duplicati (contatore 2, anteprima e `updated_at` aggiornati); metadata salvano autore e anteprima; titolo usa `titoloInbox()`.

*Priorità dinamica*: urgente → `'alta'`; normale → `'normale'`; segnalazione diventa urgente tra un commento e il successivo → Evento scala; **la priorità non scende mai automaticamente** (richiede azione esplicita dell'admin).

*Riapertura dopo chiusura*: commento su thread completato crea nuovo Evento; il nuovo Evento riparte con `contatore = 1`.

*Retrocompatibilità — isolamento*: non tocca eventi con `tipo = NULL` (F24, rate, scadenze legacy); non tocca eventi con tipo diverso; con N eventi legacy presenti, viene creato esattamente un Evento commento.

*Chiusura dal controller*: `segnalazioni.show` marca completato al primo visit; nessun Evento creato se non ci sono commenti aperti; `is_completed` rimane `true` al visit successivo (idempotente).

*Integrazione listener*: `InviaNotificheCommento` richiama `sincronizza` esattamente una volta; notifiche mail/database e Evento inbox creati nella stessa esecuzione.

**Moderazione e soft delete**
- Commento nascosto/soft-deleted mostra placeholder, non compare nella relazione `commenti()`
- Chiusura thread: `toggle` imposta `commenti_abilitati = false`, commenti esistenti restano

> Nota SQLite: gli `enum` diventano `varchar`, nessun problema per `stato` e `priorita`.

---

## 14. Ordine di implementazione consigliato

1. Migration `commenti` + flag `commenti_abilitati` su `segnalazioni_guasti`.
2. Contratto `Commentable` + trait `HasComments` + modello `Commento`.
3. Applicazione a `Segnalazione` (implementa il contratto, inclusi `prioritaCommento()`, `titoloInbox()`, `utenteHaAccessoAiCommenti()`).
4. Permessi Spatie + `CommentoPolicy` (con delega a `utenteHaAccessoAiCommenti`).
5. `CommentoService` + evento `CommentoCreato` (con stato `'in_attesa'` per non-admin).
6. Controller + rotte + authorize.
7. `CommentoNotificationService` + `NuovoCommentoNotification` (mail + database).
8. Enum `EventoTipo` + `InboxService::createTask()` (se non già presente nel progetto).
9. **`CommentoEventoService`** (Admin Inbox, aggregazione, priorità) — usa `InboxService::createTask()` per la creazione e upsert diretto per l'aggiornamento.
10. Listener `InviaNotificheCommento` (orchestra mail/database + Admin Inbox).
11. Chiusura Evento in `SegnalazioneController::show()` (imposta `is_completed = true` e `completed_at`).
12. Frontend Vue (stream + form + moderazione + CTA inbox).
13. Test Pest (`AdminInboxTest.php` + `NotificheCommentiTest.php`).
14. (Successivo) Estensioni social: menzioni, reazioni, letti/non letti.

---

## 15. Decisioni confermate

1. **Autore**: `user_id` con display name via relazione `anagrafica`.
2. **Threading**: lista piatta cronologica — `parent_id` rimandato a necessità futura.
3. **Allegati**: gestiti via `documenti` polimorfica a livello entità; nessuna gestione dentro i commenti.
4. **Admin Inbox**: Evento HIDDEN + requires_action, aggregato per commentable, in aggiunta (non in sostituzione) alle notifiche mail/database.
5. **Priorità**: derivata da `prioritaCommento()` sul modello — segnalazione urgente → Evento `alta`.