<?php

namespace App\Contracts;

use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Commentable
{
    /**
     * Commenti visibili (stato 'pubblicato'), in ordine cronologico.
     * Usata per la visualizzazione pubblica.
     */
    public function commenti(): MorphMany;

    /**
     * Tutti i commenti in qualsiasi stato (esclusi soft-deleted).
     * Usata da CommentoService per la creazione e dalla moderazione.
     */
    public function tuttiICommenti(): MorphMany;

    /**
     * Commenti in attesa di approvazione.
     * Usata per il badge di moderazione nell'area admin.
     */
    public function commentiInAttesa(): MorphMany;

    /**
     * Il modello decide se accetta nuovi commenti.
     * I commenti esistenti restano visibili anche se false.
     */
    public function commentiAbilitati(): bool;

    /**
     * Per la denormalizzazione del condominio_id sul commento.
     */
    public function condominioId(): ?int;
}
