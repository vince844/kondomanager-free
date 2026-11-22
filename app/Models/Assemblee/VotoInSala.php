<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\RuoloDiVoto;
use App\Enums\Assemblee\SceltaDiVoto;
use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il voto di una testa su una votazione: chi vota e con quale ruolo, i millesimi della testa su quel punto (centomillesimi), il voto, il segno «In
 * conflitto» con la nota (il conflitto d'interessi non toglie nessuno dal conto: Cass. 1853/2018). Un voto non si registra mai due volte: unico su
 * votazione e testa; `versione` cresce a ogni correzione.
 *
 * «Solo gesto» nel registro: dentro i gesti della sala e la riga della conferma.
 */
class VotoInSala extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_voti';

    protected $fillable = ['votazione_id', 'testa', 'anagrafica_id', 'nome', 'ruolo', 'millesimi', 'voto', 'decisione', 'in_conflitto', 'nota_conflitto',
        'versione', 'gesto'];

    protected $casts = [
        'votazione_id' => 'integer',
        'anagrafica_id' => 'integer',
        'millesimi' => 'integer',
        'ruolo' => RuoloDiVoto::class,
        'voto' => SceltaDiVoto::class,
        'decisione' => SceltaDiVoto::class,
        'in_conflitto' => 'boolean',
        'versione' => 'integer',
    ];

    public function votazione(): BelongsTo
    {
        return $this->belongsTo(VotazioneDelPunto::class, 'votazione_id');
    }
}
