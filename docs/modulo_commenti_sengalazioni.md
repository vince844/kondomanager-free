# Modulo Commenti — Guida Architetturale

**Progetto:** Kondomanager
**Stack:** Laravel 12 · Vue 3 · Inertia · MySQL 8 (SQLite in-memory per test) · Spatie Permissions · Pest
**Scope iniziale:** commenti sulle Segnalazioni Guasto, con architettura polimorfica pronta per Comunicazioni, Delibere, Documenti, ecc.

---

## 1. Obiettivo e principi

Introdurre un livello "social" riutilizzabile: un primitivo unico (`commenti`) attaccabile a qualsiasi entità tramite relazione polimorfica, sul modello "core gratuito e generico, tanti consumatori" già adottato altrove nel progetto.

Principi guida:

- **Un solo primitivo, tanti consumatori.** Nessuna tabella `commenti_segnalazioni` dedicata: una tabella polimorfica.
- **Il modulo non conosce le regole di dominio.** Decisioni come "i commenti sono abilitati?" le prende il modello commentabile tramite contratto, non il modulo.
- **Multi-tenancy esplicito.** Ogni commento porta il `condominio_id`; il permesso non basta, serve appartenenza al condominio.
- **Immutabilità/audit.** Niente delete duri: soft delete + stato di moderazione, coerente con la filosofia ledger-centric.
- **Lista piatta.** Conversazione cronologica, nessun threading annidato.

---

## 2. Decisioni architetturali (confermate)

| Decisione | Scelta |
|---|---|
| Tabella | Polimorfica unica `commenti` |
| Threading | **Lista piatta cronologica**, nessuna risposta annidata (no `parent_id`) |
| Autore | `user_id` → `users`; display name via relazione `anagrafica` |
| Flag "commenti abilitati" | Colonna sul modello, esposta via contratto `Commentable` |
| Comportamento alla disabilitazione | I commenti esistenti restano **visibili in sola lettura**; si bloccano solo i nuovi |
| Cancellazione | Soft delete + `stato` (`pubblicato`/`nascosto`) |
| Allegati/foto | Gestiti via tabella `documenti` polimorfica **esistente**, a livello entità. Nessuna tabella allegati dedicata ai commenti |
| Notifiche | Mail + `database`, in coda |

> **Nota threading.** Se in futuro un consumatore (es. Comunicazioni) richiedesse risposte annidate, basta aggiungere una colonna `parent_id` nullable: migration non distruttiva, nessun debito introdotto adesso.

---

## 3. Schema database

### 3.1 Tabella `commenti`

```php
Schema::create('commenti', function (Blueprint $table) {
    $table->id();

    // Relazione polimorfica → commentable_type + commentable_id (+ indice composto)
    $table->morphs('commentable');

    // Autore (utente autenticato)
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // Denormalizzazione per scoping multi-tenancy
    $table->foreignId('condominio_id')
          ->nullable()
          ->constrained()
          ->cascadeOnDelete();

    $table->text('corpo');

    // Moderazione (no delete duri)
    $table->enum('stato', ['pubblicato', 'nascosto'])->default('pubblicato');
    $table->foreignId('moderato_da')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('moderato_at')->nullable();

    // Indicatore "modificato"
    $table->timestamp('modificato_at')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->index(['commentable_type', 'commentable_id', 'stato']);
    $table->index('condominio_id');
});
```

> Nessun `parent_id`: la conversazione è una lista piatta cronologica.

### 3.2 Flag sul modello commentabile

```php
Schema::table('segnalazioni_guasti', function (Blueprint $table) {
    $table->boolean('commenti_abilitati')->default(true);
});
```

> Adatta il nome tabella a quello reale (`segnalazioni_guasti` assunto).

### 3.3 Allegati — nessuna tabella nuova

I commenti restano **solo testo**. Foto e documenti di una segnalazione si attaccano alla `SegnalazioneGuasto` tramite la tabella polimorfica `documenti` già esistente (relazione `documentable`).

Se in futuro servisse legare un documento a un **singolo commento**, non occorre una tabella nuova: basta rendere `Commento` documentable riusando lo stesso morph. Decisione comoda adesso e a prova di futuro.

---

## 4. Modelli, contratto e trait

### 4.1 Contratto `Commentable`

```php
namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Commentable
{
    public function commenti(): MorphMany;

    /** Il modello decide se accetta nuovi commenti. */
    public function commentiAbilitati(): bool;

    /** Per la denormalizzazione del condominio_id sul commento. */
    public function condominioId(): ?int;
}
```

### 4.2 Trait `HasComments`

```php
namespace App\Concerns;

use App\Models\Commento;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    /** Commenti visibili, in ordine cronologico (per la UI). */
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

### 5.2 Policy — il punto critico è `create`

```php
class CommentoPolicy
{
    // L'amministratore bypassa i controlli granulari
    public function before(User $user, string $ability): ?bool
    {
        return $user->can('commenti.modera') ? true : null;
    }

    public function create(User $user, Commentable $commentable): bool
    {
        return $user->can('commenti.crea')
            && $commentable->commentiAbilitati()                  // (1) thread aperto
            && $this->appartieneAlCondominio($user, $commentable); // (2) multi-tenancy
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

    private function appartieneAlCondominio(User $user, Commentable $commentable): bool
    {
        $condominioId = $commentable->condominioId();
        return $condominioId !== null
            && $user->condomini()->whereKey($condominioId)->exists();
    }
}
```

> **Questa è la trappola multi-tenancy.** Il permesso `commenti.crea` da solo non isola i condomini: senza il check (2), un condomino con permesso commenterebbe segnalazioni altrui. Adatta `$user->condomini()` alla relazione reale (via anagrafica/pivot).

---

## 6. Servizio di creazione

Centralizza la denormalizzazione del `condominio_id` e la sanitizzazione.

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
            ]);

            event(new CommentoCreato($commento));

            return $commento;
        });
    }

    private function sanitizza(string $corpo): string
    {
        // Testo semplice: niente HTML. (In futuro: markdown ristretto.)
        return trim(strip_tags($corpo));
    }
}
```

---

## 7. Notifiche

Canali: `mail` + `database`. In coda. Logica destinatari centralizzata.

```php
class CommentoNotificationService
{
    public function destinatari(Commento $commento): Collection
    {
        $utenti = collect();

        // 1. Autore dell'entità commentata (es. chi ha aperto la segnalazione)
        $utenti->push($commento->commentable->autore ?? null);

        // 2. Amministratori del condominio
        $utenti = $utenti->merge(
            $this->amministratoriDel($commento->condominio_id)
        );

        // 3. Altri partecipanti alla conversazione (chi ha già commentato l'entità)
        $utenti = $utenti->merge(
            $this->partecipanti($commento->commentable)
        );

        return $utenti
            ->filter()
            ->unique('id')
            ->reject(fn ($u) => $u->id === $commento->user_id); // mai notificare l'autore
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

Listener sull'evento `CommentoCreato`:

```php
class InviaNotificheCommento
{
    public function __construct(private CommentoNotificationService $service) {}

    public function handle(CommentoCreato $evento): void
    {
        $destinatari = $this->service->destinatari($evento->commento);

        Notification::send($destinatari, new NuovoCommentoNotification($evento->commento));
    }
}
```

> **Futuro:** tabella `preferenze_notifiche` per opt-out per-tipo (immediata/digest/disattivata). Per ora notifica sempre i partecipanti.

---

## 8. Moderazione

- **Nascondere** un commento: `stato = 'nascosto'`, valorizza `moderato_da`/`moderato_at`. In UI compare il placeholder "Commento rimosso da un amministratore".
- **Eliminare**: soft delete (`deleted_at`). Stesso placeholder.
- **Chiudere il thread**: toggle `commenti_abilitati` sul modello. I commenti esistenti restano in sola lettura.

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

## 9. Controller e rotte

Annida i commenti sotto la risorsa commentabile.

```php
// routes/web.php
Route::post('segnalazioni/{segnalazione}/commenti', [CommentoController::class, 'store']);
Route::patch('commenti/{commento}', [CommentoController::class, 'update']);
Route::delete('commenti/{commento}', [CommentoController::class, 'destroy']);
Route::post('commenti/{commento}/modera', [CommentoController::class, 'modera']);
Route::patch('segnalazioni/{segnalazione}/commenti/toggle', [CommentoController::class, 'toggle']);
```

Nel `store`: `$this->authorize('create', [Commento::class, $segnalazione]);` prima di chiamare il `CommentoService`.

Nella view della segnalazione (Inertia), carica eager per evitare N+1:

```php
$segnalazione->load(['commenti.autore']);
```

E nella lista segnalazioni, badge contatore a costo zero:

```php
SegnalazioneGuasto::withCount('commenti')->get();
```

---

## 10. Frontend (Vue/Inertia) — note rapide

- **Mai `v-html`** sul corpo del commento: rendering come testo, Vue fa l'escape automatico.
- Componenti: `ListaCommenti.vue` (stream cronologico), `Commento.vue`, `FormCommento.vue`.
- Mostra "modificato" se `modificato_at !== null`.
- Disabilita il form se `commenti_abilitati === false`, mostrando i commenti esistenti in sola lettura.
- Pannello moderazione visibile solo con permesso `commenti.modera` (passa il flag da backend, non fidarti del frontend per l'autorizzazione).

---

## 11. Estensioni future (solo annotate)

- **Menzioni `@utente`**: parsing del corpo, notifica dedicata, tabella `commento_menzioni`. Risolvono il targeting senza bisogno di threading.
- **Reazioni**: tabella polimorfica `reazioni` (riusa lo stesso pattern morph).
- **Letti / non letti**: pivot `commento_letture(user_id, commento_id, letto_at)` per badge "non letti".
- **Rate limiting** sulla creazione (anti-spam).
- **Allegati per-commento**: se mai servisse, rendere `Commento` documentable sulla `documenti` esistente — nessuna tabella nuova.
- **Threading**: aggiungere `parent_id` nullable solo se un consumatore lo richiede.

---

## 12. Test (Pest)

Convenzioni allineate al progetto: helper condivisi in `GestionaleTestHelpers.php` con `require_once`, SQLite in-memory, `Event::fake()` dove servono record DB per i listener.

Casi minimi da coprire:

- Un condomino del condominio può creare un commento su una segnalazione del **proprio** condominio.
- Un condomino **non** può commentare segnalazioni di un **altro** condominio (anche con permesso `commenti.crea`).
- Con `commenti_abilitati = false` la creazione è negata, ma i commenti esistenti restano visibili.
- Solo l'autore (o chi ha `commenti.modera`) può modificare/eliminare.
- Alla creazione vengono notificati i destinatari corretti, **escluso** l'autore (`Notification::fake()`).
- Un commento nascosto/soft-deleted mostra il placeholder e non compare nella relazione `commenti()`.

> Nota SQLite: gli `enum` diventano `varchar`, nessun problema per `stato`.

---

## 13. Ordine di implementazione consigliato

1. Migration `commenti` + flag `commenti_abilitati` su `segnalazioni_guasti`.
2. Contratto `Commentable` + trait `HasComments` + modello `Commento`.
3. Applicazione a `SegnalazioneGuasto` (implementa il contratto).
4. Permessi Spatie + `CommentoPolicy` (con il check multi-tenancy).
5. `CommentoService` + evento `CommentoCreato`.
6. Controller + rotte + authorize.
7. Frontend Vue (stream + form + moderazione).
8. Notifiche (notification + listener + servizio destinatari).
9. Test Pest.
10. (Successivo) Estensioni social: menzioni, reazioni, letti/non letti.

---

## Decisioni confermate

1. **Autore**: `user_id` con display name via relazione `anagrafica`.
2. **Threading**: lista piatta cronologica, nessuna risposta annidata. `parent_id` rimandato a eventuale necessità futura (migration nullable non distruttiva).
3. **Allegati**: gestiti via tabella `documenti` polimorfica esistente a livello entità; nessuna gestione allegati dentro i commenti.