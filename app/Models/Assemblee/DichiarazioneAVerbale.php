<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La breve dichiarazione a verbale di un condòmino (art. 1130 n. 7 c.c.), su un punto o sulla seduta.
 *
 * «Solo gesto» nel registro: nasce in un gesto della sala.
 */
class DichiarazioneAVerbale extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_dichiarazioni';

    protected $fillable = ['seduta_id', 'punto_id', 'anagrafica_id', 'nome', 'testo', 'gesto', 'created_by'];

    protected $casts = [
        'seduta_id' => 'integer',
        'punto_id' => 'integer',
        'anagrafica_id' => 'integer',
    ];

    public function seduta(): BelongsTo
    {
        return $this->belongsTo(SedutaDellAssemblea::class, 'seduta_id');
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }
}
