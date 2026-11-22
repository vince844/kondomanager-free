<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Models\Anagrafica;
use App\Models\Gestionale\RegistroInvio;
use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un convocato, fissato **all'invio** (§4.1): il nome, l'indirizzo e la PEC copiati, le unità e il ruolo di allora, i punti per cui è convocato (il
 * conduttore, decisione 566), il mezzo dell'art. 66 disp. att. `invio_id` è l'ultimo invio con un mezzo dell'art. 66, mai l'email semplice mandata in più
 * (§13, rilievo 15). Prima dell'invio l'elenco si calcola e non si scrive.
 *
 * «Solo gesto» nel registro: le righe nascono dentro il gesto «Convocazione spedita».
 */
class ConvocatoDellAssemblea extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_convocati';

    protected $fillable = ['assemblea_id', 'anagrafica_id', 'nome', 'indirizzo', 'pec', 'unita', 'punti', 'mezzo', 'fissato_il', 'invio_id', 'created_by'];

    protected $casts = [
        'assemblea_id' => 'integer',
        'anagrafica_id' => 'integer',
        'invio_id' => 'integer',
        'unita' => 'array',
        'punti' => 'array',
        'mezzo' => MezzoConvocazione::class,
        'fissato_il' => 'datetime',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }

    /** L'ultimo invio con un mezzo dell'art. 66. */
    public function invio(): BelongsTo
    {
        return $this->belongsTo(RegistroInvio::class, 'invio_id');
    }
}
