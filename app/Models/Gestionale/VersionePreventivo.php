<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use App\Enums\Assemblee\StatoVersionePreventivo;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Il preventivo «fermato» per l'assemblea (§0.4 e §1.1 del progetto della tappa 2), nella forma minima di {@see RendicontoVersione}: un numero per esercizio,
 * lo stato, le cifre in **centesimi** con l'impronta, i PDF conservati come documenti della versione. Una versione non si riscrive: quella di prima resta,
 * «sostituita». L'approvazione dalla delibera viene dopo.
 */
class VersionePreventivo extends Model
{
    protected $table = 'preventivo_versioni';

    protected $fillable = [
        'condominio_id', 'esercizio_id', 'gestione_id', 'piano_rate_id', 'numero', 'stato', 'cifre', 'impronta', 'pdf', 'punto_id', 'creata_da',
    ];

    protected $casts = [
        'condominio_id' => 'integer',
        'esercizio_id' => 'integer',
        'gestione_id' => 'integer',
        'piano_rate_id' => 'integer',
        'numero' => 'integer',
        'punto_id' => 'integer',
        'creata_da' => 'integer',
        'stato' => StatoVersionePreventivo::class,
        'cifre' => 'array',
        'pdf' => 'array',
    ];

    public function condominio(): BelongsTo
    {
        return $this->belongsTo(Condominio::class);
    }

    public function esercizio(): BelongsTo
    {
        return $this->belongsTo(Esercizio::class);
    }

    public function gestione(): BelongsTo
    {
        return $this->belongsTo(Gestione::class);
    }

    public function pianoRate(): BelongsTo
    {
        return $this->belongsTo(PianoRate::class);
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }

    public function creataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creata_da');
    }

    public function documenti(): MorphMany
    {
        return $this->morphMany(Documento::class, 'documentable');
    }
}
