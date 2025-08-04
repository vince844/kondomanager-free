<?php

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
