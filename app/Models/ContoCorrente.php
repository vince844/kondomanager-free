<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContoCorrente extends Model
{
     protected $table = 'conti_correnti';

    protected $fillable = [
        'intestatario',
        'iban',
        'swift',
        'istituto',
        'indirizzo',
        'comune',
        'provincia',
        'cap',
        'nazione',
        'predefinito',
        'tipo',
        'note',
    ];

    protected $casts = [
        'predefinito' => 'boolean',
    ];

    /** Polymorphic owner */
    public function contable(): MorphTo
    {
        return $this->morphTo();
    }
}
