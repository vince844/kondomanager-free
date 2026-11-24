<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Errori;

use App\Models\User;
use App\Support\Errori\RegistroDegliErrori;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una volta di un errore ({@see Errore}): il codice di sei caratteri che la pagina d'errore mostra e che il registro scrive nella stessa riga, l'ora, la
 * pagina con i segnaposto della rotta (mai l'indirizzo vero), il ruolo di chi l'ha incontrata (mai il nome), le versioni, il messaggio ripulito, la traccia
 * senza valori. Se qualcuno ha avvisato lo studio dalla pagina d'errore: chi, quando, con quale ruolo, e la sua nota, che resta nella scheda e non entra nel
 * rapporto né nell'email.
 *
 * La pulizia di ogni notte (`model:prune`) toglie le volte di più di {@see RegistroDegliErrori::GIORNI} giorni.
 */
class VoltaDellErrore extends Model
{
    use MassPrunable;

    protected $table = 'errori_volte';

    protected $fillable = [
        'errore_id', 'codice', 'avvenuto_il', 'metodo', 'pagina', 'rotta', 'azione', 'ruolo', 'versione', 'versione_php', 'versione_laravel', 'database',
        'classe', 'messaggio', 'causa', 'file', 'riga', 'traccia', 'avvisato_da', 'avvisato_il', 'avvisato_ruolo', 'nota_avviso',
    ];

    protected $casts = [
        'avvenuto_il' => 'datetime',
        'avvisato_il' => 'datetime',
        'riga' => 'integer',
        'traccia' => 'array',
    ];

    public function errore(): BelongsTo
    {
        return $this->belongsTo(Errore::class, 'errore_id');
    }

    public function avvisatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avvisato_da');
    }

    public function prunable(): Builder
    {
        return static::query()->where('avvenuto_il', '<', now()->subDays(RegistroDegliErrori::GIORNI));
    }
}
