<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnagraficaFornitore extends Model
{
    protected $table = 'anagrafica_fornitore';

    protected $fillable = [
        'fornitore_id',
        'anagrafica_id',
        'ruolo',
        'referente_principale',
    ];

    // Relazioni
    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class);
    }

    public function anagrafica()
    {
        return $this->belongsTo(Anagrafica::class);
    }
}
