<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Enums\Assemblee\Maggioranza;
use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un voto su un punto in una seduta (due per il rendiconto: il rendiconto e il riparto, `numero` 1 e 2). «Si vota» ferma il momento (`votata_il`) e gli
 * intervenuti (le teste presenti in quel momento, chi vota per ognuna, i millesimi in centomillesimi): chi entra dopo non conta per quel voto. I conteggi e
 * l'esito proposto li ricalcola il server a ogni voto; «Conferma» scrive l'esito e la delibera. `versione` cresce a ogni cambio: un gesto partito da una
 * versione vecchia che cambia l'esito è in conflitto.
 *
 * «Solo gesto» nel registro: la riga è quella della conferma, con i voti nel lotto.
 *
 * Le note del presidente, che vanno nel verbale: `nota` sul voto del punto (anche quando si vota senza che l'assemblea risulti costituita) e
 * `nota_non_votato` su chi non ha votato. Non cambiano la versione: non toccano i voti né l'esito.
 */
class VotazioneDelPunto extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_votazioni';

    protected $fillable = ['seduta_id', 'punto_id', 'numero', 'oggetto', 'maggioranza', 'maggioranza_libera', 'votata_il', 'intervenuti', 'scelte', 'conteggi',
        'esito_proposto', 'esito', 'motivo', 'confermata_il', 'confermata_da', 'conflitto_determinante', 'delibera_id', 'versione', 'gesto', 'nota', 'nota_non_votato'];

    protected $casts = [
        'seduta_id' => 'integer',
        'punto_id' => 'integer',
        'numero' => 'integer',
        'maggioranza' => Maggioranza::class,
        'maggioranza_libera' => 'array',
        'votata_il' => 'datetime',
        'intervenuti' => 'array',
        'scelte' => 'array',
        'conteggi' => 'array',
        'esito_proposto' => EsitoDelibera::class,
        'esito' => EsitoDelibera::class,
        'confermata_il' => 'datetime',
        'confermata_da' => 'integer',
        'conflitto_determinante' => 'boolean',
        'delibera_id' => 'integer',
        'versione' => 'integer',
    ];

    public function seduta(): BelongsTo
    {
        return $this->belongsTo(SedutaDellAssemblea::class, 'seduta_id');
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }

    public function voti(): HasMany
    {
        return $this->hasMany(VotoInSala::class, 'votazione_id')->orderBy('id');
    }

    public function delibera(): BelongsTo
    {
        return $this->belongsTo(Delibera::class, 'delibera_id');
    }

    public function eConfermata(): bool
    {
        return $this->confermata_il !== null;
    }
}
