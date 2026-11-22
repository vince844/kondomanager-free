<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Assemblee;

use App\Enums\Assemblee\ComePresente;
use App\Models\Anagrafica;
use App\Traits\ContaNelGesto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un intervallo di presenza in una seduta: chi (`chiave`: `anagrafica:<id>`, o `delega:<id>` per il delegato fuori dall'anagrafica), come («In sala»,
 * «In collegamento» con il motivo quando l'avviso non lo prevede), da quando a quando; il collegamento caduto è un'uscita, il ritorno una riga nuova. Chi
 * rappresenta le unità in comproprietà (`rappresenta`), e gli altri contitolari della testa entrati con lui, presenti senza voto (`insieme`, rilievo C53:
 * escono con la riga). Chi è presente per delega non ha righe sue: lo dice la delega valida con il delegato presente.
 *
 * «Solo gesto» nel registro: nasce e si chiude nei gesti della sala.
 */
class PresenzaInSala extends Model
{
    use ContaNelGesto;

    protected $table = 'assemblee_presenze';

    protected $fillable = ['seduta_id', 'chiave', 'anagrafica_id', 'nome', 'come', 'entrata_il', 'uscita_il', 'caduto', 'rappresenta', 'motivo_collegamento',
        'gesto_entrata', 'gesto_uscita', 'insieme'];

    protected $casts = [
        'seduta_id' => 'integer',
        'anagrafica_id' => 'integer',
        'come' => ComePresente::class,
        'entrata_il' => 'datetime',
        'uscita_il' => 'datetime',
        'caduto' => 'boolean',
        'rappresenta' => 'array',
        'insieme' => 'array',
    ];

    public function seduta(): BelongsTo
    {
        return $this->belongsTo(SedutaDellAssemblea::class, 'seduta_id');
    }

    public function anagrafica(): BelongsTo
    {
        return $this->belongsTo(Anagrafica::class);
    }
}
