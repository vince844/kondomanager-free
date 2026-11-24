<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComunicazioneLettura extends Model
{
    // Nota E2 della decisione 313: senza `HasFactory`, perché la fabbrica non c'è (le prove scrivono le righe dal database).
    protected $table = 'comunicazione_letture';

    protected $fillable = [
        'comunicazione_id',
        'user_id',
        'aperta_il',
        'presa_visione_il',
    ];

    protected $casts = [
        'aperta_il' => 'datetime',
        'presa_visione_il' => 'datetime',
    ];

    public function comunicazione(): BelongsTo
    {
        return $this->belongsTo(Comunicazione::class, 'comunicazione_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
