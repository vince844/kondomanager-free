<?php

namespace App\Models;

use App\Contracts\Commentable;
use App\Enums\Permission;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * @method \Illuminate\Database\Eloquent\Relations\MorphMany tuttiICommenti()
 * @method \Illuminate\Database\Eloquent\Relations\MorphMany commentiInAttesa()
 */
class Segnalazione extends Model implements Commentable
{
    use HasFactory, HasComments;

    protected $table = 'segnalazioni';

    protected $fillable = [
        'updated_by',
        'subject',
        'description',
        'created_by',
        'assigned_to',
        'condominio_id',
        'priority',
        'stato',
        'is_resolved',
        'is_locked',
        'is_featured',
        'is_private',
        'is_published',
        'is_approved',
        'can_comment',
    ];

    // -------------------------------------------------------------------------
    // Contratto Commentable
    // -------------------------------------------------------------------------

    /**
     * {@inheritdoc}
     *
     * Implementato tramite il trait HasComments — qui si soddisfa il contratto.
     */
    public function commenti(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable')
                    ->where('stato', 'pubblicato')
                    ->oldest();
    }

    /**
     * I commenti sono abilitati se il flag can_comment è true.
     * I commenti esistenti restano visibili anche quando è false.
     */
    public function commentiAbilitati(): bool
    {
        return (bool) $this->can_comment;
    }

    /**
     * Restituisce l'id del condominio per la denormalizzazione sul commento.
     */
    public function condominioId(): ?int
    {
        return $this->condominio_id;
    }

    /**
     * Mappa la priorità della segnalazione alla priorità dell'Evento in Inbox.
     */
    public function prioritaCommento(): string
    {
        if (in_array($this->priority, ['alta', 'urgente'])) {
            return 'alta';
        }
        return 'normale';
    }

    /**
     * Titolo leggibile per l'Admin Inbox.
     */
    public function titoloInbox(): string
    {
        return $this->subject ?? "Segnalazione #{$this->id}";
    }

    /**
     * Chi riceve gli avvisi delle risposte: chi può aprire la segnalazione, con la stessa regola
     * della scheda (`SegnalazionePolicy::show`, che fuori dal pannello usa `scopeVisibiliNelPortale`).
     *
     * Non è una regola a sé: «chi vede una segnalazione» è scritto in un posto solo (Code 263, 304,
     * 305). Non c'è un ramo per le segnalazioni assegnate, che nessuna pagina può creare (nessuna
     * richiesta accetta `assigned_to`).
     */
    public function utenteHaAccessoAiCommenti(User $user): bool
    {
        return Gate::forUser($user)->allows('show', $this);
    }

    // -------------------------------------------------------------------------
    // Chi la vede fuori dal pannello
    // -------------------------------------------------------------------------

    /**
     * Le segnalazioni che `$user` vede fuori dal pannello. **È la regola sola**: la usano la scheda
     * (`SegnalazionePolicy::show`), l'elenco e i conteggi del portale (`SegnalazioneService`,
     * `SegnalazioniStatsController`), il commento (`CommentoPolicy::create`) e gli avvisi dei
     * commenti; gli avvisi di creazione, modifica e approvazione usano la gemella
     * `anagraficheCheLaVedono()`, che dice la stessa cosa dal lato delle persone.
     *
     * Due condizioni, tutte e due necessarie:
     * - **lo stato**: pubblicata e approvata, oppure l'utente ne è l'autore (Code 198 e 263: una
     *   segnalazione in moderazione o una bozza non si apre per id);
     * - **a chi è indirizzata**: l'autore, se è ancora nel palazzo o se la segnalazione è privata; le
     *   anagrafiche collegate; tutto il condominio solo se non ne ha di collegate **e non è privata**.
     *   La privacy la decide `is_private` (decisione Q1 del 17/10/2026, Coda 304): una privata la
     *   vedono solo l'autore e le persone collegate, anche se i collegamenti sono stati tolti.
     *
     * L'autore di una non privata conta finché la sua anagrafica è nel palazzo della segnalazione (o
     * collegata a lei): staccarla dal condominio è il gesto con cui l'amministratore dice che non ne
     * fa più parte, e da lì la discussione che continua (commenti, avvisi) è di chi nel palazzo c'è.
     * Una privata resta sempre del suo autore: è indirizzata a lui, e nessun altro la vedrebbe.
     *
     * L'elenco del portale aggiunge «pubblicata e approvata» per tutti, autore compreso: le proprie
     * in moderazione l'autore le apre per indirizzo ma non le ritrova in elenco (Coda 389).
     *
     * **Il permesso** (decisione 134.4): la regola qui sopra vale per chi ha «Visualizza segnalazioni».
     * «Visualizza proprie segnalazioni», che dà solo un ruolo su misura, apre le proprie e basta
     * (`SegnalazionePolicy::show`): da solo le proprie, insieme all'altro anche le proprie che la regola
     * non comprende. Senza nessuno dei due, nessuna. Lo scope guarda il permesso: i conteggi e la
     * dashboard del portale, che lo usano, mostrano a quei ruoli solo le segnalazioni che la scheda
     * apre.
     */
    public function scopeVisibiliNelPortale(Builder $query, User $user): Builder
    {
        $vedeTutte = $user->hasPermissionTo(Permission::VIEW_SEGNALAZIONI->value);
        $vedeProprie = $user->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value);

        if (! $vedeTutte && ! $vedeProprie) {
            return $query->whereRaw('1 = 0');
        }

        if (! $vedeTutte) {
            return $query->where('created_by', $user->id);
        }

        $anagrafica = $user->anagrafica;
        $condominioIds = $anagrafica ? $anagrafica->condomini()->pluck('condomini.id')->all() : [];

        return $query->where(function (Builder $regola) use ($user, $anagrafica, $condominioIds, $vedeProprie) {
            $regola->where(function (Builder $vista) use ($user, $anagrafica, $condominioIds) {
                $vista
                    ->where(function (Builder $stato) use ($user) {
                        $stato->where(fn (Builder $q) => $q->where('is_published', true)->where('is_approved', true))
                            ->orWhere('created_by', $user->id);
                    })
                    ->where(function (Builder $indirizzo) use ($user, $anagrafica, $condominioIds) {
                        $indirizzo->where(fn (Builder $autore) => $autore
                            ->where('created_by', $user->id)
                            ->where(fn (Builder $q) => $q->where('is_private', true)->orWhereIn('condominio_id', $condominioIds)));

                        if ($anagrafica !== null) {
                            $indirizzo->orWhereHas('anagrafiche', fn (Builder $q) => $q->where('anagrafiche.id', $anagrafica->id));
                        }

                        if ($condominioIds !== []) {
                            $indirizzo->orWhere(fn (Builder $q) => $q
                                ->where('is_private', false)
                                ->whereIn('condominio_id', $condominioIds)
                                ->whereDoesntHave('anagrafiche'));
                        }
                    });
            });

            if ($vedeProprie) {
                $regola->orWhere('created_by', $user->id);
            }
        });
    }

    /** La stessa regola, per una segnalazione sola: è lo scope qui sopra, non una sua copia. */
    public function visibileNelPortaleA(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibiliNelPortale($user)->exists();
    }

    /**
     * Con «Rendi pubblica» l'autore perde la scheda dal portale? La pagina dell'amministratore lo dice
     * nella conferma, prima del comando (Coda 304).
     *
     * Il comando cambia solo a chi è indirizzata: non più privata, senza persone collegate; lo stato e
     * i permessi restano. Oggi l'autore la apre (`SegnalazionePolicy::show`); dopo, per una non privata,
     * la regola qui sopra lo tiene solo se la sua anagrafica è nel palazzo, a meno che la apra per
     * un'altra strada della policy che il comando non tocca (il pannello, «Visualizza proprie
     * segnalazioni»). Che la risposta data prima sia la scheda che l'autore apre dopo lo verifica,
     * dalle rotte, `tests/Feature/Portale/AvvisiAllAutoreCheNonLaApreTest.php`.
     */
    public function autorePerdeLaSchedaConRendiPubblica(): bool
    {
        $autore = $this->createdBy;

        if (! $this->is_private || $autore === null || Gate::forUser($autore)->denies('show', $this)) {
            return false;
        }

        if ($autore->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value)
            || $autore->hasPermissionTo(Permission::VIEW_OWN_SEGNALAZIONI->value)) {
            return false;
        }

        return ! ($autore->anagrafica?->condomini()->whereKey($this->condominio_id)->exists() ?? false);
    }

    /**
     * Le anagrafiche che la trovano nell'elenco del portale: la platea degli avvisi (Coda 302).
     *
     * Dice dal lato delle persone ciò che `scopeVisibiliNelPortale()` dice dal lato dell'utente, per
     * chi la vede **in elenco**: nessuno finché non è pubblicata e approvata (Coda 198); poi l'autore
     * (se è privata, o se la sua anagrafica è ancora nel palazzo), le anagrafiche collegate, e tutto il
     * condominio solo se non ne ha di collegate e non è privata.
     * Una segnalazione non privata indirizzata a persone avvisa solo loro (decisione Q1). Che le due
     * forme coincidano lo verifica `tests/Feature/Portale/PlateaDegliAvvisiTest.php`.
     *
     * Non filtra per preferenza di notifica né per permesso: lo fa chi manda l'avviso.
     *
     * @return Collection<int, int>
     */
    public function anagraficheCheLaVedono(): Collection
    {
        if (! ($this->is_published && $this->is_approved)) {
            return collect();
        }

        $haCollegate = $this->anagrafiche()->exists();

        return Anagrafica::query()
            ->where(function (Builder $q) use ($haCollegate) {
                $q->whereHas('segnalazioni', fn (Builder $s) => $s->whereKey($this->getKey()));

                if ($this->created_by !== null) {
                    $q->orWhere(function (Builder $autore) {
                        $autore->where('user_id', $this->created_by);

                        if (! $this->is_private) {
                            $autore->whereHas('condomini', fn (Builder $c) => $c->whereKey($this->condominio_id));
                        }
                    });
                }

                if (! $this->is_private && ! $haCollegate) {
                    $q->orWhereHas('condomini', fn (Builder $c) => $c->whereKey($this->condominio_id));
                }
            })
            ->pluck('id')
            ->unique()
            ->values();
    }

    // -------------------------------------------------------------------------
    // Relazioni
    // -------------------------------------------------------------------------

    // Define the many-to-many relationship with Anagrafica
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_segnalazione', 'segnalazione_id', 'anagrafica_id'); 
    }

    // Other relationships (for example, creator, assignee, etc.)
    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Chi ha modificato l'oggetto per ultimo. `null` finché non lo modifica nessuno.
     *
     * Aggiunta nella beta.64 insieme all'avviso di modifica: senza, quell'avviso era costretto a
     * nominare il **creatore**, cioè a dire una cosa falsa su chi aveva fatto cosa.
     */
    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }


    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }
}
