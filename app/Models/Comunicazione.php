<?php

namespace App\Models;

use App\Enums\Permission;
use App\Helpers\SlugHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Comunicazione extends Model
{
    use HasFactory;
    
    protected $table = 'comunicazioni';

    protected $fillable = [
        'updated_by',
        'subject',
        'description',
        'created_by',
        'priority',
        'is_featured',
        'is_private',
        'is_published',
        'is_approved',
        'can_comment',
        'slug',
        'reference',
    ];

    // Add this method to the model
    protected static function boot()
    {
        parent::boot();

        // Listen to the 'creating' event to generate the slug before saving
        static::creating(function ($comunicazione) {
            // Generate the slug using the SlugHelper
            $comunicazione->slug = SlugHelper::generateSlug($comunicazione->subject);
        });
    }

    /**
     * The condomini that belong to the comunicazione.
     */
    public function condomini()
    {
        /* return $this->belongsToMany(Condominio::class, 'comunicazione_condominio')->withTimestamps(); */
        return $this->belongsToMany(Condominio::class);
    }

    /**
     * The anagrafiche that belong to the comunicazione.
     */
    public function anagrafiche()
    {
        return $this->belongsToMany(Anagrafica::class, 'anagrafica_comunicazione')->withTimestamps();
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

    /**
     * Le comunicazioni che `$user` vede fuori dal pannello. **È la regola sola**: la usano la scheda
     * (`ComunicazionePolicy::show`) e l'elenco e i conteggi del portale (`ComunicazioneService`); gli
     * avvisi usano la gemella `anagraficheCheLaVedono()`. Stessa forma di
     * `Segnalazione::scopeVisibiliNelPortale()`, con i condomìni collegati al posto del condominio:
     *
     * - **lo stato**: pubblicata e approvata, oppure l'utente ne è l'autore (Coda 198: una bozza o
     *   una comunicazione in moderazione non si apre per id);
     * - **a chi è indirizzata**: l'autore, se è ancora in uno dei condomìni collegati o se la
     *   comunicazione è privata; le anagrafiche collegate; i condomìni collegati solo se non ne ha di
     *   collegate **e non è privata** (la privacy la decide `is_private`, come per le segnalazioni:
     *   Coda 304).
     *
     * Come per le segnalazioni, l'autore di una non privata conta finché la sua anagrafica è in uno
     * dei palazzi della comunicazione (o collegata a lei); una privata resta sempre del suo autore.
     *
     * L'elenco del portale aggiunge «pubblicata e approvata» per tutti, autore compreso (Coda 389).
     *
     * **Il permesso** (decisione 134.4), come per le segnalazioni: la regola qui sopra vale per chi ha
     * «Visualizza comunicazioni»; «Visualizza proprie comunicazioni» apre le proprie e basta
     * (`ComunicazionePolicy::show`), da solo o in più dell'altro; senza nessuno dei due, nessuna.
     */
    public function scopeVisibiliNelPortale(Builder $query, User $user): Builder
    {
        $vedeTutte = $user->hasPermissionTo(Permission::VIEW_COMUNICAZIONI->value);
        $vedeProprie = $user->hasPermissionTo(Permission::VIEW_OWN_COMUNICAZIONI->value);

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
                            ->where(fn (Builder $q) => $q
                                ->where('is_private', true)
                                ->orWhereHas('condomini', fn (Builder $c) => $c->whereIn('condomini.id', $condominioIds))));

                        if ($anagrafica !== null) {
                            $indirizzo->orWhereHas('anagrafiche', fn (Builder $q) => $q->where('anagrafiche.id', $anagrafica->id));
                        }

                        if ($condominioIds !== []) {
                            $indirizzo->orWhere(fn (Builder $q) => $q
                                ->where('is_private', false)
                                ->whereDoesntHave('anagrafiche')
                                ->whereHas('condomini', fn (Builder $c) => $c->whereIn('condomini.id', $condominioIds)));
                        }
                    });
            });

            if ($vedeProprie) {
                $regola->orWhere('created_by', $user->id);
            }
        });
    }

    /** La stessa regola, per una comunicazione sola: è lo scope qui sopra, non una sua copia. */
    public function visibileNelPortaleA(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibiliNelPortale($user)->exists();
    }

    /**
     * Le anagrafiche che la trovano nell'elenco del portale: la platea degli avvisi. Nessuno finché
     * non è pubblicata e approvata (Coda 198); poi l'autore (se è privata, o se la sua anagrafica è
     * ancora in uno dei condomìni collegati), le anagrafiche collegate, e i condomìni collegati solo se
     * non ne ha di collegate e non è privata. Dice dal lato delle persone ciò che
     * `scopeVisibiliNelPortale()` dice dal lato dell'utente.
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
        $condominioIds = $this->condomini()->pluck('condomini.id')->all();

        return Anagrafica::query()
            ->where(function (Builder $q) use ($haCollegate, $condominioIds) {
                $q->whereHas('comunicazioni', fn (Builder $c) => $c->whereKey($this->getKey()));

                if ($this->created_by !== null) {
                    $q->orWhere(function (Builder $autore) use ($condominioIds) {
                        $autore->where('user_id', $this->created_by);

                        if (! $this->is_private) {
                            $autore->whereHas('condomini', fn (Builder $c) => $c->whereIn('condomini.id', $condominioIds));
                        }
                    });
                }

                if (! $this->is_private && ! $haCollegate && $condominioIds !== []) {
                    $q->orWhereHas('condomini', fn (Builder $c) => $c->whereIn('condomini.id', $condominioIds));
                }
            })
            ->pluck('id')
            ->unique()
            ->values();
    }

}
