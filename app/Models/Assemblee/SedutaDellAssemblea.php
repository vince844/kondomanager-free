<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\ConvocazioneSeduta;
use App\Enums\Assemblee\EsitoSeduta;
use App\Traits\RegistraLeModifiche;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una data dell'avviso (§1.1): la prima e la seconda convocazione, e le date di prosecuzione della stessa convocazione (art. 66 c. 5 disp. att.), con
 * `numero` 2, 3… `fissata_il` è l'ora **come si legge** a Roma, come l'agenda (`DateHelper::comeSiLegge`): l'avviso dice «alle 18:30». Compare nella
 * «Storia» dell'assemblea.
 *
 * La sala scrive l'apertura e la chiusura (istanti, in UTC come gli altri), l'esito, il presidente e il segretario, il controllo dell'apertura, la
 * fotografia della seduta con la sua impronta, e se è stata registrata dal foglio della sala.
 */
class SedutaDellAssemblea extends Model
{
    use RegistraLeModifiche;

    protected $table = 'assemblee_sedute';

    protected $fillable = ['assemblea_id', 'convocazione', 'numero', 'fissata_il', 'luogo', 'aperta_il', 'chiusa_il', 'esito', 'presidente', 'segretario',
        'apertura', 'fotografia', 'impronta_fotografia', 'dal_foglio', 'nel_libro_il'];

    protected $casts = [
        'assemblea_id' => 'integer',
        'numero' => 'integer',
        'convocazione' => ConvocazioneSeduta::class,
        'fissata_il' => 'datetime',
        'aperta_il' => 'datetime',
        'chiusa_il' => 'datetime',
        'esito' => EsitoSeduta::class,
        'presidente' => 'array',
        'segretario' => 'array',
        'apertura' => 'array',
        'fotografia' => 'array',
        'dal_foglio' => 'boolean',
        'nel_libro_il' => 'date:Y-m-d',
    ];

    public function assemblea(): BelongsTo
    {
        return $this->belongsTo(Assemblea::class, 'assemblea_id');
    }

    /** Le presenze della seduta, un intervallo per riga. */
    public function presenze(): HasMany
    {
        return $this->hasMany(PresenzaInSala::class, 'seduta_id')->orderBy('entrata_il')->orderBy('id');
    }

    /** I voti dei punti in questa seduta. */
    public function votazioni(): HasMany
    {
        return $this->hasMany(VotazioneDelPunto::class, 'seduta_id')->orderBy('id');
    }

    public function dichiarazioni(): HasMany
    {
        return $this->hasMany(DichiarazioneAVerbale::class, 'seduta_id')->orderBy('id');
    }
}
