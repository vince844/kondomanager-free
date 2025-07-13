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
        'until',
        'type',
        'rrule'
    ];

    protected $casts = [
        'by_day' => 'array',
        'until' => 'datetime',
    ];

    public function eventi()
    {
        return $this->hasMany(Evento::class, 'recurrence_id');
    }
}
