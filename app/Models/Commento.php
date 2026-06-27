<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Commento extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'commenti';

    protected $fillable = [
        'commentable_type',
        'commentable_id',
        'user_id',
        'condominio_id',
        'corpo',
        'stato',
        'moderato_da',
        'moderato_at',
        'modificato_at',
    ];

    protected $casts = [
        'modificato_at' => 'datetime',
        'moderato_at'   => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Relazioni
    // -------------------------------------------------------------------------

    /**
     * L'entità commentata (SegnalazioneGuasto, Comunicazione, ecc.).
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * L'utente che ha scritto il commento.
     */
    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * L'utente che ha moderato il commento (nascosto o approvato).
     */
    public function moderatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderato_da');
    }

    /**
     * Il condominio di appartenenza (denormalizzato per scoping).
     */
    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Indica se il commento è stato modificato dopo la creazione.
     */
    public function eModificato(): bool
    {
        return $this->modificato_at !== null;
    }

    /**
     * Indica se il commento è in attesa di approvazione.
     */
    public function eInAttesa(): bool
    {
        return $this->stato === 'in_attesa';
    }

    /**
     * Indica se il commento è stato nascosto da un moderatore.
     */
    public function eNascosto(): bool
    {
        return $this->stato === 'nascosto';
    }

    /**
     * Indica se il commento è visibile pubblicamente.
     */
    public function ePubblicato(): bool
    {
        return $this->stato === 'pubblicato';
    }
}
