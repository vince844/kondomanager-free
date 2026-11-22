<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il testo di adesso di una parte del verbale: `intero` (il verbale generato dal modello, poi corretto) o `punto:<id>` (la discussione di un punto, scritta in
 * sala o nella pagina del verbale). `documento` è la struttura dell'editor già validata dal server, mai HTML; `revisione` cresce a ogni salvataggio; `scrittori`
 * tiene le ultime revisioni con il dispositivo che le ha scritte, perché le due versioni ci sono solo quando dopo la base ha scritto un altro dispositivo.
 *
 * Fuori dal registro delle modifiche: il registro scrive le versioni ({@see VersioneDelVerbale}), non ogni salvataggio.
 */
class ParteDelVerbale extends Model
{
    protected $table = 'assemblee_verbali_parti';

    protected $fillable = ['verbale_id', 'parte', 'punto_id', 'documento', 'revisione', 'dispositivo', 'scrittori', 'scritta_il', 'aggiornata_da'];

    protected $casts = [
        'verbale_id' => 'integer',
        'punto_id' => 'integer',
        'documento' => 'array',
        'revisione' => 'integer',
        'scrittori' => 'array',
        'scritta_il' => 'datetime',
        'aggiornata_da' => 'integer',
    ];

    public function verbale(): BelongsTo
    {
        return $this->belongsTo(VerbaleDellAssemblea::class, 'verbale_id');
    }

    public function punto(): BelongsTo
    {
        return $this->belongsTo(PuntoDellOrdineDelGiorno::class, 'punto_id');
    }
}
