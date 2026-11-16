<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RicorrenzaEvento extends Model
{
    protected $table = 'ricorrenze_eventi';

    protected $fillable = [
        'frequency',
        'interval',
        'by_day',
        'by_month_day',
        'until',
        'type',
        'rrule',
        'timezone',
    ];

    protected $casts = [
        'by_day' => 'array',
        'until'  => 'datetime',
    ];
}
