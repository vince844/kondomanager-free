<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\RuoloOggettoPunto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una scheda di cui parla un punto, o da cui nasce (§1.1): `oggetto_tipo` è il nome stabile del registro (`fattura_passiva`, `piano_rate`, `gestione`…), mai
 * la classe PHP. Serve alla proposta di «Scegli la delibera» (livelli 1 e 2 di R6) e al memo. Fuori dal registro: si scrive solo dentro il campo «Parla di»
 * del punto.
 */
class OggettoDelPunto extends Model
{
    protected $table = 'assemblee_punti_oggetti';

    protected $fillable = ['punto_id', 'oggetto_tipo', 'oggetto_id', 'ruolo'];

    protected $casts = [
        'punto_id' => 'integer',
        'oggetto_id' => 'integer',
        'ruolo' => RuoloOggettoPunto::class,
    ];

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }
}
