<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\EsitoDelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il libro dei gesti della sala, la base della tenuta senza rete: ogni gesto con il suo numero (scritto dal dispositivo, unico: un gesto che arriva due
 * volte conta una volta), il dispositivo, il tipo e i dati, la versione da cui è partito, l'ora del gesto (corretta con lo scarto dell'orologio) e quella
 * del dispositivo, l'arrivo, l'esito; per le due versioni il conflitto e chi ha scelto. Lo scrive solo {@see \App\Services\Assemblee\Sala\GestiDellaSala}.
 *
 * Fuori dal registro: è esso stesso un libro dei gesti, e ogni gesto applicato scrive la sua riga.
 */
class GestoDellaSala extends Model
{
    protected $table = 'assemblee_gesti';

    protected $fillable = ['assemblea_id', 'gesto', 'dispositivo', 'tipo', 'dati', 'base', 'segnato_il', 'orologio', 'arrivato_il', 'esito', 'messaggio',
        'conflitto', 'scelto_il', 'scelto_da', 'utente_id'];

    protected $casts = [
        'assemblea_id' => 'integer',
        'dati' => 'array',
        'base' => 'integer',
        'segnato_il' => 'datetime',
        'orologio' => 'datetime',
        'arrivato_il' => 'datetime',
        'esito' => EsitoDelGesto::class,
        'conflitto' => 'array',
        'scelto_il' => 'datetime',
        'scelto_da' => 'integer',
        'utente_id' => 'integer',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }
}
