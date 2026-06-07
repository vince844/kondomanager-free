<?php

namespace App\Models;

use App\Contracts\Commentable;
use App\Traits\HasComments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @method \Illuminate\Database\Eloquent\Relations\MorphMany tuttiICommenti()
 * @method \Illuminate\Database\Eloquent\Relations\MorphMany commentiInAttesa()
 */
class Segnalazione extends Model implements Commentable
{
    use HasFactory, HasComments;

    protected $table = 'segnalazioni';

    protected $fillable = [
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
     * Matrice di accesso ai commenti per Segnalazione.
     * I controlli dell'amministratore avvengono a monte nella Policy.
     */
    public function utenteHaAccessoAiCommenti(User $user): bool
    {
        // Se la segnalazione è privata, solo il creatore ha accesso
        if ($this->is_private) {
            return $this->created_by === $user->id;
        }

        // Se è assegnata a qualcuno, creatore + assegnatario
        if ($this->assigned_to) {
            return $this->created_by === $user->id || $this->assigned_to === $user->id;
        }

        // Altrimenti qualsiasi condomino dello stesso condominio
        return $user->condomini()->whereKey($this->condominio_id)->exists();
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

    public function assignedTo()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function condominio()
    {
        return $this->belongsTo(Condominio::class);
    }
}
