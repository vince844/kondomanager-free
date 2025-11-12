<?php

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
        'until',
        'rrule',
        'timezone',
    ];

    protected $casts = [
        'by_day' => 'array',
        'until' => 'datetime',
    ];

    public function pianoRate()
    {
        return $this->belongsTo(PianoRate::class);
    }
}

