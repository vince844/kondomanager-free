<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una rata del calendario di un accordo di rientro: il numero, il giorno e l'importo in centesimi. */
class AccordoRientroScadenza extends Model
{
    protected $table = 'accordo_rientro_scadenze';

    protected $fillable = ['accordo_rientro_id', 'numero', 'data', 'importo'];

    protected $casts = [
        'accordo_rientro_id' => 'integer',
        'numero' => 'integer',
        'data' => 'date',
        'importo' => 'integer',
    ];

    public function accordo(): BelongsTo
    {
        return $this->belongsTo(AccordoRientro::class, 'accordo_rientro_id');
    }
}
