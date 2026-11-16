<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
