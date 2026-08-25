<?php

namespace App\Traits;

use App\Models\Commento;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasComments
{
    /**
     * Commenti pubblicati, in ordine cronologico (per la UI).
     * Usata nella view: mostra solo quelli approvati.
     */
    public function commenti(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable')
                    ->where('stato', 'pubblicato')
                    ->oldest();
    }

    /**
     * Tutti i commenti in qualsiasi stato (anche soft-deleted esclusi).
     * Usata per creazione e moderazione.
     */
    public function tuttiICommenti(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable');
    }

    /**
     * Commenti in attesa di approvazione.
     * Usata per il badge di moderazione nell'area admin.
     */
    public function commentiInAttesa(): MorphMany
    {
        return $this->morphMany(Commento::class, 'commentable')
                    ->where('stato', 'in_attesa')
                    ->oldest();
    }

    /**
     * Default: priorità normale. Override nei modelli che hanno un campo urgenza.
     */
    public function prioritaCommento(): string
    {
        return 'normale';
    }

    /**
     * Default: "NomeClasse #id". Override per titoli leggibili nell'inbox.
     */
    public function titoloInbox(): string
    {
        return class_basename($this) . ' #' . $this->getKey();
    }

    /**
     * Default: qualsiasi membro del condominio ha accesso.
     * Override nei modelli con regole specifiche (es. Segnalazioni private/assegnate).
     */
    public function utenteHaAccessoAiCommenti(\App\Models\User $user): bool
    {
        return $user->anagrafica?->condomini()->whereKey($this->condominioId())->exists() ?? false;
    }
}
