<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models\Gestionale;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RicorrenzaRata extends Model
{
    use HasFactory;

    protected $table = 'ricorrenze_rate';

    protected $fillable = [
        'piano_rate_id',
        'frequency',
        'interval',
        'by_day',
        'by_month_day',
        'by_set_pos',
        'count',
        'until',
        'rrule',
        'timezone',
    ];

    protected $casts = [
        'by_day'     => 'array',
        'until'      => 'datetime',
        'by_set_pos' => 'integer',
        'count'      => 'integer',
    ];

    public function pianoRate()
    {
        return $this->belongsTo(PianoRate::class);
    }
}

